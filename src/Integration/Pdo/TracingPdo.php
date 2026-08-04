<?php

declare(strict_types=1);

namespace Hkyss\Sonar\Integration\Pdo;

use PDO;
use PDOStatement;
use Hkyss\Sonar\Sonar;

/** Drop-in PDO that reports every statement it runs. */
final class TracingPdo extends PDO
{
    private string $source = 'pdo';

    /** @param array<int, mixed>|null $options */
    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null, string $source = 'pdo')
    {
        parent::__construct($dsn, $username, $password, $options ?? []);

        $this->source = $source;
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [TracingStatement::class, [$source]]);
    }

    public function exec(string $statement): int|false
    {
        $startedAt = microtime(true);

        try {
            return parent::exec($statement);
        } finally {
            Sonar::record($statement, (microtime(true) - $startedAt) * 1000, $this->source);
        }
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $startedAt = microtime(true);

        try {
            return parent::query($query, $fetchMode, ...$fetchModeArgs);
        } finally {
            Sonar::record($query, (microtime(true) - $startedAt) * 1000, $this->source);
        }
    }
}
