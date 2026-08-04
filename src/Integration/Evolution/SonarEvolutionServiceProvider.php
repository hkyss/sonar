<?php

declare(strict_types=1);

namespace Hkyss\Sonar\Integration\Evolution;

use Hkyss\Sonar\Config;
use Hkyss\Sonar\Integration\Laravel\SonarServiceProvider;
use Hkyss\Sonar\Sonar;
use Illuminate\Database\Events\QueryExecuted;

/**
 * Evolution CMS 3 provider: injects through OnWebPagePrerender, which also
 * covers pages served from the EVO page cache, and gates on a manager login.
 */
class SonarEvolutionServiceProvider extends SonarServiceProvider
{
    public function register(): void
    {
        if (Sonar::collecting()) {
            return;
        }

        parent::register();

        if (!Sonar::collecting()) {
            return;
        }

        Sonar::meta('document', static fn (): int => (int) evo()->documentIdentifier);
        Sonar::meta('source', static fn (): string => (int) (evo()->documentGenerated ?? 1) === 0 ? 'cache' : 'database');

        $this->app->make('events')->listen('evolution.OnWebPagePrerender', static function (): void {
            evo()->documentOutput = Sonar::inject((string) evo()->documentOutput);
        });
    }

    /**
     * EVO's legacy driver reports its timings in seconds, Illuminate in
     * milliseconds; queries are labelled and normalised accordingly.
     */
    protected function listenToQueries(mixed $events): void
    {
        $origin = new QueryOrigin();

        if ($this->app->bound('db')) {
            $this->app->make('db')->connection()->beforeExecuting(static fn () => $origin->mark());
        }

        $events->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($origin): void {
            $illuminate = $origin->take();

            Sonar::record(
                $event->sql,
                $illuminate ? (float) $event->time : (float) $event->time * 1000,
                $illuminate ? 'eloquent' : 'evo'
            );
        });
    }

    protected function sonarConfig(): Config
    {
        $config = $this->app->make('config');

        return Config::fromValue(
            $config->get('sonar.enabled', false),
            static fn (): bool => PHP_SAPI !== 'cli' && evo()->isLoggedIn('mgr'),
            (int) $config->get('sonar.max_queries', 200),
            $this->inProduction()
        );
    }
}
