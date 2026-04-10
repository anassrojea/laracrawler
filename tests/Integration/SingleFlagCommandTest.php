<?php

namespace Tests\Integration;

use Tests\TestCase;

class SingleFlagCommandTest extends TestCase
{
    /** @var resource|false */
    protected static $server = null;

    protected static string $relDir = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $docRoot = __DIR__ . '/../fixtures';
        $cmd = [PHP_BINARY, '-S', '127.0.0.1:9878', '-t', $docRoot];
        self::$server = proc_open(
            $cmd,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        usleep(200000);
        // Use a relative path so base_path() resolves it correctly inside Testbench
        self::$relDir = 'storage/sitemap-test-' . uniqid('', true);
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

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure the output directory exists relative to Testbench base path
        $absDir = base_path(self::$relDir);
        if (!is_dir($absDir)) {
            mkdir($absDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up output files created during the test
        $absDir = base_path(self::$relDir);
        if (is_dir($absDir)) {
            $files = glob($absDir . '/*');
            foreach (($files ?: []) as $f) {
                @unlink($f);
            }
            @rmdir($absDir);
        }
        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('sitemap.base_url', 'http://127.0.0.1:9878');
        // Disable ping to avoid hitting external URLs in tests
        $app['config']->set('sitemap.ping', false);
    }

    public function testSingleFlagWritesSitemapXml(): void
    {
        // CRASH-01 regression guard: --single path calls buildSitemap() then asXML()
        // which previously crashed because buildSitemap() was protected (now public).
        $this->artisan('laracrawler:generate', [
            '--single'    => true,
            '--output'    => self::$relDir,
            '--max-depth' => 0,
            '--no-ping'   => true,
        ])->assertExitCode(0);

        $sitemapPath = base_path(self::$relDir) . '/sitemap.xml';
        $this->assertFileExists($sitemapPath, 'sitemap.xml was not written by --single flag');

        // File must be valid XML with <urlset> root
        $contents = file_get_contents($sitemapPath);
        $this->assertStringContainsString('<urlset', $contents, 'sitemap.xml does not contain <urlset>');
    }
}
