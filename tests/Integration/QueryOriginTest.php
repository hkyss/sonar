<?php

declare(strict_types=1);

namespace hkyss\Sonar\Tests\Integration;

use hkyss\Sonar\Integration\Evolution\QueryOrigin;
use PHPUnit\Framework\TestCase;

final class QueryOriginTest extends TestCase
{
    public function testAnUnmarkedQueryIsLegacy(): void
    {
        self::assertFalse((new QueryOrigin())->take());
    }

    public function testAMarkIsConsumedOnce(): void
    {
        $origin = new QueryOrigin();
        $origin->mark();

        self::assertTrue($origin->take());
        self::assertFalse($origin->take());
    }

    public function testAnUnmarkedQueryOnTheDefaultConnectionIsLegacy(): void
    {
        self::assertTrue((new QueryOrigin())->legacy('main', 'main'));
    }

    public function testAnUnmarkedQueryOnAnotherConnectionIsNotLegacy(): void
    {
        self::assertFalse((new QueryOrigin())->legacy('reports', 'main'));
    }

    public function testAMarkedQueryIsNotLegacy(): void
    {
        $origin = new QueryOrigin();
        $origin->mark();

        self::assertFalse($origin->legacy('main', 'main'));
    }
}
