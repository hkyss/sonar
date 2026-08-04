<?php

declare(strict_types=1);

namespace Sonar\Tests;

use PHPUnit\Framework\TestCase;
use Sonar\Collector;

final class CollectorTest extends TestCase
{
    public function testGroupsQueriesOfTheSameShape(): void
    {
        $collector = new Collector();
        $collector->record('select * from `pages` where `id` = 12', 1.5);
        $collector->record('select * from `pages` where `id` = 34', 2.5);
        $collector->record('select * from `pages` where `id` = 56', 1.0);

        $queries = $collector->topQueries();

        self::assertCount(1, $queries);
        self::assertSame(3, $queries[0]['count']);
        self::assertSame(5.0, $queries[0]['timeMs']);
        self::assertSame(2.5, $queries[0]['maxMs']);
    }

    public function testKeepsDifferentShapesApart(): void
    {
        $collector = new Collector();
        $collector->record('select * from `a` where `id` = 1', 1.0);
        $collector->record('select * from `b` where `id` = 1', 1.0);

        self::assertCount(2, $collector->topQueries());
    }

    public function testCollapsesInLists(): void
    {
        $collector = new Collector();
        $collector->record('select * from `a` where `id` in (1, 2, 3)', 1.0);
        $collector->record('select * from `a` where `id` in (4, 5)', 1.0);

        self::assertCount(1, $collector->topQueries());
    }

    public function testKeepsSourcesApart(): void
    {
        $collector = new Collector();
        $collector->record('select 1', 1.0, 'db');
        $collector->record('select 1', 1.0, 'pdo');

        $snapshot = $collector->snapshot();

        self::assertCount(2, $snapshot['queries']);
        self::assertSame(1, $snapshot['db']['sources']['db']['count']);
        self::assertSame(1, $snapshot['db']['sources']['pdo']['count']);
    }

    public function testSumsRecordedAggregateAndLazySources(): void
    {
        $collector = new Collector();
        $collector->record('select 1', 10.0, 'db');
        $collector->add('cache', 3, 5.0);
        $collector->lazy('evo', static fn (): array => ['count' => 8, 'timeMs' => 50.0]);

        $snapshot = $collector->snapshot();

        self::assertSame(12, $snapshot['db']['count']);
        self::assertSame(65.0, $snapshot['db']['timeMs']);
        self::assertSame(8, $snapshot['db']['sources']['evo']['count']);
    }

    public function testResolvesLazyMetaAtSnapshotTime(): void
    {
        $collector = new Collector();
        $value = 'database';
        $collector->meta('source', static function () use (&$value): string {
            return $value;
        });

        $value = 'cache';

        self::assertSame('cache', $collector->snapshot()['meta']['source']);
    }

    public function testSumsRepeatedMarks(): void
    {
        $collector = new Collector();
        $collector->mark('ssr', 10.0);
        $collector->mark('ssr', 5.5);

        self::assertSame(15.5, $collector->snapshot()['marks']['ssr']);
    }

    public function testTimerStoresElapsedTime(): void
    {
        $collector = new Collector();
        $stop = $collector->timer('render');
        usleep(2000);
        $stop();

        self::assertGreaterThan(1.0, $collector->snapshot()['marks']['render']);
    }

    public function testStopsStoringDistinctQueriesAtTheCap(): void
    {
        $collector = new Collector(null, 2);
        $collector->record('select * from `a`', 1.0);
        $collector->record('select * from `b`', 1.0);
        $collector->record('select * from `c`', 1.0);

        $snapshot = $collector->snapshot();

        self::assertCount(2, $snapshot['queries']);
        self::assertTrue($snapshot['truncated']);
        self::assertSame(3, $snapshot['db']['count']);
    }

    public function testMeasuresTotalTimeFromTheGivenStart(): void
    {
        $collector = new Collector(microtime(true) - 0.5);

        self::assertGreaterThanOrEqual(500.0, $collector->snapshot()['time']['totalMs']);
    }
}
