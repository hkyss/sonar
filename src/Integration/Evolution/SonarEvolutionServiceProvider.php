<?php

declare(strict_types=1);

namespace hkyss\Sonar\Integration\Evolution;

use hkyss\Sonar\Config;
use hkyss\Sonar\Integration\Laravel\SonarServiceProvider;
use hkyss\Sonar\Overlay;
use hkyss\Sonar\Sonar;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;

/**
 * Evolution CMS 3 provider: injects through OnWebPagePrerender, which also
 * covers pages served from the EVO page cache, and gates on a manager login and
 * on the document's own content type.
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
            if (!Overlay::isHtml(self::documentContentType())) {
                return;
            }

            // The event fires once per rendered document, so what it carries is a
            // whole page — including the ones whose template spells no body tag,
            // which a fresh installation serves until it is given templates of
            // its own.
            evo()->documentOutput = Sonar::injectPage((string) evo()->documentOutput);
        });
    }

    /**
     * A document declares its own content type, and the ones that are not HTML —
     * a sitemap, a feed, anything built from a template and served as XML — reach
     * the event like every other page. Read the way Evolution reads it when it
     * sends the header: an empty field means a page.
     */
    private static function documentContentType(): string
    {
        $type = evo()->documentObject['contentType'] ?? '';

        return is_string($type) && $type !== '' ? $type : 'text/html';
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
