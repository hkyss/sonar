<?php

declare(strict_types=1);

namespace Hkyss\Sonar\Tests\Integration;

use Hkyss\Sonar\Integration\Evolution\QueryOrigin;
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
}
