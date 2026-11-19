<?php

declare(strict_types=1);

namespace hkyss\Sonar\Tests\Integration;

use hkyss\Sonar\Config;
use hkyss\Sonar\Integration\Evolution\SonarEvolutionServiceProvider;
use hkyss\Sonar\Sonar;
use hkyss\Sonar\Tests\ReadsSnapshots;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

final class EvolutionQueryTimingTest extends TestCase
{
    use ReadsSnapshots;

    private FakeApplication $app;

    private Dispatcher $events;

    private DatabaseManager $db;

    protected function setUp(): void
    {
        Sonar::reset();
        Sonar::boot(Config::fromValue(true));

        $this->app = new FakeApplication();
        $this->events = new Dispatcher($this->app);
        $this->app->instance('events', $this->events);
        $this->app->instance('config', new ConfigRepository(['database' => [
            'default' => 'main',
            'connections' => [
                'main' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
                'reports' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            ],
        ]]));

        $this->db = new DatabaseManager($this->app, new ConnectionFactory($this->app));
        $this->app->instance('db', $this->db);

        $provider = new class ($this->app) extends SonarEvolutionServiceProvider {
            public function listen(Dispatcher $events): void
            {
                $this->listenToQueries($events);
            }
        };
        $provider->listen($this->events);
    }

    protected function tearDown(): void
    {
        Sonar::reset();
    }

    public function testAQueryOnAnotherConnectionKeepsItsMilliseconds(): void
    {
        $this->events->dispatch(new QueryExecuted('select 1', [], 2.0, $this->db->connection('reports')));

        self::assertSame(2.0, self::snapshot()['queries']['timeMs']);
        self::assertSame(1, self::snapshot()['queries']['sources']['eloquent']['count']);
    }

    public function testAQueryRunOnTheDefaultConnectionKeepsItsMilliseconds(): void
    {
        $connection = $this->db->connection();
        $this->events->dispatch(new QueryExecuted('select 1', [], 2.0, $connection));
        $connection->select('select 1');

        self::assertSame(2, self::snapshot()['queries']['count']);
    }

    public function testALegacyDriverQueryIsConvertedFromSeconds(): void
    {
        $this->events->dispatch(new QueryExecuted('select 1', [], 0.002, $this->db->connection()));

        self::assertSame(2.0, self::snapshot()['queries']['timeMs']);
        self::assertSame(1, self::snapshot()['queries']['sources']['evo']['count']);
    }
}
