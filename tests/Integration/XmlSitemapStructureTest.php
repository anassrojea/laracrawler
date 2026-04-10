<?php

namespace Tests\Integration;

use Tests\TestCase;
use Anassrojea\Laracrawler\Services\Crawler;
use Anassrojea\Laracrawler\Services\XmlWriter;

class XmlSitemapStructureTest extends TestCase
{
    /** @var resource|false */
    protected static $server = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $docRoot = __DIR__ . '/../fixtures';
        $cmd = [PHP_BINARY, '-S', '127.0.0.1:9877', '-t', $docRoot];
        self::$server = proc_open(
            $cmd,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        usleep(200000);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null && self::$server !== false) {
            proc_terminate(self::$server);
            usleep(200000);
            proc_close(self::$server);
            self::$server = null;
        }
        parent::tearDownAfterClass();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        // Override base_url to port 9877 for this class
        $app['config']->set('sitemap.base_url', 'http://127.0.0.1:9877');
    }

    public function testBuildSitemapProducesValidUrlsetXml(): void
    {
        $crawler = new Crawler();
        $entries = $crawler->crawl('http://127.0.0.1:9877/basic-page.html', 0, 0);

        $this->assertNotEmpty($entries, 'Crawl returned no entries from basic-page.html');

        $writer = new XmlWriter();
        $xml = $writer->buildSitemap($entries, []);

        // Must be a SimpleXMLElement
        $this->assertInstanceOf(\SimpleXMLElement::class, $xml);

        // Root element must be <urlset>
        $this->assertSame('urlset', $xml->getName(), 'Root element is not <urlset>');

        // Must have at least one <url> child
        $urlChildren = $xml->children();
        $this->assertGreaterThan(0, count($urlChildren), 'Sitemap has no <url> elements');

        // First <url> must have a <loc> child
        $firstUrl = $urlChildren[0];
        $this->assertTrue(isset($firstUrl->loc), '<url> element has no <loc> child');

        // <loc> must not be empty
        $this->assertNotEmpty((string) $firstUrl->loc, '<loc> is empty');

        // <loc> must start with http (base_url is http:// in test env)
        $this->assertStringStartsWith('http', (string) $firstUrl->loc, '<loc> does not start with http');
    }

    public function testBuildSitemapIncludesImageNamespaceWhenImagesConfigured(): void
    {
        // sitemap.include.images is true in base TestCase
        $entries = [[
            'url'    => 'http://127.0.0.1:9877/page',
            'depth'  => 0,
            'images' => [['src' => '/img/test.jpg', 'title' => 'Test', 'caption' => 'Cap']],
            'videos' => [],
        ]];

        $writer = new XmlWriter();
        $xml    = $writer->buildSitemap($entries, []);
        $xmlStr = $xml->asXML();

        $this->assertStringContainsString('image', $xmlStr, 'XML does not contain image namespace');
    }
}
