<?php

namespace Anassrojea\Laracrawler\Tests\Unit;

use Tests\TestCase;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;
use Anassrojea\Laracrawler\Services\Crawler;

/**
 * Tests for Phase 01 Plan 02: DATA-01 fix.
 *
 * DATA-01: Crawler::extractImages() must return plain arrays (not Closures) for
 *          <picture><source srcset="..."> entries so they are correctly merged
 *          into the sitemap image list.
 */
class ExtractImagesTest extends TestCase
{
    /**
     * Expose the protected extractImages() method via a subclass.
     */
    protected function makeTestCrawler(): object
    {
        return new class extends Crawler {
            // Override constructor to skip Guzzle instantiation (needs config()).
            public function __construct()
            {
                // No parent::__construct() — we don't need an HTTP client for this test.
            }

            public function callExtractImages(DomCrawler $dom): array
            {
                return $this->extractImages($dom);
            }
        };
    }

    // -------------------------------------------------------------------------
    // DATA-01: picture/source srcset produces plain arrays, not Closures
    // -------------------------------------------------------------------------

    public function test_extract_images_returns_plain_arrays_for_picture_source_srcset(): void
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<body>
<picture>
  <source srcset="image-400.webp 400w, image-800.webp 800w">
</picture>
</body>
</html>
HTML;

        $dom = new DomCrawler($html);
        $crawler = $this->makeTestCrawler();

        $images = $crawler->callExtractImages($dom);

        $this->assertNotEmpty($images, 'extractImages() must return at least one image entry from <picture><source srcset> (DATA-01)');

        foreach ($images as $index => $image) {
            $this->assertIsArray(
                $image,
                "Image entry at index {$index} must be a plain array, not a Closure. " .
                'The double-arrow fn($s) => fn($s) => bug caused Closure objects to be stored (DATA-01)'
            );
            $this->assertArrayHasKey('src', $image, "Image entry at index {$index} must have a 'src' key");
            $this->assertArrayHasKey('title', $image, "Image entry at index {$index} must have a 'title' key");
            $this->assertArrayHasKey('caption', $image, "Image entry at index {$index} must have a 'caption' key");
        }
    }

    public function test_extract_images_srcset_entries_are_not_closures(): void
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<body>
<picture>
  <source srcset="hero.webp">
</picture>
</body>
</html>
HTML;

        $dom = new DomCrawler($html);
        $crawler = $this->makeTestCrawler();

        $images = $crawler->callExtractImages($dom);

        foreach ($images as $index => $image) {
            $this->assertNotInstanceOf(
                \Closure::class,
                $image,
                "Image entry at index {$index} must not be a Closure. " .
                'The pre-fix double-arrow fn($s) => fn($s) => produced Closure objects (DATA-01)'
            );
        }
    }

    public function test_extract_images_returns_correct_src_from_srcset(): void
    {
        // A single-entry srcset with no size descriptor — src should equal the trimmed URL.
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<body>
<picture>
  <source srcset="photo.jpg">
</picture>
</body>
</html>
HTML;

        $dom = new DomCrawler($html);
        $crawler = $this->makeTestCrawler();

        $images = $crawler->callExtractImages($dom);

        $this->assertNotEmpty($images, 'A <picture><source srcset="photo.jpg"> should produce at least one image entry');

        // Find the entry with our expected src.
        $found = false;
        foreach ($images as $image) {
            if (is_array($image) && isset($image['src']) && strpos($image['src'], 'photo.jpg') !== false) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, "Expected an image entry with src containing 'photo.jpg' from <picture><source srcset='photo.jpg'>");
    }
}
