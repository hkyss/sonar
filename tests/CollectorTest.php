<?php

declare(strict_types=1);

namespace Hkyss\Sonar\Tests;

use Hkyss\Sonar\Collector;
use PHPUnit\Framework\TestCase;

final class CollectorTest extends TestCase
{
    public function testGroupsQueriesOfTheSameShape(): void
    {
        $collector = new Collector();
        $collector->record('select * from `pages` where `id` = 12', 1.5);
        $collector->record('select * from `pages` where `id` = 34', 2.5);
        $collector->record('select * from `pages` where `id` = 56', 1.0);

        $statements = $collector->topStatements();

        self::assertCount(1, $statements);
        self::assertSame(3, $statements[0]['count']);
        self::assertSame(5.0, $statements[0]['timeMs']);
        self::assertSame(2.5, $statements[0]['maxMs']);
    }

    public function testKeepsOnlyTheFingerprintOfAStatement(): void
    {
        $collector = new Collector();
        $collector->record("select * from `users` where `email` = 'ada@example.com' and `id` = 7", 1.0);

        $statement = $collector->topStatements()[0]['sql'];

        self::assertSame('select * from `users` where `email` = ? and `id` = ?', $statement);
        self::assertStringNotContainsString('ada@example.com', json_encode($collector->snapshot()) ?: '');
    }

    public function testKeepsDifferentShapesApart(): void
    {
        $collector = new Collector();
        $collector->record('select * from `a` where `id` = 1', 1.0);
        $collector->record('select * from `b` where `id` = 1', 1.0);

        self::assertCount(2, $collector->topStatements());
    }

    public function testCollapsesInLists(): void
    {
        $collector = new Collector();
        $collector->record('select * from `a` where `id` in (1, 2, 3)', 1.0);
        $collector->record('select * from `a` where `id` in (4, 5)', 1.0);

        self::assertCount(1, $collector->topStatements());
    }

    public function testKeepsSourcesApart(): void
    {
        $collector = new Collector();
        $collector->record('select 1', 1.0, 'db');
        $collector->record('select 1', 1.0, 'pdo');

        $snapshot = $collector->snapshot();

        self::assertCount(2, $snapshot['statements']);
        self::assertSame(1, $snapshot['queries']['sources']['db']['count']);
        self::assertSame(1, $snapshot['queries']['sources']['pdo']['count']);
    }

    public function testSumsRecordedAggregateAndDeferredSources(): void
    {
        $collector = new Collector();
        $collector->record('select 1', 10.0, 'db');
        $collector->add('cache', 3, 5.0);
        $collector->addUsing('evo', static fn (): array => ['count' => 8, 'timeMs' => 50.0]);

        $snapshot = $collector->snapshot();

        self::assertSame(12, $snapshot['queries']['count']);
        self::assertSame(65.0, $snapshot['queries']['timeMs']);
        self::assertSame(8, $snapshot['queries']['sources']['evo']['count']);
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

    public function testStopsStoringDistinctStatementsAtTheCap(): void
    {
        $collector = new Collector(null, 2);
        $collector->record('select * from `a`', 1.0);
        $collector->record('select * from `b`', 1.0);
        $collector->record('select * from `c`', 1.0);

        $snapshot = $collector->snapshot();

        self::assertCount(2, $snapshot['statements']);
        self::assertTrue($snapshot['truncated']);
        self::assertSame(3, $snapshot['queries']['count']);
    }

    public function testMeasuresTotalTimeFromTheGivenStart(): void
    {
        $collector = new Collector(microtime(true) - 0.5);

        self::assertGreaterThanOrEqual(500.0, $collector->snapshot()['time']['totalMs']);
    }
}
