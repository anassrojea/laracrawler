<?php

namespace Tests\Integration;

use Tests\TestCase;
use Anassrojea\Laracrawler\Services\Crawler;

class PictureSrcsetTest extends TestCase
{
    /** @var resource|false */
    protected static $server = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $docRoot = __DIR__ . '/../fixtures';
        $cmd = [PHP_BINARY, '-S', '127.0.0.1:9876', '-t', $docRoot];
        self::$server = proc_open(
            $cmd,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        usleep(200000); // 200ms — Windows needs more time than Linux
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null && self::$server !== false) {
            proc_terminate(self::$server);
            usleep(200000); // 200ms before close — avoids port-in-use on Windows
            proc_close(self::$server);
            self::$server = null;
        }
        parent::tearDownAfterClass();
    }

    public function testPictureSrcsetImagesAppearInCrawlResult(): void
    {
        $crawler = new Crawler();
        $entries = $crawler->crawl('http://127.0.0.1:9876/picture-test.html', 0, 0);

        $this->assertNotEmpty($entries, 'Crawl returned no entries');

        $images = [];
        foreach ($entries as $entry) {
            foreach ($entry['images'] ?? [] as $img) {
                $images[] = $img['src'];
            }
        }

        $this->assertNotEmpty($images, 'No images found in crawl result');

        // The srcset has two entries: photo-480w.jpg and photo-800w.jpg
        // extractImages() must return both (DATA-01 regression guard)
        $hasPhoto480 = false;
        $hasPhoto800 = false;
        foreach ($images as $src) {
            if (str_contains($src, 'photo-480w.jpg')) $hasPhoto480 = true;
            if (str_contains($src, 'photo-800w.jpg')) $hasPhoto800 = true;
        }

        $this->assertTrue($hasPhoto480, 'photo-480w.jpg not found — DATA-01 srcset regression');
        $this->assertTrue($hasPhoto800, 'photo-800w.jpg not found — DATA-01 srcset regression');
    }
}
