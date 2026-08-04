<?php

declare(strict_types=1);

namespace Hkyss\Sonar\Tests;

use Hkyss\Sonar\Headers;
use PHPUnit\Framework\TestCase;

final class HeadersTest extends TestCase
{
    public function testEmptySnapshotProducesNoHeaders(): void
    {
        self::assertSame([], Headers::fromSnapshot([]));
    }

    public function testHonoursACustomPrefix(): void
    {
        $headers = Headers::fromSnapshot([
            'queries' => ['count' => 3, 'timeMs' => 1.5],
            'time' => ['totalMs' => 20.0],
            'memory' => ['peakMb' => 8.0],
        ], 'X-Perf-');

        self::assertSame('3', $headers['X-Perf-Queries']);
        self::assertSame(['X-Perf-Queries', 'X-Perf-Query-Time', 'X-Perf-Time', 'X-Perf-Memory'], Headers::names('X-Perf-'));
    }

    public function testExposeKeepsWhatWasAlreadyThere(): void
    {
        $exposed = Headers::expose('X-Total-Count', Headers::names());

        self::assertStringContainsString('X-Total-Count', $exposed);
        self::assertStringContainsString('X-Sonar-Queries', $exposed);
    }

    public function testExposeDoesNotRepeatItself(): void
    {
        $exposed = Headers::expose('X-Sonar-Time', Headers::names());

        self::assertSame(1, substr_count($exposed, 'X-Sonar-Time'));
    }
}
