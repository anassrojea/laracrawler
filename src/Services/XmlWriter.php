<?php

namespace Anassrojea\Laracrawler\Services;

use SimpleXMLElement;
use Anassrojea\Laracrawler\Traits\Excludable;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;

class XmlWriter
{
    use Excludable;

    /**
     * Write sitemap files to the given output directory.
     *
     * @param array $urls Array of URLs to include in the sitemap
     * @param string $outputDir Output directory for the sitemap files
     *
     * @throws \Exception If a sitemap file exceeds 50MB limit
     */
    public function write(array $urls, string $outputDir): void
    {
        $maxUrls  = config('sitemap.max_urls_per_sitemap', 50000);
        $maxSize  = config('sitemap.max_file_size', 52428800); // 50MB
        $useIndex = config('sitemap.use_index', true);

        $chunks = array_chunk($urls, $maxUrls);
        $sitemaps = [];

        foreach ($chunks as $i => $chunk) {
            $filename = "sitemap-" . ($i + 1) . ".xml"; // ✅ use dash
            $path = rtrim($outputDir, '/') . '/' . $filename;

            $xml = $this->buildSitemap($chunk);
            $xml->asXML($path);

            // ✅ File size check
            if (filesize($path) > $maxSize) {
                throw new \Exception("Sitemap {$filename} exceeds 50MB limit.");
            }

            $sitemaps[] = [
                'loc'     => rtrim(config('sitemap.base_url'), '/') . '/' . $filename,
                'lastmod' => now()->toAtomString(),
            ];
        }

        // ✅ Always create index in split mode, even if only one file
        if ($useIndex || count($sitemaps) > 1) {
            $this->buildSitemapIndex($sitemaps, rtrim($outputDir, '/') . '/sitemap-index.xml');
        } else {
            // ✅ only rename if the file actually exists
            $src = rtrim($outputDir, '/') . '/sitemap-1.xml';
            $dest = rtrim($outputDir, '/') . '/sitemap.xml';

            if (file_exists($src)) {
                rename($src, $dest);
            } else {
                \Log::warning("LaraCrawler: Expected {$src} but file not found, sitemap.xml not created.");
            }
        }
    }

    /**
     * Builds a sitemap XML document from an array of URLs.
     *
     * Includes images and videos if configured in the sitemap config.
     *
     * @param array $urls Array of URLs to include in the sitemap
     * @return \SimpleXMLElement The sitemap XML document
     */
    protected function buildSitemap(array $urls): \SimpleXMLElement
    {
        $namespaces = [
            'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"',
        ];

        if (config('sitemap.include.images', true)) {
            $namespaces[] = 'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"';
        }

        if (config('sitemap.include.videos', true)) {
            $namespaces[] = 'xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"';
        }

        if (!empty(config('sitemap.alternates', [])) && config('sitemap.include.languages', true)) {
            $namespaces[] = 'xmlns:xhtml="http://www.w3.org/1999/xhtml"';
        }

        $xml = new \SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?>' .
                '<urlset ' . implode(' ', $namespaces) . '/>'
        );

        $linkGraph = app(\Anassrojea\Laracrawler\Services\Crawler::class)->getLinkGraph();

        foreach ($urls as $entry) {
            if ($this->isExcluded($entry['url'])) {
                continue;
            }

            $url = $xml->addChild('url');
            $url->addChild('loc', $entry['url']);

            // ✅ Pass depth from crawl entry (default 0 if not set)
            $linkCount = $linkGraph[$entry['url']] ?? 1;
            $seoRules = $this->getSeoRules($entry['url'], $entry['depth'] ?? 0, $linkCount,);
            $lastmod  = $this->resolveLastmod($seoRules['lastmod'], $entry);

            $url->addChild('lastmod', $lastmod);
            $url->addChild('changefreq', $seoRules['changefreq']);
            $url->addChild('priority', $seoRules['priority']);

            // hreflang alternates
            if (!empty(config('sitemap.alternates', [])) && config('sitemap.include.languages', true)) {
                $this->addAlternateLinks($url, $entry['url']);
            }

            $includeRules = $this->getIncludeRules($entry['url']);

            // ✅ Images
            if ($includeRules['images']) {
                foreach ($entry['images'] as $img) {
                    if ($this->isExcluded($img['src'])) continue;
                    $image = $url->addChild('image:image', null, 'http://www.google.com/schemas/sitemap-image/1.1');
                    $image->addChild('image:loc', $img['src'], 'http://www.google.com/schemas/sitemap-image/1.1');
                    $image->addChild('image:title', htmlspecialchars($img['title']), 'http://www.google.com/schemas/sitemap-image/1.1');
                    $image->addChild('image:caption', htmlspecialchars($img['caption']), 'http://www.google.com/schemas/sitemap-image/1.1');
                }
            }

            // ✅ Videos
            if ($includeRules['videos']) {
                foreach ($entry['videos'] as $vid) {
                    if ($this->isExcluded($vid['src'])) continue;
                    $video = $url->addChild('video:video', null, 'http://www.google.com/schemas/sitemap-video/1.1');
                    $video->addChild('video:thumbnail_loc', $vid['src'], 'http://www.google.com/schemas/sitemap-video/1.1');
                    $video->addChild('video:title', htmlspecialchars($vid['title']), 'http://www.google.com/schemas/sitemap-video/1.1');
                    $video->addChild('video:description', htmlspecialchars($vid['description']), 'http://www.google.com/schemas/sitemap-video/1.1');
                    $video->addChild('video:content_loc', $vid['src'], 'http://www.google.com/schemas/sitemap-video/1.1');
                    if (!empty($vid['duration'])) {
                        $video->addChild('video:duration', intval($vid['duration']), 'http://www.google.com/schemas/sitemap-video/1.1');
                    }
                    if (!empty($vid['published'])) {
                        $video->addChild('video:publication_date', \Carbon\Carbon::parse($vid['published'])->toAtomString(), 'http://www.google.com/schemas/sitemap-video/1.1');
                    }
                }
            }
        }

