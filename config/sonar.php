<?php

declare(strict_types=1);

$sonarEnv = static function (string $key, mixed $default = null): mixed {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    return is_string($value) && $value !== '' ? $value : $default;
};

return [
    /** false | true | 'gated' */
    'enabled' => $sonarEnv('SONAR', false),

    'max_queries' => (int) $sonarEnv('SONAR_MAX_QUERIES', 200),

    /** Callable deciding who sees the overlay in 'gated' mode; defaults to an authenticated user. */
    'gate' => null,
];
