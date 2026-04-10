<?php

namespace Tests\Smoke;

use Tests\TestCase;
use Anassrojea\Laracrawler\Services\Crawler;
use Anassrojea\Laracrawler\Services\XmlWriter;

/**
 * @group smoke
 *
 * Requires network access to https://books.toscrape.com.
 * Run: vendor/bin/phpunit --group smoke
 * Skip in CI: vendor/bin/phpunit (smoke group excluded by default in phpunit.xml)
 */
class BooksSmokeTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('sitemap.base_url', 'https://books.toscrape.com');
        $app['config']->set('sitemap.normalize.enforce_https', true);
        $app['config']->set('sitemap.normalize.strip_trailing_slash', true);
        $app['config']->set('sitemap.exclude_urls', [
            '/catalogue/category/',
            '*.css',
            '*.js',
        ]);
        $app['config']->set('sitemap.include.images', true);
        $app['config']->set('sitemap.include.videos', false);
    }

    /**
     * Verify that a depth-1 crawl of books.toscrape.com produces sitemap entries
     * containing image:image nodes in the XML output. This confirms the image
     * extraction pipeline works end-to-end against real HTML.
     *
     * books.toscrape.com homepage has 20 book cover <img> tags per page.
     * Verified live: 2026-04-10.
     */
    public function testBooksSiteHasImageEntries(): void
    {
        // Connectivity check
        try {
            $ch = curl_init('https://books.toscrape.com/');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $result = curl_exec($ch);
            $errno  = curl_errno($ch);
            curl_close($ch);
            if ($errno !== 0 || $result === false) {
                $this->markTestSkipped('books.toscrape.com is unreachable (curl error ' . $errno . ')');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('books.toscrape.com connectivity check failed: ' . $e->getMessage());
        }

        $crawler = new Crawler();
        // depth=1 crawls the homepage and one level of links
        $entries = $crawler->crawl('https://books.toscrape.com/', 0, 1);

        $this->assertNotEmpty($entries, 'Crawl returned no entries from books.toscrape.com');

        // Find at least one entry with images
        $hasImages = false;
        foreach ($entries as $entry) {
            if (!empty($entry['images'])) {
                $hasImages = true;
                break;
            }
        }

        $this->assertTrue($hasImages, 'No entries with images found in depth-1 crawl of books.toscrape.com');

        // Build the sitemap XML and assert image:image appears
        $writer = new XmlWriter();
        $xml    = $writer->buildSitemap($entries, []);
        $xmlStr = $xml->asXML();

        $this->assertIsString($xmlStr, 'buildSitemap() did not return valid XML');
        $this->assertStringContainsString(
            'image:image',
            $xmlStr,
            '<image:image> not found in sitemap XML — image entries not generated from real <img> tags'
        );
    }
}
