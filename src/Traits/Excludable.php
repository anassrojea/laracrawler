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
                if ($this->safePreg($excluded, $path)) {
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
    /**
     * Safe wrapper around preg_match that throws on invalid patterns (SEC-03).
     *
     * PHP emits E_WARNING (not an exception) when a regex pattern is malformed.
     * The @ suppression operator silences the warning and returns false, causing
     * URLs to silently pass exclusion checks when a developer provides a bad regex.
     * This helper converts the E_WARNING into an \InvalidArgumentException so the
     * misconfiguration surfaces immediately at first use.
     *
     * Only use this for user-supplied patterns from config. Hardcoded literal
     * patterns (e.g. '#^(tel:|mailto:|javascript:)#i') use plain preg_match.
     *
     * @param string $pattern User-supplied regex pattern from config.
     * @param string $subject The string to match against.
     * @return bool True if pattern matches, false otherwise.
     * @throws \InvalidArgumentException If the pattern is invalid.
     */
    protected function safePreg(string $pattern, string $subject): bool
    {
        $error = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$error): bool {
            $error = $errstr;
            return true;
        });
        $result = preg_match($pattern, $subject);
        restore_error_handler();

        if ($error !== null) {
            throw new \InvalidArgumentException(
                "LaraCrawler: Invalid regex pattern in config — {$error} (pattern: {$pattern})"
            );
        }

        return (bool) $result;
    }

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
