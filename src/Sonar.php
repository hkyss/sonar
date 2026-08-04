<?php

declare(strict_types=1);

namespace Hkyss\Sonar;

/** Request-scoped entry point; every method is a no-op while the collector is off. */
final class Sonar
{
    private static ?Collector $collector = null;

    private static ?Config $config = null;

    public static function boot(?Config $config = null): ?Collector
    {
        self::$config = $config ??= Config::fromEnv();

        if (!$config->collecting()) {
            return self::$collector = null;
        }

        return self::$collector ??= new Collector(null, $config->maxQueries());
    }

    /**
     * Opens a new request: drops what was collected and restarts the clock.
     *
     * Under PHP-FPM boot() is enough, because the process handles one request
     * and measures from REQUEST_TIME_FLOAT. Long-running runtimes (Octane,
     * Swoole, RoadRunner, queue workers) reuse the process, so they have to
     * call this at every request boundary or the figures keep accumulating.
     */
    public static function start(?Config $config = null): ?Collector
    {
        self::$config = $config ?? self::$config ?? Config::fromEnv();

        if (!self::$config->collecting()) {
            return self::$collector = null;
        }

        return self::$collector = new Collector(microtime(true), self::$config->maxQueries());
    }

    public static function config(): Config
    {
        return self::$config ??= Config::off();
    }

    public static function collector(): ?Collector
    {
        return self::$collector;
    }

    public static function collecting(): bool
    {
        return self::$collector !== null;
    }

    public static function visible(): bool
    {
        return self::collecting() && self::config()->visible();
    }

    public static function record(string $sql, float $timeMs, string $source = 'db'): void
    {
        self::$collector?->record($sql, $timeMs, $source);
    }

    public static function add(string $source, int $count, float $timeMs): void
    {
        self::$collector?->add($source, $count, $timeMs);
    }

    public static function addUsing(string $source, callable $resolver): void
    {
        self::$collector?->addUsing($source, $resolver);
    }

    public static function mark(string $name, float $timeMs): void
    {
        self::$collector?->mark($name, $timeMs);
    }

    /** @return callable(): void */
    public static function timer(string $name): callable
    {
        return self::$collector?->timer($name) ?? static function (): void {
        };
    }

    public static function meta(string $key, mixed $value): void
    {
        self::$collector?->meta($key, $value);
    }

    /** @return array<string, mixed> */
    public static function snapshot(): array
    {
        return self::$collector?->snapshot() ?? [];
    }

    /** @return array<string, string> */
    public static function headers(): array
    {
        return self::visible()
            ? Headers::fromSnapshot(self::snapshot(), self::config()->headerPrefix())
            : [];
    }

    public static function inject(string $html): string
    {
        return self::visible()
            ? (new Overlay())->injectInto($html, self::snapshot())
            : $html;
    }

    public static function reset(): void
    {
        self::$collector = null;
        self::$config = null;
    }
}
