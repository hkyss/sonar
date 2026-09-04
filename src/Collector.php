<?php

declare(strict_types=1);

namespace hkyss\Sonar;

use Closure;

/**
 * @phpstan-type SonarStatement array{sql: string, source: string, count: int, timeMs: float, maxMs: float}
 * @phpstan-type SonarSource array{count: int, timeMs: float}
 * @phpstan-type SonarSnapshot array{
 *     queries: array{count: int, timeMs: float, sources: array<string, SonarSource>},
 *     statements: list<SonarStatement>,
 *     time: array{totalMs: float, phpMs: float},
 *     memory: array{peakMb: float},
 *     marks: array<string, float>,
 *     meta: array<string, mixed>,
 *     truncated: bool
 * }
 */
final class Collector
{
    /** @var array<string, SonarStatement> */
    private array $statements = [];

    /** @var array<string, SonarSource> */
    private array $sources = [];

    /** @var array<string, Closure> */
    private array $deferred = [];

    /** @var array<string, float> */
    private array $marks = [];

    /** @var array<string, mixed> */
    private array $meta = [];

    private bool $truncated = false;

    private readonly float $startedAt;

    private readonly int $maxStatements;

    public function __construct(?float $startedAt = null, int $maxStatements = 200)
    {
        $requestTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;

        $this->startedAt = $startedAt ?? (is_numeric($requestTime) ? (float) $requestTime : microtime(true));
        $this->maxStatements = max(1, $maxStatements);
    }

    /**
     * Only the fingerprint is kept: literals are replaced by `?` before the statement is stored,
     * so bind values never reach the page or the headers.
     */
    public function record(string $sql, float $timeMs, string $source = 'db'): void
    {
        $this->tally($source, 1, $timeMs);

        $statement = self::fingerprint($sql);
        $key = $source . '|' . $statement;

        if (!isset($this->statements[$key])) {
            if (count($this->statements) >= $this->maxStatements) {
                $this->truncated = true;

                return;
            }

            $this->statements[$key] = [
                'sql' => self::shorten($statement),
                'source' => $source,
                'count' => 0,
                'timeMs' => 0.0,
                'maxMs' => 0.0,
            ];
        }

        $this->statements[$key]['count']++;
        $this->statements[$key]['timeMs'] += $timeMs;
        $this->statements[$key]['maxMs'] = max($this->statements[$key]['maxMs'], $timeMs);
    }

    public function add(string $source, int $count, float $timeMs): void
    {
        $this->tally($source, $count, $timeMs);
    }

    /**
     * @param  callable(): array{count?: int|float, timeMs?: int|float}  $resolver
     */
    public function addUsing(string $source, callable $resolver): void
    {
        $this->deferred[$source] = Closure::fromCallable($resolver);
    }

    public function mark(string $name, float $timeMs): void
    {
        $this->marks[$name] = ($this->marks[$name] ?? 0.0) + $timeMs;
    }

    /** @return callable(): void */
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

    /** @return SonarSnapshot */
    public function snapshot(): array
    {
        $sources = $this->sources;

        foreach ($this->deferred as $source => $resolver) {
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
            'queries' => [
                'count' => $count,
                'timeMs' => round($timeMs, 1),
                'sources' => $sources,
            ],
            'statements' => $this->topStatements(),
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
            'truncated' => $this->truncated,
        ];
    }

    /** @return list<SonarStatement> */
    public function topStatements(int $limit = 15): array
    {
        $statements = array_values($this->statements);

        usort($statements, static fn (array $a, array $b): int => [$b['count'], $b['timeMs']] <=> [$a['count'], $a['timeMs']]);

        return array_slice(array_map(static fn (array $statement): array => [
            'sql' => $statement['sql'],
            'source' => $statement['source'],
            'count' => $statement['count'],
            'timeMs' => round($statement['timeMs'], 1),
            'maxMs' => round($statement['maxMs'], 1),
        ], $statements), 0, $limit);
    }

    private function tally(string $source, int $count, float $timeMs): void
    {
        $counter = $this->sources[$source] ?? ['count' => 0, 'timeMs' => 0.0];

        $this->sources[$source] = [
            'count' => $counter['count'] + $count,
            'timeMs' => $counter['timeMs'] + $timeMs,
        ];
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
        return mb_strlen($sql) > $limit ? mb_substr($sql, 0, $limit) . '…' : $sql;
    }
}
