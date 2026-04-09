<?php

namespace Anassrojea\Laracrawler\Traits;

trait Pingable
{
    /**
     * Checks if pinging search engines is allowed.
     *
     * Returns false in job contexts where option() is unavailable.
     *
     * @return bool true if pinging is allowed, false otherwise
     */
    protected function shouldPing(): bool
    {
        $noPing = method_exists($this, 'option') && $this->option('no-ping');
        return config('sitemap.ping', false) && !$noPing;
    }

    /**
     * Pings search engines with the sitemap URL.
     *
     * @param \Anassrojea\Laracrawler\Services\XmlWriter $writer
     * @param string $sitemapUrl
     * @param string $successMsg
     * @param string $skipMsg
     *
     * @return void
     *
     * @throws \Throwable
     */
    protected function handlePing($writer, string $sitemapUrl, string $successMsg = '✅ Sitemaps finalized and search engines notified.', string $skipMsg = '✅ Sitemaps finalized (ping skipped).')
    {
        try {
            if ($this->shouldPing()) {
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
