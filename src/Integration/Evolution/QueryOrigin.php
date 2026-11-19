<?php

declare(strict_types=1);

namespace hkyss\Sonar\Integration\Evolution;

/**
 * Only `Connection::run()` fires the beforeExecuting callbacks, so a QueryExecuted event that
 * arrives without a mark came from EVO's legacy driver, which reports on the default connection only.
 */
final class QueryOrigin
{
    private bool $illuminate = false;

    public function mark(): void
    {
        $this->illuminate = true;
    }

    public function legacy(string $connection, string $default): bool
    {
        return !$this->take() && $connection === $default;
    }

    public function take(): bool
    {
        $illuminate = $this->illuminate;
        $this->illuminate = false;

        return $illuminate;
    }
}
