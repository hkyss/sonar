<?php

declare(strict_types=1);

namespace Sonar\Integration\Pdo;

use PDOStatement;
use Sonar\Sonar;

final class TracingStatement extends PDOStatement
{
    private function __construct(private readonly string $source = 'pdo')
    {
    }

    /** @param array<mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        $startedAt = microtime(true);

        try {
            return parent::execute($params);
        } finally {
            Sonar::record($this->queryString, (microtime(true) - $startedAt) * 1000, $this->source);
        }
    }
}
