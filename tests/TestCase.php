<?php

namespace Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Anassrojea\Laracrawler\LaraCrawlerServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaraCrawlerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Use a local fixture server base URL; individual tests override as needed
        $app['config']->set('sitemap.base_url', 'http://127.0.0.1:9876');

        // Normalization — match exactly config/sitemap.php keys
        $app['config']->set('sitemap.normalize.strip_queries', true);
        $app['config']->set('sitemap.normalize.strip_anchors', true);
        $app['config']->set('sitemap.normalize.canonicalize', true);
        $app['config']->set('sitemap.normalize.enforce_https', false); // false so tests use http://
        $app['config']->set('sitemap.normalize.enforce_www', null);
        $app['config']->set('sitemap.normalize.strip_trailing_slash', true);
        $app['config']->set('sitemap.normalize.force_trailing_slash', false);

        // Exclusions — empty so normalizeUrl/cleanUrl tests are not filtered
        $app['config']->set('sitemap.exclude_urls', []);

        // Validation off by default in tests
        $app['config']->set('sitemap.validate_links', false);
        $app['config']->set('sitemap.indexability_audit', false);

        // XML output defaults
        $app['config']->set('sitemap.include.images', true);
        $app['config']->set('sitemap.include.videos', false);
        $app['config']->set('sitemap.include.languages', false);
        $app['config']->set('sitemap.use_index', false);
        $app['config']->set('sitemap.max_urls_per_sitemap', 50000);
        $app['config']->set('sitemap.max_file_size', 52428800);
        $app['config']->set('sitemap.defaults.changefreq', 'weekly');
        $app['config']->set('sitemap.defaults.priority', '0.8');
        $app['config']->set('sitemap.defaults.lastmod', 'now');
        $app['config']->set('sitemap.rules', []);
        $app['config']->set('sitemap.alternates', []);
        $app['config']->set('sitemap.priority_scoring.enabled', false);
        $app['config']->set('sitemap.ping', false);
    }
}
