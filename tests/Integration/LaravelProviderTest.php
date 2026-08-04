<?php

declare(strict_types=1);

namespace Sonar\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use PDO;
use PHPUnit\Framework\TestCase;
use Sonar\Integration\Laravel\SonarServiceProvider;
use Sonar\Sonar;

final class LaravelProviderTest extends TestCase
{
    private Container $app;

    private Dispatcher $events;

    protected function setUp(): void
    {
        Sonar::reset();

        $this->app = new Container();
        $this->events = new Dispatcher($this->app);
        $this->app->instance('events', $this->events);
        $this->app->instance('config', new ConfigRepository(['sonar' => ['enabled' => true]]));
    }

    protected function tearDown(): void
    {
        Sonar::reset();
        Container::setInstance(null);
    }

    public function testWiresTheQueryListenerOnce(): void
    {
        (new SonarServiceProvider($this->app))->register();
        (new SonarServiceProvider($this->app))->register();

        self::assertCount(1, $this->events->getListeners(QueryExecuted::class));
    }

    public function testDoesNotWireAnythingWhenDisabled(): void
    {
        $this->app->instance('config', new ConfigRepository(['sonar' => ['enabled' => false]]));

        (new SonarServiceProvider($this->app))->register();

        self::assertFalse(Sonar::collecting());
        self::assertSame([], $this->events->getListeners(QueryExecuted::class));
    }

    public function testRecordsADispatchedQuery(): void
    {
        (new SonarServiceProvider($this->app))->register();

        $this->events->dispatch(new QueryExecuted('select 1', [], 3.5, new Connection(new PDO('sqlite::memory:'))));

        self::assertSame(1, Sonar::snapshot()['db']['count']);
        self::assertSame(3.5, Sonar::snapshot()['db']['timeMs']);
    }
}
