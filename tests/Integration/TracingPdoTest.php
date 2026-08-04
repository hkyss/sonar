<?php

declare(strict_types=1);

namespace Hkyss\Sonar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Hkyss\Sonar\Config;
use Hkyss\Sonar\Integration\Pdo\TracingPdo;
use Hkyss\Sonar\Sonar;

final class TracingPdoTest extends TestCase
{
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

        $snapshot = Sonar::snapshot();

        self::assertSame(2, $snapshot['db']['count']);
        self::assertSame(2, $snapshot['db']['sources']['pdo']['count']);
    }

    public function testRecordsPreparedStatements(): void
    {
        $statement = $this->pdo->prepare('insert into users (name) values (?)');
        $statement->execute(['first']);
        $statement->execute(['second']);

        $queries = array_values(array_filter(
            Sonar::snapshot()['queries'],
            static fn (array $query): bool => str_starts_with($query['sql'], 'insert')
        ));

        self::assertSame(2, $queries[0]['count']);
    }
}
