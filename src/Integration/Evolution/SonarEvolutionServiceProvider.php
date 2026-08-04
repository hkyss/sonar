<?php

declare(strict_types=1);

namespace Hkyss\Sonar\Integration\Evolution;

use Hkyss\Sonar\Config;
use Hkyss\Sonar\Integration\Laravel\SonarServiceProvider;
use Hkyss\Sonar\Sonar;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
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

        if (Sonar::collector() === null) {
            return;
        }

        Sonar::meta('document', static fn (): int => (int) evo()->documentIdentifier);
        Sonar::meta('source', static fn (): string => (int) (evo()->documentGenerated ?? 1) === 0 ? 'cache' : 'database');

        /** @var Dispatcher $events */
        $events = $this->app->make('events');

        $events->listen('evolution.OnWebPagePrerender', static function (): void {
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
            /** @var DatabaseManager $db */
            $db = $this->app->make('db');
            $db->connection()->beforeExecuting(static fn () => $origin->mark());
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
        /** @var Repository $config */
        $config = $this->app->make('config');

        return Config::fromValue(
            $config->get('sonar.enabled', false),
            static fn (): bool => PHP_SAPI !== 'cli' && evo()->isLoggedIn('mgr'),
            self::maxQueries($config),
            $this->inProduction()
        );
    }
}
