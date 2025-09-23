<?php

namespace Anassrojea\Laracrawler\Services;

use Anassrojea\Laracrawler\Jobs\CrawlUrlJob;
use Anassrojea\Laracrawler\Jobs\FinalizeSitemapJob;
use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;
use Anassrojea\Laracrawler\Traits\Excludable;
use Illuminate\Support\Facades\Bus;

class Crawler
{
    use Excludable;

    protected Client $client;
    protected array $visited = [];
    protected array $errors = [];
    protected array $linkGraph = []; // counts how many times a URL is linked
    protected int $maxReachedDepth = 0;

    /**
     * Initializes the crawler with a Guzzle client configured according to the
     * "sitemap.http.validate_links" config section.
     *
     * The client is configured with a timeout of 10 seconds, a connection timeout of
     * 5 seconds, SSL verification disabled, and ignoring HTTP errors.
     */
    public function __construct()
    {
        $this->client = new Client(config('sitemap.http.validate_links', [
            'timeout' => 10,
            'connect_timeout' => 5,
            'verify'  => false,
            'http_errors' => false,
        ]));
    }

    /**
     * Crawls a given URL and extracts the following information:
     *  - images: `<img>` tags
     *  - videos: `<video>` tags
     *  - links: `<a>` tags
     *
     * The method will also validate the links it finds and keep track of the maximum depth
     * it reaches. The crawled information can be summarized using the `$summarize` parameter.
     *
     * @param string $url The URL to crawl
     * @param int $depth The current depth of the crawl
     * @param int $maxDepth The maximum depth to reach
     * @param bool $summarize Whether to summarize the crawled information
     * @param bool|null $validate Whether to validate the links that are found
     * @param bool|null $auditIndexability Whether to audit the indexability of the crawled URLs
     * @return array An array of crawled information
     */
    public function crawl(
        string $url,
        int $depth = 0,
        int $maxDepth = 2,
        bool $summarize = false,
        ?bool $validate = null,
        ?bool $auditIndexability = null
    ): array {
        $validate = $validate ?? config('sitemap.validate_links', false);
        $auditIndexability = $auditIndexability ?? config('sitemap.indexability_audit', false);


        // Track max depth
        if ($depth > $this->maxReachedDepth) {
            $this->maxReachedDepth = $depth;
        }

        $url = $this->cleanUrl($url);

        if (isset($this->visited[$url]) || $depth > $maxDepth || $this->isExcluded($url)) {
            return [];
        }

        $this->visited[$url] = true;
        $entries = [];

        try {
            // ✅ Don’t throw on error status
            $response = $this->client->get($url, ['http_errors' => false]);
            $status   = $response->getStatusCode();

            // ✅ HARD FAIL: status >= 400
            if ($validate && $status >= 400) {
                $this->logError($url, $status);
                return []; // 🚫 don’t include this URL
            }

            $html = (string) $response->getBody();

            // ✅ SOFT FAIL: body looks like a 404/error
            if ($validate && preg_match('/(404|not found|error)/i', $html)) {
                $title = (new DomCrawler($html))->filter('title')->text('');
                if (stripos($title, '404') !== false || stripos($title, 'error') !== false) {
                    $this->logError($url, 'soft-404');
                    return [];
                }
            }

            // --- parse DOM only if status is good ---
            $dom    = new DomCrawler($html);

            // ✅ Indexability Audit
            if ($auditIndexability) {
                // Headers
                $headers = $response->getHeaders();
                if (isset($headers['X-Robots-Tag'])) {
                    $robotsHeader = strtolower(implode(' ', $headers['X-Robots-Tag']));
                    if (str_contains($robotsHeader, 'noindex')) {
                        $this->logError($url, 'noindex-header');
                        return [];
                    }
                }
                // Meta
                $meta = $dom->filterXPath('//meta[@name="robots"]');
                if ($meta->count()) {
                    $content = strtolower($meta->attr('content'));
                    if (str_contains($content, 'noindex')) {
                        $this->logError($url, 'noindex-meta');
                        return [];
                    }
                }
            }


            $links  = $dom->filter('a')->each(fn($n) => $n->attr('href'));
            $images = $this->extractImages($dom);
            $videos = $this->extractVideos($dom);

            $entries[] = [
                'url'    => $url,
                'depth'  => $depth,
                'images' => $this->filterAssets($images, $url, 'image'),
                'videos' => $this->filterAssets($videos, $url, 'video'),
            ];

            // Crawl children
            foreach ($links as $link) {
                if (!is_string($link)) {
                    continue; // skip malformed values
                }
                $absolute = $this->normalizeUrl($link, $url);
                if ($absolute) {
                    $this->linkGraph[$absolute] = ($this->linkGraph[$absolute] ?? 0) + 1;
                    if (!$this->isExcluded($absolute) && $this->isSameDomain($absolute)) {
                        if ($this->isAssetUrl($absolute)) {
                            continue;
                        }
                        $entries = array_merge(
                            $entries,
                            $this->crawl($absolute, $depth + 1, $maxDepth, $summarize, $validate, $auditIndexability)
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            if ($validate) {
                $this->logError($url, 'connection-failed');
            }
            return [];
        }

        if ($depth === 0 && $summarize) {
            $this->summarizeExclusions();
        }

        return $entries;
    }

    /**
     * Filter assets based on sitemap configuration rules.
     *
     * @param array $assets A list of assets (images or videos) to filter
     * @param string $baseUrl The base URL to normalize asset URLs against
     * @param string $type The type of asset to filter (image or video)
     * @return array A filtered list of assets
     */
    protected function filterAssets(array $assets, string $baseUrl, string $type = 'image'): array
    {
        $whitelist = $type === 'video'
            ? config('sitemap.video_whitelist', [])
            : config('sitemap.image_whitelist', []);

        $excludes = config('sitemap.exclude_assets', []);
        $results = [];
        foreach ($assets as $asset) {
            // ✅ Support structured assets (from extractImages) or plain strings
            $src = is_array($asset) ? ($asset['src'] ?? null) : $asset;
            if (!$src) continue;

            $absolute = $this->normalizeUrl($src, $baseUrl);
            if (!$absolute) continue;

            // ✅ Apply exclusion rules
            if ($this->isExcluded($absolute)) continue;

            $skip = false;
            foreach ($excludes as $pattern) {
                if (@preg_match($pattern, $absolute)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) continue;

            // ✅ If whitelist is defined and non-empty → enforce it
            if (!empty($whitelist)) {
                $path = parse_url($absolute, PHP_URL_PATH) ?? '';
                $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

                $isAllowed = false;
                foreach ($whitelist as $rule) {
                    // Regex rule
                    if (preg_match('/^#.*#$/', $rule) && preg_match($rule, $path)) {
                        $isAllowed = true;
                        break;
                    }
                    // Extension rule: *.jpg, *.mp4 etc.
                    if (str_starts_with($rule, '*.') && $ext === strtolower(ltrim($rule, '*.'))) {
                        $isAllowed = true;
                        break;
                    }
                    // Plain substring rule
                    if (str_contains($absolute, $rule)) {
                        $isAllowed = true;
                        break;
                    }
                }
                if (!$isAllowed) continue;
            }
            // ✅ Preserve metadata for structured images
            if (is_array($asset)) {
                $asset['src'] = $absolute;
                $results[] = $asset;
            } else {
                $results[] = $absolute;
            }
        }
        // ✅ Deduplicate
        $unique = [];
        foreach ($results as $r) {
            $key = is_array($r) ? $r['src'] : $r;
            $unique[$key] = $r;
        }
        return array_values($unique);
    }

    /**
     * Normalize a URL by removing anchors, query strings and making it absolute
     * if necessary.
     *
     * @param string|null $link The URL to normalize
     * @param string $baseUrl The base URL to normalize against
     *
     * @return string|null The normalized URL or null if the URL was skipped
     */
    protected function normalizeUrl(?string $link, string $baseUrl): ?string
    {
        if (!$link) return null;

        // ✅ Skip tel:, mailto:, javascript: before doing anything
        if (preg_match('#^(tel:|mailto:|javascript:)#i', $link)) {
            return null;
        }

        // ✅ Remove anchors if enabled
        if (config('sitemap.normalize.strip_anchors', true)) {
            $link = preg_replace('/#.*$/', '', $link);
        }

        // ✅ Remove query strings if enabled
        if (config('sitemap.normalize.strip_queries', true)) {
            $link = preg_replace('/\?.*$/', '', $link);
        }

        // ✅ If already absolute (http/https)
        if (str_starts_with($link, 'http')) {
            return $this->cleanUrl($link);
        }

        // ✅ Protocol-relative (e.g., //example.com/page)
        if (str_starts_with($link, '//')) {
            return $this->cleanUrl('http:' . $link);
        }

        // ✅ Build absolute from relative
        $parsedBase = parse_url($baseUrl);
        $base = $parsedBase['scheme'] . '://' . $parsedBase['host'];

        if (isset($parsedBase['port'])) {
            $base .= ':' . $parsedBase['port'];
        }

        $url = rtrim($base, '/') . '/' . ltrim($link, '/');

        return $this->cleanUrl($url);
    }

    /**
     * Normalize a URL by cleaning it up and optionally enforcing rules.
     *
     * - Removes duplicate slashes but keeps "http://" intact
     * - Canonicalizes to lowercase if enabled
     * - Enforces https if enabled
     * - Enforces or strips www if enabled
     * - Normalizes trailing slashes if enabled
     *
     * @param string $url The URL to clean
     *
     * @return string The cleaned URL
     */
    protected function cleanUrl(string $url): string
    {
        // Remove duplicate slashes but keep "http://" intact
        $url = preg_replace('#(?<!:)//+#', '/', $url);

        $parts = parse_url($url);

        if ($parts !== false) {
            $scheme = strtolower($parts['scheme'] ?? 'http');
            $host   = $parts['host'] ?? '';
            $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
            $path   = $parts['path'] ?? '';
            $query  = isset($parts['query']) && !config('sitemap.normalize.strip_queries', true)
                ? '?' . $parts['query']
                : '';

            // ✅ Canonicalize to lowercase
            if (config('sitemap.normalize.canonicalize', true)) {
                $host  = strtolower($host);
                $path  = strtolower($path);
                $query = strtolower($query);
            }

            // ✅ Enforce https
            if (config('sitemap.normalize.enforce_https', true)) {
                $scheme = 'https';
            }

            // ✅ Enforce or strip www
            $wwwRule = config('sitemap.normalize.enforce_www', null);
            if ($wwwRule === true && !str_starts_with($host, 'www.')) {
                $host = 'www.' . $host;
            } elseif ($wwwRule === false && str_starts_with($host, 'www.')) {
                $host = substr($host, 4);
            }

            // ✅ Trailing slash normalization
            if ($path === '' || $path === '/') {
                $path = '/'; // 🔥 Always normalize root to "/"
            } elseif (config('sitemap.normalize.force_trailing_slash', false)) {
                if (!str_ends_with($path, '/')) {
                    $path .= '/';
                }
            } elseif (config('sitemap.normalize.strip_trailing_slash', true)) {
                $path = rtrim($path, '/');
                if ($path === '') {
                    $path = '/'; // 🔥 Fallback: still ensure root is "/"
                }
            }

            // ✅ Rebuild URL
            $url = "{$scheme}://{$host}{$port}{$path}{$query}";

            // ✅ Final safeguard: collapse root duplicates
            if ($url === "{$scheme}://{$host}{$port}" || $url === "{$scheme}://{$host}{$port}/") {
                $url = "{$scheme}://{$host}{$port}/";
            }
        }

        return $url;
    }

    /**
     * Check if a given URL is from the same domain as the base URL.
     *
     * @param string $url The URL to check
     *
     * @return bool True if the URL is from the same domain, false otherwise
     */
    protected function isSameDomain(string $url): bool
    {
        $baseHost = parse_url(config('sitemap.base_url'), PHP_URL_HOST);
        $urlHost  = parse_url($url, PHP_URL_HOST);

        return $baseHost === $urlHost;
    }

    /**
     * Start crawling the site with the given URL and maximum depth.
     *
     * This method will queue the crawl job with the given URL and maximum depth.
     * It will then wait for the crawl job to finish and queue the finalize job.
     *
     * @param string $startUrl The URL to start crawling from
     * @param int $maxDepth The maximum depth to crawl
     */
    public function crawlQueued(string $startUrl, int $maxDepth): void
    {
        $jobs = [
            new CrawlUrlJob($startUrl, 0, $maxDepth),
        ];

        Bus::batch($jobs)
            ->then(function () {
                // ✅ Queue the finalize job instead of calling Artisan inline
                FinalizeSitemapJob::dispatch('public')
                    ->onConnection(config('sitemap.queue.connection', 'database'));
            })
            ->onConnection(config('sitemap.queue.connection', 'database'))
            ->name('LaraCrawler Crawl Batch')
            ->dispatch();
    }

    /**
     * Get the maximum depth reached during crawling.
     *
     * @return int The maximum depth reached.
     */
    public function getMaxReachedDepth(): int
    {
        return $this->maxReachedDepth;
    }

    /**
     * Extracts all images from a given HTML document.
     *
     * Extracts `<img>` tags and `<picture><source>` tags, and returns an array of
     * images with the following structure:
     *
     * [
     *     'src' => string,
     *     'title' => string,
     *     'caption' => string,
     * ]
     *
     * @param DomCrawler $dom The HTML document to extract images from
     * @return array An array of images
     */
    protected function extractImages(DomCrawler $dom): array
    {
        $images = [];

        // <img src="...">
        $images = $dom->filter('img')->each(function ($node) {
            return [
                'src'     => $node->attr('src'),
                'title'   => $node->attr('title')
                    ?? config('sitemap.image_defaults.title', 'Image Title'),
                'caption' => $node->attr('alt')
                    ?? config('sitemap.image_defaults.description', 'Image Caption'),
            ];
        });

        // <picture><source srcset="...">
        $srcsets = $dom->filter('picture source')->each(function ($node) {
            $srcset = $node->attr('srcset');
            if (!$srcset) return [];
            return array_map(
                fn($s) => fn($s) => [
                    'src'   => trim(preg_replace('/\s+\d+[wx]$/', '', $s)),
                    'title'   => $node->attr('title')
                        ?? config('sitemap.image_defaults.title', 'Image Title'),
                    'caption' => $node->attr('alt')
                        ?? config('sitemap.image_defaults.description', 'Image Caption'),
                ],
                explode(',', $srcset)
            );
        });

        foreach ($srcsets as $set) {
            if (is_array($set)) {
                $images = array_merge($images, $set);
            } elseif ($set) {
                $images[] = $set;
            }
        }

        // <a href="...jpg|png|webp">
        $images = array_merge(
            $images,
            $dom->filter('a')->each(function ($node) {
                $href = $node->attr('href');
                if ($href && preg_match('#\.(jpg|jpeg|png|gif|webp|svg)$#i', $href)) {
                    return [
                        'src' => $href,
                        'title'   => $node->attr('title')
                            ?? config('sitemap.image_defaults.title', 'Image Title'),
                        'caption' => $node->attr('alt')
                            ?? config('sitemap.image_defaults.description', 'Image Caption'),
                    ];
                }
                return null;
            })
        );

        // ✅ Ensure uniqueness by src
        $unique = [];
        foreach ($images as $img) {
            if (!empty($img['src']) && !isset($unique[$img['src']])) {
                $unique[$img['src']] = $img;
            }
        }

        return array_values($unique);
    }

    /**
     * Extract all video tags from the given HTML.
     *
     * This method will extract:
     *  - <video> tags
     *  - <video><source> tags
     *  - <iframe> tags (YouTube, Vimeo, etc.)
     *
     * It will also deduplicate the results by src.
     *
     * @param DomCrawler $dom The HTML to extract videos from.
     * @return array An array of videos extracted from the HTML.
     */
    protected function extractVideos(DomCrawler $dom): array
    {
        $videos = [];

        // <video src="...">
        $videos = array_merge($videos, $dom->filter('video')->each(
            function ($node) {
                return [
                    'src'        => $node->attr('src'),
                    'title'      => $node->attr('title') ?? config('sitemap.video_defaults.title', 'Video Title'),
                    'description' => $node->attr('aria-label') ?? config('sitemap.video_defaults.description', 'Video Description'),
                    'duration'   => $node->attr('duration') ?? null,
                    'published'  => $node->attr('data-published') ?? null,
                ];
            }
        ));

        // <video><source src="...">
        $videoSources = $dom->filter('video source')->each(function ($node) {
            return [
                'src'        => $node->attr('src'),
                'title'      => $node->attr('title') ?? config('sitemap.video_defaults.title', 'Video Title'),
                'description' => $node->attr('aria-label') ?? config('sitemap.video_defaults.description', 'Video Description'),
                'duration'   => $node->attr('duration') ?? null,
                'published'  => $node->attr('data-published') ?? null,
            ];
        });
        $videos = array_merge($videos, $videoSources);

        // <iframe src="..."> (YouTube, Vimeo, etc.)
        $iframes = $dom->filter('iframe')->each(function ($node) {
            return [
                'src'        => $node->attr('src'),
                'title'      => $node->attr('title') ?? config('sitemap.video_defaults.title', 'Video Title'),
                'description' => $node->attr('aria-label') ?? config('sitemap.video_defaults.description', 'Video Description'),
                'duration'   => null,
                'published'  => null,
            ];
        });
        $videos = array_merge($videos, $iframes);

        // ✅ Deduplicate
        $unique = [];
        foreach ($videos as $vid) {
            if (!empty($vid['src']) && !isset($unique[$vid['src']])) {
                $unique[$vid['src']] = $vid;
            }
        }
        return array_values($unique);
    }

    /**
     * Check if a given URL is an asset URL (image/video).
     *
     * This method will check if the given URL has an extension that is
     * commonly used for images or videos.
     *
     * @param string $url The URL to check.
     * @return bool True if the URL is an asset URL, false otherwise.
     */
    protected function isAssetUrl(string $url): bool
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        $assetExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'mp4', 'webm', 'avi', 'mov'];
        return in_array($ext, $assetExts);
    }

    /**
     * Returns the link graph of the crawled URLs.
     *
     * The link graph is a key-value array where the key is the URL and the value is the number of links pointing to that URL.
     *
     * @return array The link graph of the crawled URLs.
     */
    public function getLinkGraph(): array
    {
        return $this->linkGraph;
    }

    /**
     * Logs an error with a given URL and status code.
     *
     * Only logs up to `sitemap.max_errors` (5000 by default) errors.
     *
     * @param string $url The URL that caused the error.
     * @param int $status The HTTP status code of the error.
     */

    public function logError($url, $status): void
    {
        if (count($this->errors) < config('sitemap.max_errors', 5000)) {
            $this->errors[] = [
                'url'    => $url,
                'status' => $status,
                'time'   => now()->toAtomString(),
            ];
        }
    }

    /**
     * Returns an array of errors that were encountered during the crawl.
     *
     * Each error is an associative array containing the following keys:
     * - `url`: The URL that caused the error.
     * - `status`: The HTTP status code of the error.
     * - `time`: The timestamp of when the error occurred, in Atom format.
     *
     * @return array An array of errors that were encountered during the crawl.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
