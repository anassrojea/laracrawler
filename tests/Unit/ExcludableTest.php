<?php

namespace Tests\Unit;

/**
 * Unit tests for Excludable::isExcluded() and safePreg().
 *
 * ExcludableStub is a minimal concrete class that uses the trait, exposing
 * the protected methods as public so tests can call them directly.
 *
 * Tests extend the Testbench-based TestCase so the Laravel container and
 * Log facade are available (debugExclusion() calls \Log::info internally).
 * The base TestCase sets exclude_urls=[] so each test is isolated; tests
 * that need specific rules override via config([...]) inside the test body.
 */
class ExcludableTest extends \Tests\TestCase
{
    // ----------------------------------------------------------------
    // Regex exclusion tests
    // ----------------------------------------------------------------

    public function test_isExcluded_by_regex_pattern(): void
    {
        config(['sitemap.exclude_urls' => ['#/admin#']]);
        $stub = new ExcludableStub();
        $this->assertTrue($stub->checkExcluded('http://example.com/admin/users'));
    }

    public function test_isNotExcluded_when_regex_does_not_match(): void
    {
        config(['sitemap.exclude_urls' => ['#/admin#']]);
        $stub = new ExcludableStub();
        $this->assertFalse($stub->checkExcluded('http://example.com/about'));
    }

    // ----------------------------------------------------------------
    // Extension glob tests
    // ----------------------------------------------------------------

    public function test_isExcluded_by_extension_glob(): void
    {
        config(['sitemap.exclude_urls' => ['*.png']]);
        $stub = new ExcludableStub();
        $this->assertTrue($stub->checkExcluded('http://example.com/images/photo.png'));
    }

    public function test_isNotExcluded_different_extension(): void
    {
        config(['sitemap.exclude_urls' => ['*.png']]);
        $stub = new ExcludableStub();
        $this->assertFalse($stub->checkExcluded('http://example.com/images/photo.jpg'));
    }

    // ----------------------------------------------------------------
    // Plain string prefix tests
    // ----------------------------------------------------------------

    public function test_isExcluded_by_plain_string_prefix(): void
    {
        config(['sitemap.exclude_urls' => ['/login']]);
        $stub = new ExcludableStub();
        $this->assertTrue($stub->checkExcluded('http://example.com/login'));
    }

    // ----------------------------------------------------------------
    // Scheme exclusion tests
    // ----------------------------------------------------------------

    public function test_isExcluded_mailto_scheme(): void
    {
        config(['sitemap.exclude_urls' => []]);
        $stub = new ExcludableStub();
        $this->assertTrue($stub->checkExcluded('mailto:user@example.com'));
    }

    // ----------------------------------------------------------------
    // safePreg() tests (SEC-03 regression guard)
    // ----------------------------------------------------------------

    public function test_safePreg_throws_on_invalid_pattern(): void
    {
        $stub = new ExcludableStub();
        $this->expectException(\InvalidArgumentException::class);
        $stub->checkSafePreg('#[unclosed', '/some/path');
    }

    public function test_safePreg_returns_true_on_match(): void
    {
        $stub = new ExcludableStub();
        $this->assertTrue($stub->checkSafePreg('#/admin#', '/admin/users'));
    }

    public function test_safePreg_returns_false_on_no_match(): void
    {
        $stub = new ExcludableStub();
        $this->assertFalse($stub->checkSafePreg('#/admin#', '/about'));
    }
}

// ----------------------------------------------------------------
// Concrete stub class — defined outside the test class so PHPUnit
// can autoload it but it does not pollute Tests\Unit namespace.
// ----------------------------------------------------------------

class ExcludableStub
{
    use \Anassrojea\Laracrawler\Traits\Excludable;

    public function checkExcluded(string $url): bool
    {
        return $this->isExcluded($url);
    }

    public function checkSafePreg(string $pattern, string $subject): bool
    {
        return $this->safePreg($pattern, $subject);
    }
}
