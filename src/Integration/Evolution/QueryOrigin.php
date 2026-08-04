<?php

declare(strict_types=1);

namespace hkyss\Sonar\Integration\Evolution;

/**
 * Tells an Illuminate-driven query from a legacy `evo()->db` one.
 *
 * Only `Connection::run()` fires the beforeExecuting callbacks; the legacy
 * driver prepares its own statement and calls `logQuery()` directly, so a
 * QueryExecuted event that arrives without a mark came from EVO.
 */
final class QueryOrigin
{
    private bool $illuminate = false;

    public function mark(): void
    {
        $this->illuminate = true;
    }

    public function take(): bool
    {
        $illuminate = $this->illuminate;
        $this->illuminate = false;

        return $illuminate;
    }
}
