<?php

declare(strict_types=1);

namespace hkyss\Sonar\Tests;

use hkyss\Sonar\Sonar;

/**
 * Sonar::snapshot() is an empty array while the collector is off.
 *
 * @phpstan-import-type SonarSnapshot from \hkyss\Sonar\Collector
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
