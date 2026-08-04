<?php

declare(strict_types=1);

namespace Hkyss\Sonar\Tests;

use Hkyss\Sonar\Config;
use Hkyss\Sonar\Sonar;
use PHPUnit\Framework\TestCase;

final class SonarTest extends TestCase
{
    use ReadsSnapshots;

    protected function setUp(): void
    {
        Sonar::reset();
    }

    protected function tearDown(): void
    {
        Sonar::reset();
    }

    public function testIsANoOpUntilBooted(): void
    {
        Sonar::record('select 1', 1.0);
        Sonar::mark('ssr', 10.0);
        Sonar::timer('render')();

        self::assertFalse(Sonar::collecting());
        self::assertFalse(Sonar::visible());
        self::assertSame([], Sonar::snapshot());
        self::assertSame([], Sonar::headers());
        self::assertSame('<body></body>', Sonar::inject('<body></body>'));
    }

    public function testBootingOffKeepsItSilent(): void
    {
        Sonar::boot(Config::off());

        Sonar::record('select 1', 1.0);

        self::assertNull(Sonar::collector());
        self::assertSame([], Sonar::snapshot());
    }

    public function testCollectsAndRendersWhenOn(): void
    {
        Sonar::boot(Config::fromValue(true));
        Sonar::record('select 1', 2.0);

        self::assertTrue(Sonar::visible());
        self::assertSame(1, self::snapshot()['queries']['count']);
        self::assertStringContainsString('id="sonar-data"', Sonar::inject('<html><body></body></html>'));
    }

    public function testCollectsButStaysHiddenBehindTheGate(): void
    {
        $allowed = false;
        Sonar::boot(Config::fromValue('gated', static function () use (&$allowed): bool {
            return $allowed;
        }));

        Sonar::record('select 1', 2.0);

        self::assertTrue(Sonar::collecting());
        self::assertSame([], Sonar::headers());
        self::assertSame('<body></body>', Sonar::inject('<body></body>'));

        $allowed = true;

        self::assertArrayHasKey('X-Sonar-Queries', Sonar::headers());
    }

    public function testKeepsTheCollectorAcrossRepeatedBoots(): void
    {
        Sonar::boot(Config::fromValue(true));
        Sonar::record('select 1', 2.0);
        Sonar::boot(Config::fromValue(true));

        self::assertSame(1, self::snapshot()['queries']['count']);
    }

    public function testStartOpensAFreshRequest(): void
    {
        Sonar::boot(Config::fromValue(true));
        Sonar::record('select 1', 2.0);

        Sonar::start();

        self::assertTrue(Sonar::collecting());
        self::assertSame(0, self::snapshot()['queries']['count']);
    }

    public function testStartRestartsTheClock(): void
    {
        Sonar::boot(Config::fromValue(true));
        $before = self::snapshot()['time']['totalMs'];

        Sonar::start();

        self::assertLessThanOrEqual($before, self::snapshot()['time']['totalMs']);
    }

    public function testStartStaysSilentWhileOff(): void
    {
        Sonar::boot(Config::off());

        self::assertNull(Sonar::start());
        self::assertFalse(Sonar::collecting());
    }

    public function testReportsHeadersFromTheSnapshot(): void
    {
        Sonar::boot(Config::fromValue(true));
        Sonar::record('select 1', 4.0);
        Sonar::record('select 2', 6.0);

        $headers = Sonar::headers();

        self::assertSame('2', $headers['X-Sonar-Queries']);
        self::assertSame('10', $headers['X-Sonar-Query-Time']);
        self::assertArrayHasKey('X-Sonar-Time', $headers);
        self::assertArrayHasKey('X-Sonar-Memory', $headers);
    }
}
