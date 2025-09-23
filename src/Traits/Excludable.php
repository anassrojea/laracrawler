<?php

namespace Anassrojea\Laracrawler\Traits;

trait Excludable
{
    protected array $excludedUrls = []; // collect excluded URLs during crawl

    /**
     * Checks if a given URL is excluded from the crawl.
     *
     * The exclusion rules are as follows:
     * - Non-HTTP(S) schemes are excluded (e.g. tel:, mailto:, javascript:).
     * - URLs matching a regex pattern starting with '#.*$' are excluded.
     * - URLs having an extension matching a string starting with '*' are excluded.
     * - URLs containing a plain string are excluded.
     *
     * @param string $url The URL to check for exclusion.
     * @return bool True if the URL is excluded, false otherwise.
     */
    protected function isExcluded(string $url): bool
    {
        // ✅ First handle non-HTTP(S) schemes
        if (preg_match('#^(tel:|mailto:|javascript:)#i', $url)) {
            $this->debugExclusion($url, "protocol filter (tel/mailto/javascript)");
            return true;
        }

        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        foreach (config('sitemap.exclude_urls', []) as $excluded) {
            if (!$excluded) {
                continue;
            }

            // ✅ Regex pattern
            if (preg_match('/^#.*#$/', $excluded)) {
                if (@preg_match($excluded, $path)) {
                    $this->debugExclusion($url, "regex {$excluded}");
                    return true;
                }
            }
            // ✅ Extension match
            elseif (str_starts_with($excluded, '*')) {
                $blockedExt = strtolower(ltrim($excluded, '*.'));
                if ($ext === $blockedExt) {
                    $this->debugExclusion($url, "extension {$blockedExt}");
                    return true;
                }
            }
            // ✅ Plain string match
            else {
                if (str_starts_with($path, $excluded) || str_contains($path, $excluded)) {
                    $this->debugExclusion($url, "string {$excluded}");
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Logs excluded URLs during the crawl.
     *
     * The method logs excluded URLs in both the console and the log file.
     * The log message contains the URL and the rule which caused the exclusion.
     *
     * @param string $url The URL that was excluded from the crawl.
     * @param string $rule The rule which caused the URL to be excluded.
     */
    protected function debugExclusion(string $url, string $rule): void
    {
        $this->excludedUrls[] = ['url' => $url, 'rule' => $rule];

        if (app()->runningInConsole() && app('request')->server->get('argv')) {
            $args = implode(' ', app('request')->server->get('argv'));
            if (str_contains($args, '--debug')) {
                echo "🔎 Excluded: {$url} (matched {$rule})\n";
            }
        }

        \Log::info("LaraCrawler: URL excluded", [
            'url'  => $url,
            'rule' => $rule,
        ]);
    }

    /**
     * Summarizes the excluded URLs during the crawl.
     *
     * The method logs the excluded URLs to the console and log file.
     * The log message contains the count of excluded URLs and the individual URLs
     * with the rule which caused the exclusion.
     *
     * @return void
     */
    protected function summarizeExclusions(): void
    {
        if (empty($this->excludedUrls)) {
            return;
        }

        $count = count($this->excludedUrls);
        echo "\n⚠️  {$count} URLs excluded during crawl:\n";

        foreach ($this->excludedUrls as $excluded) {
            echo "   - {$excluded['url']} (matched {$excluded['rule']})\n";
        }
    }
}
