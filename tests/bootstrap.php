<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Boot a minimal container so that config() and app() helpers work
// in standalone PHPUnit tests (those that extend PHPUnit\Framework\TestCase
// directly, without Orchestra Testbench). Without this, the real illuminate
// config() helper calls app('config') which fails with no container bound.
//
// We use the plain Container (not Application) to avoid Application's
// constructor overriding the singleton instance before we can bind 'config'.
$container = new \Illuminate\Container\Container();
$container->singleton('config', function () {
    return new \Illuminate\Config\Repository([]);
});
\Illuminate\Container\Container::setInstance($container);

require_once __DIR__ . '/stubs.php';
