<?php

declare(strict_types=1);

namespace Sonar;

use Closure;

/** Per-request counters: queries, aggregate sources, named timings and metadata. */
final class Collector
{
    /** @var array<string, array{sql: string, source: string, count: int, timeMs: float, maxMs: float}> */
    private array $queries = [];

    /** @var array<string, array{count: int, timeMs: float}> */
    private array $counters = [];

    /** @var array<string, Closure> */
    private array $lazy = [];

    /** @var array<string, float> */
    private array $marks = [];

    /** @var array<string, mixed> */
    private array $meta = [];

    private bool $truncated = false;

    private readonly float $startedAt;

    private readonly int $maxQueries;

    public function __construct(?float $startedAt = null, int $maxQueries = 200)
    {
        $this->startedAt = $startedAt ?? (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $this->maxQueries = max(1, $maxQueries);
    }

    public function record(string $sql, float $timeMs, string $source = 'db'): void
    {
        $this->add($source, 1, $timeMs);

        $key = $source . '|' . self::fingerprint($sql);

        if (!isset($this->queries[$key])) {
            if (count($this->queries) >= $this->maxQueries) {
                $this->truncated = true;

                return;
            }

            $this->queries[$key] = [
                'sql' => self::shorten($sql),
                'source' => $source,
                'count' => 0,
                'timeMs' => 0.0,
                'maxMs' => 0.0,
            ];
        }

        $this->queries[$key]['count']++;
        $this->queries[$key]['timeMs'] += $timeMs;
        $this->queries[$key]['maxMs'] = max($this->queries[$key]['maxMs'], $timeMs);
    }

    /** Totals for a source that cannot report individual statements. */
    public function add(string $source, int $count, float $timeMs): void
    {
        $counter = $this->counters[$source] ?? ['count' => 0, 'timeMs' => 0.0];

        $this->counters[$source] = [
            'count' => $counter['count'] + $count,
            'timeMs' => $counter['timeMs'] + $timeMs,
        ];
    }

    /**
     * Aggregate source read at snapshot time.
     *
     * @param  callable(): array{count: int|float, timeMs: int|float}  $resolver
     */
    public function lazy(string $source, callable $resolver): void
    {
        $this->lazy[$source] = Closure::fromCallable($resolver);
    }

    public function mark(string $name, float $timeMs): void
    {
        $this->marks[$name] = ($this->marks[$name] ?? 0.0) + $timeMs;
    }

    /** @return callable(): void Stops the timer and stores the elapsed time. */
    public function timer(string $name): callable
    {
        $startedAt = microtime(true);

        return function () use ($name, $startedAt): void {
            $this->mark($name, (microtime(true) - $startedAt) * 1000);
        };
    }

    public function meta(string $key, mixed $value): void
    {
        $this->meta[$key] = $value;
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $sources = $this->counters;

        foreach ($this->lazy as $source => $resolver) {
            $resolved = $resolver();
            $sources[$source] = [
                'count' => (int) ($resolved['count'] ?? 0),
                'timeMs' => round((float) ($resolved['timeMs'] ?? 0), 1),
            ];
        }

        $count = 0;
        $timeMs = 0.0;

        foreach ($sources as $source => $counter) {
            $count += $counter['count'];
            $timeMs += $counter['timeMs'];
            $sources[$source] = [
                'count' => $counter['count'],
                'timeMs' => round($counter['timeMs'], 1),
            ];
        }

        $totalMs = (microtime(true) - $this->startedAt) * 1000;

        return [
            'db' => [
                'count' => $count,
                'timeMs' => round($timeMs, 1),
                'sources' => $sources,
            ],
            'time' => [
                'totalMs' => round($totalMs, 1),
                'phpMs' => round(max(0.0, $totalMs - $timeMs), 1),
            ],
            'memory' => [
                'peakMb' => round(memory_get_peak_usage(true) / 1048576, 1),
            ],
            'marks' => array_map(static fn (float $ms): float => round($ms, 1), $this->marks),
            'meta' => array_map(
                static fn (mixed $value): mixed => $value instanceof Closure ? $value() : $value,
                $this->meta
            ),
            'queries' => $this->topQueries(),
            'truncated' => $this->truncated,
        ];
    }

    /** @return array<int, array{sql: string, source: string, count: int, timeMs: float, maxMs: float}> */
    public function topQueries(int $limit = 15): array
    {
        $queries = array_values($this->queries);

        usort($queries, static fn (array $a, array $b): int => [$b['count'], $b['timeMs']] <=> [$a['count'], $a['timeMs']]);

        return array_slice(array_map(static fn (array $query): array => [
            'sql' => $query['sql'],
            'source' => $query['source'],
            'count' => $query['count'],
            'timeMs' => round($query['timeMs'], 1),
            'maxMs' => round($query['maxMs'], 1),
        ], $queries), 0, $limit);
    }

    private static function fingerprint(string $sql): string
    {
        $sql = preg_replace('/\'[^\']*\'|"[^"]*"/', '?', $sql) ?? $sql;
        $sql = preg_replace('/\b\d+\b/', '?', $sql) ?? $sql;
        $sql = preg_replace('/\?(\s*,\s*\?)+/', '?', $sql) ?? $sql;

        return trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);
    }

    private static function shorten(string $sql, int $limit = 300): string
    {
        $sql = trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);

        return mb_strlen($sql) > $limit ? mb_substr($sql, 0, $limit) . '…' : $sql;
    }
}
