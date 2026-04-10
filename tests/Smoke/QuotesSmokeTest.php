<?php

namespace Tests\Smoke;

use Tests\TestCase;
use Anassrojea\Laracrawler\Services\Crawler;

/**
 * @group smoke
 *
 * Requires network access to https://quotes.toscrape.com.
 * Run: vendor/bin/phpunit --group smoke
 * Skip in CI: vendor/bin/phpunit (smoke group excluded by default in phpunit.xml)
 */
class QuotesSmokeTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('sitemap.base_url', 'https://quotes.toscrape.com');
        $app['config']->set('sitemap.normalize.enforce_https', true);
        $app['config']->set('sitemap.normalize.strip_trailing_slash', true);
        // Exclude tag/author pages so the crawl stays on pagination pages only
        $app['config']->set('sitemap.exclude_urls', [
            '/tag/',
            '/author/',
            '/login',
        ]);
    }

    /**
     * Verify that a full crawl of quotes.toscrape.com returns all 10 known
     * pagination URLs. The site has pages /page/2/ through /page/10/ with /page/11/
     * returning "No quotes found", confirming 10 is the last page.
     *
     * Verified live: 2026-04-10.
     */
    public function testQuotesSiteHas10PaginationPages(): void
    {
        // Connectivity check — skip gracefully if site is down
        try {
            $ch = curl_init('https://quotes.toscrape.com/');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $result = curl_exec($ch);
            $errno  = curl_errno($ch);
            curl_close($ch);
            if ($errno !== 0 || $result === false) {
                $this->markTestSkipped('quotes.toscrape.com is unreachable (curl error ' . $errno . ')');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('quotes.toscrape.com connectivity check failed: ' . $e->getMessage());
        }

        $crawler = new Crawler();
        $entries = $crawler->crawl('https://quotes.toscrape.com/', 0, 10);

        $this->assertNotEmpty($entries, 'Crawl returned no entries from quotes.toscrape.com');

        $urls = array_column($entries, 'url');

        // The 10 expected pagination URLs — trailing-slash-tolerant comparison
        $expected = [
            'https://quotes.toscrape.com',
            'https://quotes.toscrape.com/page/2',
            'https://quotes.toscrape.com/page/3',
            'https://quotes.toscrape.com/page/4',
            'https://quotes.toscrape.com/page/5',
            'https://quotes.toscrape.com/page/6',
            'https://quotes.toscrape.com/page/7',
            'https://quotes.toscrape.com/page/8',
            'https://quotes.toscrape.com/page/9',
            'https://quotes.toscrape.com/page/10',
        ];

        foreach ($expected as $expectedUrl) {
            $found = false;
            foreach ($urls as $url) {
                if (rtrim($url, '/') === rtrim($expectedUrl, '/')) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, "Expected URL not found in crawl results: {$expectedUrl}");
        }
    }
}
