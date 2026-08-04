<?php

declare(strict_types=1);

namespace Hkyss\Sonar;

/** Snapshot as response headers, so the overlay can report XHR requests too. */
final class Headers
{
    private const SUFFIXES = ['Queries', 'Db-Time', 'Time', 'Memory'];

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, string>
     */
    public static function fromSnapshot(array $snapshot, string $prefix = 'X-Sonar-'): array
    {
        if ($snapshot === []) {
            return [];
        }

        return [
            $prefix . 'Queries' => (string) ($snapshot['db']['count'] ?? 0),
            $prefix . 'Db-Time' => (string) ($snapshot['db']['timeMs'] ?? 0),
            $prefix . 'Time' => (string) ($snapshot['time']['totalMs'] ?? 0),
            $prefix . 'Memory' => (string) ($snapshot['memory']['peakMb'] ?? 0),
        ];
    }

    /** @return array<int, string> */
    public static function names(string $prefix = 'X-Sonar-'): array
    {
        return array_map(static fn (string $suffix): string => $prefix . $suffix, self::SUFFIXES);
    }

    /**
     * @param  array<int, string>  $names
     * @return string
     */
    public static function expose(?string $current, array $names): string
    {
        $exposed = array_filter(array_map('trim', explode(',', (string) $current)));

        return implode(', ', array_unique(array_merge($exposed, $names)));
    }
}
