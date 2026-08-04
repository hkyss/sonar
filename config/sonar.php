<?php

declare(strict_types=1);

$sonarEnv = static function (string $key, mixed $default = null): mixed {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    return is_string($value) && $value !== '' ? $value : $default;
};

return [
    /** false | true | 'gated'. In production, true is ignored — use 'gated' with a gate. */
    'enabled' => $sonarEnv('SONAR', false),

    /** How many distinct statements to keep; the rest only reach the totals. */
    'max_queries' => (int) $sonarEnv('SONAR_MAX_QUERIES', 200),

    /** Callable deciding who sees the overlay in 'gated' mode. There is no default: without one, 'gated' is off. */
    'gate' => null,
];
