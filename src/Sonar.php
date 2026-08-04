<?php

declare(strict_types=1);

namespace Sonar;

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

    public static function lazy(string $source, callable $resolver): void
    {
        self::$collector?->lazy($source, $resolver);
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
