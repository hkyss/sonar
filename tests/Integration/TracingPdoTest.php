<?php

declare(strict_types=1);

namespace hkyss\Sonar\Tests\Integration;

use hkyss\Sonar\Config;
use hkyss\Sonar\Integration\Pdo\TracingPdo;
use hkyss\Sonar\Sonar;
use hkyss\Sonar\Tests\ReadsSnapshots;
use PHPUnit\Framework\TestCase;

final class TracingPdoTest extends TestCase
{
    use ReadsSnapshots;

    private TracingPdo $pdo;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not available');
        }

        Sonar::reset();
        Sonar::boot(Config::fromValue(true));

        $this->pdo = new TracingPdo('sqlite::memory:');
        $this->pdo->exec('create table users (id integer primary key, name text)');
    }

    protected function tearDown(): void
    {
        Sonar::reset();
    }

    public function testRecordsExecAndQuery(): void
    {
        $this->pdo->query('select * from users');

        $snapshot = self::snapshot();

        self::assertSame(2, $snapshot['queries']['count']);
        self::assertSame(2, $snapshot['queries']['sources']['pdo']['count']);
    }

    public function testRecordsPreparedStatements(): void
    {
        $statement = $this->pdo->prepare('insert into users (name) values (?)');
        $statement->execute(['first']);
        $statement->execute(['second']);

        $statements = array_values(array_filter(
            self::snapshot()['statements'],
            static fn (array $statement): bool => str_starts_with($statement['sql'], 'insert')
        ));

        self::assertSame(2, $statements[0]['count']);
    }
}
