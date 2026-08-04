<?php

declare(strict_types=1);

namespace Sonar\Integration\Evolution;

use Sonar\Config;
use Sonar\Integration\Laravel\SonarServiceProvider;
use Sonar\Sonar;

/**
 * Evolution CMS 3 provider: adds the legacy query counters and injects the
 * overlay through OnWebPagePrerender, which also covers pages served from the
 * EVO page cache.
 */
class SonarEvolutionServiceProvider extends SonarServiceProvider
{
    public function register(): void
    {
        parent::register();

        if (!Sonar::collecting()) {
            return;
        }

        Sonar::lazy('evo', static fn (): array => [
            'count' => (int) (evo()->executedQueries ?? 0),
            'timeMs' => (float) (evo()->queryTime ?? 0) * 1000,
        ]);

        Sonar::meta('source', static fn (): string => (int) (evo()->documentGenerated ?? 1) === 0 ? 'cache' : 'database');

        $this->app->make('events')->listen('evolution.OnWebPagePrerender', static function (): void {
            evo()->documentOutput = Sonar::inject((string) evo()->documentOutput);
        });
    }

    protected function sonarConfig(): Config
    {
        $config = $this->app->make('config');

        return Config::fromValue(
            $config->get('sonar.enabled', false),
            static fn (): bool => PHP_SAPI !== 'cli' && evo()->isLoggedIn('mgr'),
            (int) $config->get('sonar.max_queries', 200)
        );
    }
}
