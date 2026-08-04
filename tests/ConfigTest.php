<?php

declare(strict_types=1);

namespace Hkyss\Sonar\Tests;

use PHPUnit\Framework\TestCase;
use Hkyss\Sonar\Config;

final class ConfigTest extends TestCase
{
    /** @dataProvider modes */
    public function testResolvesLooseValues(mixed $value, string $expected): void
    {
        self::assertSame($expected, Config::fromValue($value, static fn (): bool => true)->mode());
    }

    /** @return array<int, array{mixed, string}> */
    public static function modes(): array
    {
        return [
            [true, Config::MODE_ON],
            ['1', Config::MODE_ON],
            ['true', Config::MODE_ON],
            ['ON', Config::MODE_ON],
            ['gated', Config::MODE_GATED],
            ['manager', Config::MODE_GATED],
            [false, Config::MODE_OFF],
            ['0', Config::MODE_OFF],
            ['', Config::MODE_OFF],
            [null, Config::MODE_OFF],
        ];
    }

    public function testGatedWithoutGateIsOff(): void
    {
        self::assertSame(Config::MODE_OFF, Config::fromValue('gated')->mode());
    }

    public function testGateDecidesVisibility(): void
    {
        $allowed = false;
        $config = Config::fromValue('gated', static function () use (&$allowed): bool {
            return $allowed;
        });

        self::assertTrue($config->collecting());
        self::assertFalse($config->visible());

        $allowed = true;

        self::assertTrue($config->visible());
    }

    public function testOffCollectsNothing(): void
    {
        $config = Config::off();

        self::assertFalse($config->collecting());
        self::assertFalse($config->visible());
    }

    public function testReadsTheEnvironment(): void
    {
        $_ENV['SONAR'] = 'on';
        $_ENV['SONAR_MAX_QUERIES'] = '25';

        $config = Config::fromEnv();

        self::assertSame(Config::MODE_ON, $config->mode());
        self::assertSame(25, $config->maxQueries());

        unset($_ENV['SONAR'], $_ENV['SONAR_MAX_QUERIES']);
    }
}