        return $xml;
    }

    /**
     * Builds a sitemap index.
     *
     * @param array $sitemaps An array of sitemaps, each containing a 'loc' and a 'lastmod' key.
     * @param string $outputPath The path to write the sitemap index to.
     */
    protected function buildSitemapIndex(array $sitemaps, string $outputPath): void
    {
        $xml = new SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?>' .
                '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"/>'
        );

        foreach ($sitemaps as $map) {
            $sitemap = $xml->addChild('sitemap');
            $sitemap->addChild('loc', $map['loc']);
            $sitemap->addChild('lastmod', $map['lastmod']);
        }

        $xml->asXML($outputPath);
    }

    /**
     * Adds alternate links to a given URL element.
     *
     * Supports three modes for generating alternate links:
     * - path: Replaces the first segment of the path with the alternate language.
     * - subdomain: Uses a subdomain for the alternate language.
     * - query: Appends a query parameter to the URL for the alternate language.
     *
     * Optionally validates the generated alternate links by making a HEAD request.
     *
     * @param \SimpleXMLElement $urlElement The URL element to add the alternate links to.
     * @param string $currentUrl The current URL to generate the alternate links for.
     */
    protected function addAlternateLinks(\SimpleXMLElement $urlElement, string $currentUrl): void
    {
        $alternates  = config('sitemap.alternates', []);
        $langMode    = config('sitemap.lang_mode', 'path');
        $validate    = config('sitemap.validate_alternates', false);

        if (empty($alternates)) {
            return;
        }

        $parsed = parse_url($currentUrl);
        $path   = $parsed['path'] ?? '';
        $host   = $parsed['host'] ?? '';
        $query  = $parsed['query'] ?? '';

        $altHrefs = [];

        // ✅ Build alternate hrefs
        foreach ($alternates as $lang => $altBase) {
            switch ($langMode) {
                case 'path':
                    $segments = explode('/', trim($path, '/'));
                    if (!empty($segments) && array_key_exists($segments[0], $alternates)) {
                        array_shift($segments);
                    }
                    $normalizedPath = '/' . implode('/', $segments);
                    $altHref = rtrim($altBase, '/') . $normalizedPath;
                    break;

                case 'subdomain':
                    $scheme = $parsed['scheme'] ?? 'https';
                    $domain = implode('.', array_slice(explode('.', $host), -2)); // example.com
                    $altHref = "{$scheme}://{$lang}.{$domain}{$path}";
                    break;

                case 'query':
                    $base = rtrim($altBase, '/') . $path;
                    $altHref = $base . '?lang=' . $lang;
                    break;

                default:
                    $altHref = $currentUrl;
            }

            $altHrefs[$lang] = $altHref;
        }

        // ✅ Validate alternates in parallel
        $validation = $validate
            ? $this->checkUrlWorks(array_values($altHrefs))
            : [];

        // ✅ Add only valid alternates
        foreach ($altHrefs as $lang => $altHref) {
            if ($validate && (empty($validation[$altHref]) || $validation[$altHref] === false)) {
                continue; // skip broken
            }

            $link = $urlElement->addChild('xhtml:link', null, 'http://www.w3.org/1999/xhtml');
            $link->addAttribute('rel', 'alternate');
            $link->addAttribute('hreflang', $lang);
            $link->addAttribute('href', $altHref);
        }

        // ✅ x-default fallback
        if ($default = config('sitemap.xdefault')) {
            $link = $urlElement->addChild('xhtml:link', null, 'http://www.w3.org/1999/xhtml');
            $link->addAttribute('rel', 'alternate');
            $link->addAttribute('hreflang', 'x-default');
            $link->addAttribute('href', $default);
        }
    }

    /**
     * Validates an array of URLs in parallel to check if they work.
     *
     * Will return an array with the URL as the key and a boolean indicating
     * whether the URL works or not.
     *
     * @param array $urls An array of URLs to validate
     * @return array An array with the URL as the key and a boolean indicating
     * whether the URL works or not
     */
    protected function checkUrlWorks(array $urls): array
    {
        $client = new Client(config('sitemap.http.validate_alternates', [
            'timeout' => 10,
            'connect_timeout' => 5,
            'verify'  => false,
            'http_errors' => false,
        ]));

        $results = [];
        $requests = function ($urls) {
            foreach ($urls as $url) {
                yield $url => new Request('HEAD', $url);
            }
        };

        $pool = new Pool($client, $requests($urls), [
            'concurrency' => 10, // how many requests at once
            'fulfilled' => function ($response, $url) use (&$results) {
                $status = $response->getStatusCode();
                $results[$url] = ($status >= 200 && $status < 400);
            },
            'rejected' => function (\Throwable $e, $url) use (&$results) {
                $results[$url] = false;
            },
        ]);

        // Wait for all requests to complete
        $pool->promise()->wait();

        return $results; // array: [url => true/false]
    }

    /**
     * Ping search engines with a sitemap URL.
     *
     * Only works if `sitemap.ping` is set to `true` in the config.
     *
     * The following search engines are supported:
     * - Google
     * - Bing
     * - Yandex
     * - Baidu
     *
     * @param string $sitemapUrl The URL of the sitemap to ping.
     *
     * @return void
     */
    public function pingSearchEngines(string $sitemapUrl): void
    {
        if (!config('sitemap.ping', false)) {
            return;
        }

        $engines = config('sitemap.ping_targets', []);

        foreach ($engines as $name => $endpoint) {
            $url = $endpoint . urlencode($sitemapUrl);

            try {
                file_get_contents($url);

                // ✅ Console + Log
                echo "✅ Pinged {$name}: {$url}\n";
                \Log::info("LaraCrawler: Successfully pinged {$name}", [
                    'url' => $sitemapUrl,
                    'endpoint' => $url,
                ]);
            } catch (\Exception $e) {
                // ⚠️ Console + Log
                echo "⚠️ Failed to ping {$name}: {$e->getMessage()}\n";
                \Log::error("LaraCrawler: Failed to ping {$name}", [
                    'url' => $sitemapUrl,
                    'endpoint' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Get the inclusion rules for a given URL.
     *
     * This method will return an associative array containing the inclusion rules for the given URL.
     * The rules are based on the configuration in `config/sitemap.php` and are applied in the following order:
     * 1. Global defaults
     * 2. Regex patterns (if the pattern starts with `#` and ends with `$`)
     * 3. Plain string prefixes (if the pattern does not start with `#` or end with `$`)
     *
     * The returned array will contain the following keys:
     * - `images`: whether to include images in the sitemap
     * - `videos`: whether to include videos in the sitemap
     *
     * @param string $url the URL to get the inclusion rules for
     * @return array the inclusion rules for the given URL
     */
    protected function getIncludeRules(string $url): array
    {
        $rules = config('sitemap.include.rules', []);
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        // Start with global defaults
        $include = [
            'images' => config('sitemap.include.images', true),
            'videos' => config('sitemap.include.videos', true),
        ];

        foreach ($rules as $pattern => $rule) {
            // ✅ Regex pattern
            if (preg_match('/^#.*#$/', $pattern)) {
                if (@preg_match($pattern, $path)) {
                    $include = array_merge($include, $rule);
                }
            }
            // ✅ Plain string prefix
            else {
                if (str_starts_with($path, $pattern)) {
                    $include = array_merge($include, $rule);
                }
            }
        }

        // dd($include, $url);
        return $include;
    }

    /**
     * Get the SEO rules for a given URL.
     *
     * This method will return an associative array containing the SEO rules for the given URL.
     * The rules are based on the configuration in `config/sitemap.php` and are applied in the following order:
     * 1. Global defaults
     * 2. Regex patterns (if the pattern starts with `#` and ends with `$`)
     * 3. Plain string prefixes (if the pattern does not start with `#` or end with `$`)
     *
     * The returned array will contain the following keys:
     * - `changefreq`: the change frequency of the URL
     * - `priority`: the priority of the URL (null means: auto-score if enabled)
     * - `lastmod`: the last modification date of the URL
     * - `priority_boost`: the boost to apply to the priority (only if auto-priority scoring is enabled)
     *
     * @param string $url the URL to get the SEO rules for
     * @param int $depth the depth of the URL (optional, defaults to 0)
     * @param int $linkCount the number of links pointing to the URL (optional, defaults to 1)
     * @return array the SEO rules for the given URL
     */
    protected function getSeoRules(string $url, int $depth = 0, int $linkCount = 1): array
    {
        $rules = config('sitemap.rules', []);
        $path  = parse_url($url, PHP_URL_PATH) ?? '';

        // Start with global defaults
        $seo = [
            'changefreq' => config('sitemap.defaults.changefreq', 'weekly'),
            'priority'   => null, // null means: auto-score if enabled
            'lastmod'    => config('sitemap.defaults.lastmod', 'now'),
            'priority_boost' => 0.0, // ✅ new field
        ];

        foreach ($rules as $pattern => $rule) {
            if (preg_match('/^#.*#$/', $pattern)) {
                if (@preg_match($pattern, $path)) {
                    $seo = array_merge($seo, $rule);
                }
            } else {
                if (str_starts_with($path, $pattern)) {
                    $seo = array_merge($seo, $rule);
                }
            }
        }

        // ✅ Auto-priority scoring
        if (
            is_null($seo['priority']) &&
            config('sitemap.priority_scoring.enabled', false)
        ) {
            $seo['priority'] = $this->autoScorePriority($url, $depth, $linkCount);
            // Apply boost (but clamp later)
            if (!empty($seo['priority_boost'])) {
                $seo['priority'] += $seo['priority_boost'];
            }
        }

        // ✅ Fallback if still null
        if (is_null($seo['priority'])) {
            $seo['priority'] = config('sitemap.defaults.priority', '0.8');
        }

        // ✅ Clamp to safe bounds
        $seo['priority'] = max(
            config('sitemap.priority_scoring.min', 0.1),
            min(config('sitemap.priority_scoring.max', 1.0), $seo['priority'])
        );

        return $seo;
    }

    /**
     * Calculate the priority of a URL based on its depth, link count and freshness.
     * The priority is calculated by weighting the depth, link count and freshness scores.
     * The weights are configurable in `config/sitemap.php` under the `priority_scoring` key.
     * The weighted scores are then combined to produce a final priority score between 0.1 and 1.0.
     *
     * @param string $url the URL to calculate the priority for
     * @param int $depth the depth of the URL
     * @param int $linkCount the number of links pointing to the URL
     * @return float the calculated priority score
     */
    protected function autoScorePriority(string $url, int $depth, int $linkCount = 1): float
    {
        $weights = config('sitemap.priority_scoring.weights', [
            'depth'     => 0.4,
            'links'     => 0.4,
            'freshness' => 0.2,
        ]);

        // --- Depth score
        $depthScore = match (true) {
            $depth === 0 => 1.0,
            $depth === 1 => 0.8,
            $depth === 2 => 0.6,
            $depth === 3 => 0.4,
            default      => 0.2,
        };

        // --- Link score (normalize against max links)
        $linkScore = min(1.0, log10($linkCount + 1) / 2);

        // --- Freshness score
        $freshnessScore = 0.6;
        try {
            $lastmod = $this->resolveLastmod(config('sitemap.defaults.lastmod', 'now'), ['url' => $url]);
            $days = now()->diffInDays(\Carbon\Carbon::parse($lastmod));
            $freshnessScore = match (true) {
                $days <= 7      => 1.0,
                $days <= 30     => 0.8,
                $days <= 180    => 0.6,
                $days <= 365    => 0.4,
                default         => 0.2,
            };
        } catch (\Exception $e) {
            // ignore
        }


        // Weighted priority
        $priority = (
            ($depthScore * ($weights['depth'] ?? 0.4)) +
            ($linkScore * ($weights['links'] ?? 0.4)) +
            ($freshnessScore * ($weights['freshness'] ?? 0.2))
        );

        return round($priority, 2);
    }

    /**
     * Resolve the last modification date of a URL based on the given strategy.
     *
     * Supported strategies are:
     * - 'now': returns the current timestamp
     * - 'file': returns the last modification date of the file at the given URL
     * - 'db': returns the last modification date of the row with the given slug in the given table
     * - 'callback': calls the given callback with the given URL and/or entry as parameter
     *
     * If the strategy is invalid or the lookup fails, the current timestamp is returned.
     *
     * @param string|array $strategy the strategy to use for resolving the last modification date
     * @param array $entry the entry containing the URL and other relevant information
     * @return string the resolved last modification date in Atom format (YYYY-MM-DDTHH:MM:SSZ)
     */
    protected function resolveLastmod($strategy, array $entry): string
    {
        $url = $entry['url'];

        // ✅ Simple string strategies
        if (is_string($strategy)) {
            switch ($strategy) {
                case 'now':
                    return now()->toAtomString();

                case 'file':
                    $path = public_path(parse_url($url, PHP_URL_PATH));
                    if (file_exists($path)) {
                        return date(DATE_ATOM, filemtime($path));
                    }
                    return now()->toAtomString();

                default:
                    return now()->toAtomString();
            }
        }

        // ✅ Array strategies
        if (is_array($strategy)) {
            switch ($strategy['strategy'] ?? null) {
                case 'db':
                    $table  = $strategy['table'] ?? null;
                    $lookup = $strategy['lookup'] ?? 'slug';
                    $column = $strategy['column'] ?? 'updated_at';

                    if (!$table) {
                        \Log::warning("LaraCrawler: DB strategy missing table", ['url' => $url]);
                        return now()->toAtomString();
                    }

                    $slug = $this->extractSlugFromUrl($url);

                    if (!$slug) {
                        return now()->toAtomString(); // fallback for root or broken URL
                    }

                    try {
                        $updatedAt = \DB::table($table)
                            ->where($lookup, $slug)
                            ->value($column);

                        if ($updatedAt) {
                            return \Carbon\Carbon::parse($updatedAt)->toAtomString();
                        }
                    } catch (\Exception $e) {
                        \Log::warning("LaraCrawler: DB lastmod lookup failed", [
                            'url'    => $url,
                            'table'  => $table,
                            'lookup' => $lookup,
                            'column' => $column,
                            'error'  => $e->getMessage(),
                        ]);
                    }

                    return now()->toAtomString();

                case 'callback':
                    $callback = $strategy['callback'] ?? null;

                    if (is_callable($callback)) {
                        try {
                            $ref = new \ReflectionFunction(\Closure::fromCallable($callback));
                            $paramCount = $ref->getNumberOfParameters();

                            $result = $paramCount > 1
                                ? call_user_func($callback, $entry)
                                : call_user_func($callback, $url);

                            if ($result) {
                                return \Carbon\Carbon::parse($result)->toAtomString();
                            }
                        } catch (\Exception $e) {
                            \Log::error("LaraCrawler: Callback lastmod failed", [
                                'url'      => $url,
                                'callback' => $callback,
                                'error'    => $e->getMessage(),
                            ]);
                        }
                    } else {
                        \Log::warning("LaraCrawler: Invalid callback provided", [
                            'url' => $url,
                            'callback' => $callback,
                        ]);
                    }

                    return now()->toAtomString();
            }
        }

        return now()->toAtomString();
    }

    /**
     * Extract the slug from the given URL.
     *
     * The slug is determined by the last segment of the URL's path.
     * If the URL has no path (i.e. homepage), null is returned.
     *
     * @param string $url the URL to extract the slug from
     * @return string|null the extracted slug or null if the URL has no path
     */
    protected function extractSlugFromUrl(string $url): ?string
    {
        $path = trim(parse_url($url, PHP_URL_PATH) ?? '', '/');

        if ($path === '') {
            return null; // homepage has no slug
        }

        $segments = explode('/', $path);

        return end($segments); // always last segment
    }

    /**
     * Write the provided errors to an XML file in the given output directory.
     *
     * The errors will be written to a file named `sitemap-errors.xml` in the provided output directory.
     *
     * If the number of errors exceeds the configured maximum (default 5000), only the first 5000 errors will be written.
     *
     * @param array $errors the errors to write to the XML file
     * @param string $outputDir the output directory to write the errors to
     */
    public function writeErrors(array $errors, string $outputDir): void
    {
        if (count($errors) > config('sitemap.max_errors', 5000)) {
            $errors = array_slice($errors, 0, config('sitemap.max_errors', 5000));
        }

        if (empty($errors)) {
            return;
        }

        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><errors></errors>');

        foreach ($errors as $err) {
            $error = $xml->addChild('error');
            $error->addChild('loc', $err['url']);
            $error->addChild('status', $err['status']);
            $error->addChild('checked_at', $err['time']);
        }

        $path = rtrim($outputDir, '/') . '/sitemap-errors.xml';
        $xml->asXML($path);
    }
}
