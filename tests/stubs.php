<?php

// ============================================================
// Minimal Laravel trait/facade stubs for standalone unit tests.
// This file must be required AFTER vendor/autoload.php so that
// real illuminate classes (if present) take precedence.
// ============================================================

// illuminate/foundation stubs
namespace Illuminate\Foundation\Bus {
    if (!trait_exists(\Illuminate\Foundation\Bus\Dispatchable::class)) {
        trait Dispatchable {}
    }
}

// illuminate/queue stubs
namespace Illuminate\Queue {
    if (!trait_exists(\Illuminate\Queue\InteractsWithQueue::class)) {
        trait InteractsWithQueue {
            public function release(int $delay = 0): void {}
        }
    }
    if (!trait_exists(\Illuminate\Queue\SerializesModels::class)) {
        trait SerializesModels {}
    }
    if (!trait_exists(\Illuminate\Queue\Queueable::class)) {
        trait Queueable {}
    }
}

// illuminate/bus stubs
namespace Illuminate\Bus {
    if (!trait_exists(\Illuminate\Bus\Queueable::class)) {
        trait Queueable {}
    }
    if (!trait_exists(\Illuminate\Bus\Batchable::class)) {
        trait Batchable {}
    }
}

// illuminate/console stubs
namespace Illuminate\Console {
    if (!class_exists(\Illuminate\Console\Command::class)) {
        abstract class Command {
            const SUCCESS = 0;
            const FAILURE = 1;
            protected $signature = '';
            protected $description = '';
            public function option(string $key = null): mixed { return null; }
            public function info(string $string): void {}
            public function error(string $string): void {}
            public function warn(string $string): void {}
            public function line(string $string): void {}
            abstract public function handle(): int;
        }
    }
}

// illuminate/contracts stubs
namespace Illuminate\Contracts\Queue {
    if (!interface_exists(\Illuminate\Contracts\Queue\ShouldQueue::class)) {
        interface ShouldQueue {}
    }
}

namespace Illuminate\Contracts\Cache {
    if (!class_exists(\Illuminate\Contracts\Cache\LockTimeoutException::class)) {
        class LockTimeoutException extends \RuntimeException {}
    }
}

// illuminate/support facades stubs
namespace Illuminate\Support\Facades {
    if (!class_exists(\Illuminate\Support\Facades\Cache::class)) {
        class Cache {
            public static function lock(string $name, int $seconds = 0): object
            {
                return new class {
                    public function block(int $seconds): void {}
                    public function release(): void {}
                };
            }
            public static function get(string $key, mixed $default = null): mixed { return $default; }
            public static function put(string $key, mixed $value, int $ttl = 0): bool { return true; }
            public static function forget(string $key): bool { return true; }
        }
    }
    if (!class_exists(\Illuminate\Support\Facades\Bus::class)) {
        class Bus {
            public static function batch(array $jobs): object
            {
                return new class {
                    public function then(callable $cb): static { return $this; }
                    public function dispatch(): static { return $this; }
                };
            }
        }
    }
    if (!class_exists(\Illuminate\Support\Facades\Log::class)) {
        class Log {
            public static function info(string $msg, array $ctx = []): void {}
            public static function warning(string $msg, array $ctx = []): void {}
            public static function error(string $msg, array $ctx = []): void {}
        }
    }
}

// Global namespace helpers
namespace {
    if (!function_exists('config')) {
        function config(string $key = null, mixed $default = null): mixed
        {
            global $TEST_CONFIG;
            if ($key === null) {
                return $TEST_CONFIG ?? [];
            }
            return $TEST_CONFIG[$key] ?? $default;
        }
    }

    if (!function_exists('now')) {
        function now(): \Carbon\Carbon
        {
            return \Carbon\Carbon::now();
        }
    }

    if (!function_exists('base_path')) {
        function base_path(string $path = ''): string
        {
            return rtrim(dirname(__DIR__), '/') . ($path ? '/' . ltrim($path, '/') : '');
        }
    }

    if (!function_exists('public_path')) {
        function public_path(string $path = ''): string
        {
            return base_path('public') . ($path ? '/' . ltrim($path, '/') : '');
        }
    }

    if (!function_exists('app')) {
        function app(string $abstract = null): mixed
        {
            return null;
        }
    }
}
