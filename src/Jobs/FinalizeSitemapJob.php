<?php

namespace Anassrojea\Laracrawler\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Anassrojea\Laracrawler\Services\XmlWriter;
use Anassrojea\Laracrawler\Traits\Pingable;
use Illuminate\Support\Facades\Cache;

class FinalizeSitemapJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable, Pingable;

    protected string $output;

    /**
     * Set the output directory for the sitemap.
     *
     * @param string $output The output directory for the sitemap.
     */
    public function __construct(string $output = 'public')
    {
        $this->output = $output;
    }

    /**
     * Finalize the sitemap by writing the cached results to disk and pinging the search engines.
     *
     * This job will write the cached results to disk using the XmlWriter service, and then ping the search engines using the Pingable trait.
     *
     * If there are no results to finalize, a warning will be logged.
     *
     * Once the sitemap is finalized, the cached results will be forgotten.
     */
    public function handle(): void
    {
        $writer = app(\Anassrojea\Laracrawler\Services\XmlWriter::class);
        $results = Cache::get('laracrawler:results', []);

        if (empty($results)) {
            \Log::warning("LaraCrawler: No results to finalize.");
            return;
        }

        $outputDir = base_path($this->output);
        $writer->write($results, $outputDir);

        $sitemapUrl = rtrim(config('sitemap.base_url'), '/');
        $sitemapUrl .= config('sitemap.use_index') ? '/sitemap-index.xml' : '/sitemap.xml';

        // ✅ Ping logic from Pingable trait
        $this->handlePing(
            $writer,
            $sitemapUrl,
            "✅ Sitemaps finalized and search engines notified (via job).",
            "✅ Sitemaps finalized (ping skipped)."
        );

        Cache::forget('laracrawler:results');
    }
}
