<?php

declare(strict_types=1);

namespace Sonar\Integration\Laravel;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\ServiceProvider;
use Sonar\Config;
use Sonar\Sonar;

class SonarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../../config/sonar.php', 'sonar');

        if (Sonar::boot($this->sonarConfig()) === null) {
            return;
        }

        $events = $this->app->make('events');

        $events->listen(
            QueryExecuted::class,
            static fn (QueryExecuted $event) => Sonar::record($event->sql, (float) $event->time, 'db')
        );

        $events->listen(
            RouteMatched::class,
            static fn (RouteMatched $event) => $event->route->middleware(SonarMiddleware::class)
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
            (int) $config->get('sonar.max_queries', 200)
        );
    }

    /** @return callable(): bool */
    protected function gate(): callable
    {
        $config = $this->app->make('config');

        return function () use ($config): bool {
            $ability = $config->get('sonar.gate');

            if (is_callable($ability)) {
                return (bool) $ability($this->app);
            }

            return $this->app->bound('auth') && (bool) $this->app->make('auth')->check();
        };
    }

    protected function configPath(): string
    {
        return function_exists('config_path') ? config_path('sonar.php') : $this->app->basePath('config/sonar.php');
    }
}
