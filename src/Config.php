<?php

declare(strict_types=1);

namespace hkyss\Sonar;

use Closure;

/** Runtime settings: who sees the overlay and how much is kept. */
final class Config
{
    public const MODE_OFF = 'off';

    public const MODE_ON = 'on';

    public const MODE_GATED = 'gated';

    private ?Closure $gate;

    public function __construct(
        private readonly string $mode = self::MODE_OFF,
        ?callable $gate = null,
        private readonly int $maxQueries = 200,
        private readonly string $headerPrefix = 'X-Sonar-',
    ) {
        $this->gate = $gate === null ? null : Closure::fromCallable($gate);
    }

    /**
     * Accepts the loose values an env var or a config file may carry.
     *
     * Both fallbacks are deliberate. `gated` without a gate would show the
     * overlay to everyone, and `on` in production would show it to anonymous
     * visitors; either way the safe reading of an ambiguous setting is off.
     * To run in production, use `gated` with a gate.
     */
    public static function fromValue(mixed $value, ?callable $gate = null, int $maxQueries = 200, bool $production = false): self
    {
        $mode = self::resolveMode($value);

        if ($mode === self::MODE_ON && $production) {
            $mode = self::MODE_OFF;
        }

        if ($mode === self::MODE_GATED && $gate === null) {
            $mode = self::MODE_OFF;
        }

        return new self($mode, $gate, $maxQueries);
    }

    public static function fromEnv(string $prefix = 'SONAR', ?callable $gate = null): self
    {
        return self::fromValue(
            self::env($prefix),
            $gate,
            (int) (self::env($prefix . '_MAX_QUERIES') ?? 200),
            self::isProduction()
        );
    }

    /** Reads APP_ENV where it exists; absent, the environment is unknown and not assumed to be production. */
    public static function isProduction(): bool
    {
        return in_array(strtolower((string) self::env('APP_ENV')), ['production', 'prod'], true);
    }

    public static function off(): self
    {
        return new self();
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function maxQueries(): int
    {
        return $this->maxQueries;
    }

    public function headerPrefix(): string
    {
        return $this->headerPrefix;
    }

    public function collecting(): bool
    {
        return $this->mode !== self::MODE_OFF;
    }

    public function visible(): bool
    {
        return match ($this->mode) {
            self::MODE_ON => true,
            self::MODE_GATED => $this->gate !== null && (bool) ($this->gate)(),
            default => false,
        };
    }

    private static function resolveMode(mixed $value): string
    {
        if (is_string($value)) {
            $value = strtolower(trim($value));
        }

        return match ($value) {
            true, 1, '1', 'true', 'on', 'yes', 'all' => self::MODE_ON,
            'gated', 'gate', 'manager', 'admin', 'auth' => self::MODE_GATED,
            default => self::MODE_OFF,
        };
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
