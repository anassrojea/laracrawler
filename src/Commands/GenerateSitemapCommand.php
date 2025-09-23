<?php

namespace Anassrojea\Laracrawler\Commands;

use Anassrojea\Laracrawler\Traits\Pingable;
use Illuminate\Console\Command;

class GenerateSitemapCommand extends Command
{
    use Pingable;
    protected $signature = 'laracrawler:generate 
                        {--max-depth=2 : Maximum crawl depth} 
                        {--output=public : Output directory} 
                        {--split : Force multiple sitemaps with index} 
                        {--single : Force a single sitemap.xml only} 
                        {--no-ping : Skip search engine pinging even if enabled in config} 
                        {--ping-only : Ping search engines without regenerating sitemap} 
                        {--sitemap= : Custom sitemap file (used with --ping-only)} 
                        {--debug : Show why URLs are excluded during crawl}
                        {--summary : Show a summary of excluded URLs after crawl}
                        {--fresh : Ignore cache and re-crawl everything} 
                        {--queue : Process crawl using Laravel queue}
                        {--validate : Check link status and log broken links}
                        {--audit-indexability : Check for noindex conflicts (meta + headers)}';


    protected $description = 'Generate a sitemap with images and videos by crawling the site';


    /**
     * Generate a sitemap with images and videos by crawling the site.
     *
     * This command will generate a sitemap by crawling the site up to the specified depth,
     * and then write the sitemap to disk. If the --ping-only option is passed, the command
     * will only ping the search engines without re-generating the sitemap.
     *
     * @return int
     */
    public function handle()
    {
        $writer = app(\Anassrojea\Laracrawler\Services\XmlWriter::class);

        // ✅ Handle ping-only
        if ($this->option('ping-only')) {
            try {
                $sitemapUrl = null;

                if ($custom = $this->option('sitemap')) {
                    $sitemapPath = public_path(ltrim($custom, '/'));

                    if (!file_exists($sitemapPath)) {
                        $this->error("❌ Custom sitemap file not found at: {$sitemapPath}");
                        return;
                    }

                    $sitemapUrl = rtrim(config('sitemap.base_url'), '/') . '/' . ltrim($custom, '/');
                } else {
                    $sitemapPath = public_path(config('sitemap.use_index') ? 'sitemap-index.xml' : 'sitemap.xml');

                    if (!file_exists($sitemapPath)) {
                        $this->error("❌ Sitemap file not found at: {$sitemapPath}");
                        return;
                    }

                    $sitemapUrl = rtrim(config('sitemap.base_url'), '/') . (config('sitemap.use_index') ? '/sitemap-index.xml' : '/sitemap.xml');
                }

                // ✅ Use trait helper so --no-ping and config are respected
                $this->handlePing(
                    $writer,
                    $sitemapUrl,
                    "✅ Search engines pinged for: {$sitemapUrl}",
                    "✅ Ping skipped for: {$sitemapUrl}"
                );
            } catch (\Exception $e) {
                $this->error("❌ Failed to ping search engines: " . $e->getMessage());
            }
            return;
        }

        // ✅ Handle depth override
        $maxDepth = (int) $this->option('max-depth');
        $outputDir = base_path($this->option('output'));

        // ✅ Handle fresh crawl
        if ($this->option('fresh')) {
            \Cache::forget('laracrawler:results');
            $this->info("♻️ Cache cleared, fresh crawl will run.");
        }

        // ✅ Handle queue mode
        if ($this->option('queue') || config('sitemap.queue.enabled', false)) {
            $this->info("🚀 Dispatching crawl jobs to queue (depth {$maxDepth})...");
            app(\Anassrojea\Laracrawler\Services\Crawler::class)->crawlQueued(config('sitemap.base_url'), $maxDepth);
            $this->info("✅ Crawl jobs dispatched. The sitemap will be finalized automatically once all jobs complete.");
            $this->line("   👉 Make sure your queue worker is running: php artisan queue:work");
            return;
        }

        // ✅ Normal synchronous crawl
        $this->info("Crawling site up to depth {$maxDepth}...");
        $crawler = app(\Anassrojea\Laracrawler\Services\Crawler::class);

        $validate = $this->input->hasParameterOption('--validate')
            ? $this->option('validate')
            : config('sitemap.validate_links', false);


        $auditIndexability = $this->input->hasParameterOption('--audit-indexability')
            ? $this->option('audit-indexability')
            : config('sitemap.indexability_audit', false);

        $urls = $crawler->crawl(
            config('sitemap.base_url'),
            0,
            $maxDepth,
            $this->option('summary') || $this->option('debug'),
            $validate,
            $auditIndexability
        );

        $this->info("🔎 Maximum depth reached during crawl: " . $crawler->getMaxReachedDepth());

        // ✅ Handle flags
        if ($this->option('single')) {
            $this->info("Forcing single sitemap.xml...");
            $xml = $writer->buildSitemap($urls);
            $xml->asXML(rtrim($outputDir, '/') . '/sitemap.xml');
            return;
        }

        if ($this->option('split')) {
            $this->info("Forcing split sitemap with index...");
            config(['sitemap.use_index' => true]);
        }

        // ✅ Default behavior
        $writer->write($urls, $outputDir);
        $this->info("✅ Sitemaps generated in: \"{$outputDir}\"");

        if (($this->option('validate') || config('sitemap.validate_links'))) {
            if (!empty($crawler->getErrors())) {
                $writer->writeErrors($crawler->getErrors(), $outputDir);

                $count = count($crawler->getErrors());
                $max   = config('sitemap.max_errors', 5000);

                if ($count >= $max) {
                    $this->warn("⚠️ {$count} broken links detected (showing first {$max}). See sitemap-errors.xml for details.");
                } else {
                    $this->warn("⚠️ {$count} broken links detected. See sitemap-errors.xml for details.");
                }
            } else {
                $this->info("✅ No broken links detected.");
            }
        }

        // ✅ Build sitemap URL
        $sitemapUrl = rtrim(config('sitemap.base_url'), '/');
        if ($this->option('single')) {
            $sitemapUrl .= '/sitemap.xml';
        } elseif ($this->option('split')) {
            $sitemapUrl .= '/sitemap-index.xml';
        } else {
            $sitemapUrl .= config('sitemap.use_index') ? '/sitemap-index.xml' : '/sitemap.xml';
        }

        // ✅ Handle ping
        $this->handlePing(
            $writer,
            $sitemapUrl,
            "✅ Sitemaps generated and search engines notified.",
            "✅ Sitemaps generated (ping skipped)."
        );
    }
}
