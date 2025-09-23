<?php

namespace Anassrojea\Laracrawler\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Anassrojea\Laracrawler\Services\Crawler;
use Illuminate\Bus\Batchable;
use Illuminate\Support\Facades\Cache;

class CrawlUrlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public string $url;
    public int $depth;
    public int $maxDepth;

    /**
     * Construct a new CrawlUrlJob instance.
     *
     * @param string $url The URL to be crawled.
     * @param int $depth The current depth of the crawl.
     * @param int $maxDepth The maximum depth the crawl should go.
     */
    public function __construct(string $url, int $depth, int $maxDepth)
    {
        $this->url = $url;
        $this->depth = $depth;
        $this->maxDepth = $maxDepth;
    }

    /**
     * Handle the job.
     *
     * This job will crawl the given URL and store the results in cache.
     *
     * @param Crawler $crawler
     * @return void
     */
    public function handle(Crawler $crawler): void
    {
        $results = $crawler->crawl($this->url, $this->depth, $this->maxDepth);

        // Store results in cache
        $cacheKey = "laracrawler:results";
        $existing = Cache::get($cacheKey, []);
        Cache::put($cacheKey, array_merge($existing, $results));
    }
}
