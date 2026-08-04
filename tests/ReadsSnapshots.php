<?php

declare(strict_types=1);

namespace Hkyss\Sonar\Tests;

use Hkyss\Sonar\Sonar;

/**
 * Sonar::snapshot() is an empty array while the collector is off, so a test
 * that reads a figure out of it has to say it expected one.
 *
 * @phpstan-import-type SonarSnapshot from \Hkyss\Sonar\Collector
 */
trait ReadsSnapshots
{
    /** @return SonarSnapshot */
    protected static function snapshot(): array
    {
        $snapshot = Sonar::snapshot();

        if ($snapshot === []) {
            self::fail('expected a snapshot, but the collector is off');
        }

        return $snapshot;
    }
}
