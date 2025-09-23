<?php

namespace Anassrojea\Laracrawler\Traits;

trait Pingable
{
    /**
     * Checks if pinging search engines is allowed.
     *
     * @return bool true if pinging is allowed, false otherwise
     */
    protected function shouldPing(): bool
    {
        return config('sitemap.ping', false) && !$this->option('no-ping');
    }

    /**
     * Pings search engines with the sitemap URL.
     *
     * @param \Anassrojea\Laracrawler\Writer $writer
     * @param string $sitemapUrl
     * @param string $successMsg [optional] The message to log if pinging is successful. Defaults to '✅ Sitemaps finalized and search engines notified.'.
     * @param string $skipMsg [optional] The message to log if pinging is skipped. Defaults to '✅ Sitemaps finalized (ping skipped).'.
     *
     * @return void
     *
     * @throws \Throwable
     */
    protected function handlePing($writer, string $sitemapUrl, string $successMsg = '✅ Sitemaps finalized and search engines notified.', string $skipMsg = '✅ Sitemaps finalized (ping skipped).')
    {
        try {
            if (!$this->option('no-ping') && config('sitemap.ping', false)) {
                $writer->pingSearchEngines($sitemapUrl);

                if (method_exists($this, 'info')) {
                    $this->info($successMsg);
                } else {
                    \Log::info($successMsg);
                }
            } else {
                if (method_exists($this, 'info')) {
                    $this->info($skipMsg);
                } else {
                    \Log::info($skipMsg);
                }
            }
        } catch (\Throwable $e) {
            if (method_exists($this, 'error')) {
                $this->error("❌ Ping failed: " . $e->getMessage());
            } else {
                \Log::error("❌ Ping failed", ['error' => $e->getMessage()]);
            }
        }
    }
}
