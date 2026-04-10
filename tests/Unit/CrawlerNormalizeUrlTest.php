<?php

namespace Tests\Unit;

use Anassrojea\Laracrawler\Services\Crawler;

/**
 * Unit tests for Crawler::normalizeUrl() and Crawler::cleanUrl().
 *
 * Both methods are protected and rely on config() for their behaviour.
 * We extend the Testbench-based TestCase so the full Laravel config
 * surface is available; defineEnvironment() in the base class sets
 * enforce_https=false, strip_anchors=true, strip_queries=true,
 * canonicalize=true, and strip_trailing_slash=true.
 */
class CrawlerNormalizeUrlTest extends \Tests\TestCase
{
    // ----------------------------------------------------------------
    // Helper — invoke any protected Crawler method via ReflectionMethod
    // ----------------------------------------------------------------

    private function invoke(string $method, mixed ...$args): mixed
    {
        $crawler = new Crawler();
        $ref     = new \ReflectionMethod($crawler, $method);
        return $ref->invoke($crawler, ...$args);
    }

    // ================================================================
    // normalizeUrl() tests
    // ================================================================

    public function test_normalizeUrl_resolves_relative_path_and_strips_trailing_slash(): void
    {
        $result = $this->invoke('normalizeUrl', '/page/2/', 'http://127.0.0.1:9876/');
        $this->assertSame('http://127.0.0.1:9876/page/2', $result);
    }

    public function test_normalizeUrl_returns_null_for_mailto(): void
    {
        $result = $this->invoke('normalizeUrl', 'mailto:user@example.com', 'http://127.0.0.1:9876/');
        $this->assertNull($result);
    }

    public function test_normalizeUrl_returns_null_for_tel(): void
    {
        $result = $this->invoke('normalizeUrl', 'tel:+123456789', 'http://127.0.0.1:9876/');
        $this->assertNull($result);
    }

    public function test_normalizeUrl_returns_null_for_javascript(): void
    {
        $result = $this->invoke('normalizeUrl', 'javascript:void(0)', 'http://127.0.0.1:9876/');
        $this->assertNull($result);
    }

    public function test_normalizeUrl_strips_anchor(): void
    {
        $result = $this->invoke('normalizeUrl', '/about#section', 'http://127.0.0.1:9876/');
        $this->assertSame('http://127.0.0.1:9876/about', $result);
    }

    public function test_normalizeUrl_strips_query_string(): void
    {
        $result = $this->invoke('normalizeUrl', '/search?q=foo', 'http://127.0.0.1:9876/');
        $this->assertSame('http://127.0.0.1:9876/search', $result);
    }

    public function test_normalizeUrl_resolves_protocol_relative_url(): void
    {
        $result = $this->invoke('normalizeUrl', '//127.0.0.1:9876/cdn/img.png', 'http://127.0.0.1:9876/');
        $this->assertSame('http://127.0.0.1:9876/cdn/img.png', $result);
    }

    public function test_normalizeUrl_returns_null_for_empty_string(): void
    {
        $result = $this->invoke('normalizeUrl', '', 'http://127.0.0.1:9876/');
        $this->assertNull($result);
    }

    public function test_normalizeUrl_returns_null_for_null_input(): void
    {
        $result = $this->invoke('normalizeUrl', null, 'http://127.0.0.1:9876/');
        $this->assertNull($result);
    }

    // ================================================================
    // cleanUrl() tests
    // ================================================================

    public function test_cleanUrl_collapses_duplicate_slashes(): void
    {
        $result = $this->invoke('cleanUrl', 'http://127.0.0.1:9876//foo//bar');
        $this->assertSame('http://127.0.0.1:9876/foo/bar', $result);
    }

    public function test_cleanUrl_strips_trailing_slash_from_non_root_path(): void
    {
        $result = $this->invoke('cleanUrl', 'http://127.0.0.1:9876/about/');
        $this->assertSame('http://127.0.0.1:9876/about', $result);
    }

    public function test_cleanUrl_preserves_root_slash(): void
    {
        $result = $this->invoke('cleanUrl', 'http://127.0.0.1:9876/');
        $this->assertSame('http://127.0.0.1:9876/', $result);
    }

    public function test_cleanUrl_lowercases_path_when_canonicalize_is_true(): void
    {
        $result = $this->invoke('cleanUrl', 'http://127.0.0.1:9876/About-Us');
        $this->assertSame('http://127.0.0.1:9876/about-us', $result);
    }
}
