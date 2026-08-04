<?php

declare(strict_types=1);

namespace Hkyss\Sonar\Integration\Laravel;

use Hkyss\Sonar\Config;
use Hkyss\Sonar\Sonar;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestReceived;

class SonarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../../config/sonar.php', 'sonar');

        if (Sonar::collecting() || Sonar::boot($this->sonarConfig()) === null) {
            return;
        }

        $events = $this->app->make('events');

        $this->listenToQueries($events);

        $events->listen(
            RouteMatched::class,
            static fn (RouteMatched $event) => $event->route->middleware(SonarMiddleware::class)
        );

        $this->listenToRequestBoundary($events);
    }

    /**
     * Octane keeps the worker alive between requests, so the collector has to
     * be reopened per request. Under PHP-FPM this event never fires.
     */
    protected function listenToRequestBoundary(mixed $events): void
    {
        if (class_exists(RequestReceived::class)) {
            $events->listen(RequestReceived::class, static fn () => Sonar::start());
        }
    }

    protected function listenToQueries(mixed $events): void
    {
        $events->listen(
            QueryExecuted::class,
            static fn (QueryExecuted $event) => Sonar::record($event->sql, (float) $event->time, 'db')
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../../../config/sonar.php' => $this->configPath()], 'sonar-config');
        }
    }

    protected function sonarConfig(): Config
    {
        $config = $this->app->make('config');

        return Config::fromValue(
            $config->get('sonar.enabled', false),
            $this->gate(),
            (int) $config->get('sonar.max_queries', 200),
            $this->inProduction()
        );
    }

    /**
     * There is no default gate. Laravel has no universal notion of an
     * administrator, and falling back to "any authenticated user" would hand
     * the schema to anyone who can register. Without a callable sonar.gate,
     * `gated` collapses to off.
     *
     * @return (callable(): bool)|null
     */
    protected function gate(): ?callable
    {
        $ability = $this->app->make('config')->get('sonar.gate');

        if (!is_callable($ability)) {
            return null;
        }

        return fn (): bool => (bool) $ability($this->app);
    }

    protected function inProduction(): bool
    {
        return method_exists($this->app, 'environment')
            ? (bool) $this->app->environment('production')
            : Config::isProduction();
    }

    protected function configPath(): string
    {
        return function_exists('config_path') ? config_path('sonar.php') : $this->app->basePath('config/sonar.php');
    }
}
