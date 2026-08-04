<?php

declare(strict_types=1);

namespace Sonar\Tests;

use PHPUnit\Framework\TestCase;
use Sonar\Config;
use Sonar\Sonar;

final class SonarTest extends TestCase
{
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
        self::assertSame(1, Sonar::snapshot()['db']['count']);
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

        self::assertSame(1, Sonar::snapshot()['db']['count']);
    }

    public function testReportsHeadersFromTheSnapshot(): void
    {
        Sonar::boot(Config::fromValue(true));
        Sonar::record('select 1', 4.0);
        Sonar::record('select 2', 6.0);

        $headers = Sonar::headers();

        self::assertSame('2', $headers['X-Sonar-Queries']);
        self::assertSame('10', $headers['X-Sonar-Db-Time']);
        self::assertArrayHasKey('X-Sonar-Time', $headers);
        self::assertArrayHasKey('X-Sonar-Memory', $headers);
    }
}
