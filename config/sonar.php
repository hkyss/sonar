<?php

declare(strict_types=1);

$sonarEnv = static function (string $key, mixed $default = null): mixed {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    return is_string($value) && $value !== '' ? $value : $default;
};

return [
    /** false | true | 'gated'; in production true is ignored, so use 'gated' with a gate. */
    'enabled' => $sonarEnv('SONAR', false),

    /** The rest only reach the totals. */
    'max_queries' => (int) $sonarEnv('SONAR_MAX_QUERIES', 200),

    /** Callable; there is no default, so without one 'gated' is off. */
    'gate' => null,
];
