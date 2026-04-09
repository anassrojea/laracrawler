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
     * Checks global visited set before crawling to avoid duplicate work (DATA-03),
     * uses an atomic cache lock to merge results without race conditions (DATA-02),
     * and applies a TTL to both cache keys so stale data expires (DATA-04).
     *
     * @param Crawler $crawler
     * @return void
     */
    public function handle(Crawler $crawler): void
    {
        $cacheKey   = 'laracrawler:results';
        $visitedKey = 'laracrawler:visited';
        $lockKey    = 'laracrawler:lock';
        $ttl        = 3600; // 1 hour — DATA-04

        // DATA-03: Check and register this URL in the global visited set atomically.
        $lock = Cache::lock($lockKey, 10);
        try {
            $lock->block(5);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            // Could not acquire lock within 5 s; re-queue for a later attempt.
            $this->release(5);
            return;
        }

        try {
            $visited = Cache::get($visitedKey, []);
            if (in_array($this->url, $visited, true)) {
                // Already crawled by another job; skip (finally releases lock).
                return;
            }
            $visited[] = $this->url;
            Cache::put($visitedKey, $visited, $ttl);
        } finally {
            $lock->release();
        }

        // Crawl the URL (outside the lock — this is the slow network operation).
        $results = $crawler->crawl($this->url, $this->depth, $this->maxDepth);

        if (empty($results)) {
            return;
        }

        // DATA-02: Merge results atomically using a lock to prevent race conditions.
        $lock = Cache::lock($lockKey, 10);
        try {
            $lock->block(5);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            // Could not acquire lock within 5 s; re-queue for a later attempt.
            $this->release(5);
            return;
        }

        try {
            $existing = Cache::get($cacheKey, []);
            Cache::put($cacheKey, array_merge($existing, $results), $ttl); // DATA-04: TTL applied
        } finally {
            $lock->release();
        }
    }
}
