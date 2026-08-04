# Writing an integration

Sonar has no plugin system, no interface to implement and no registry to
register with. An integration is whatever code calls the static facade at the
right moments. The four built-in ones — Laravel, Evolution CMS, PSR-15 and PDO —
are each under a hundred lines and do nothing you cannot do.

There are three jobs, and a system that only does one of them is still useful.

## 1. Decide whether to collect

```php
use Hkyss\Sonar\Config;
use Hkyss\Sonar\Sonar;

Sonar::boot(Config::fromEnv());
```

`boot()` returns `null` when the configuration says off, and every other call on
the facade becomes a no-op. Nothing downstream needs to check.

`Config::fromEnv()` reads `SONAR` and `SONAR_MAX_QUERIES`, and treats `APP_ENV`
of `production` as a reason to ignore `SONAR=true`. When the configuration lives
somewhere else, build it yourself:

```php
Sonar::boot(Config::fromValue(
    $settings->get('sonar'),          // false | true | 'gated'
    fn (): bool => $user->isAdmin(),  // the gate, consulted per request
    maxQueries: 200,
    production: $env === 'live',
));
```

Two rules are enforced for you: `gated` without a gate is off, and `true` in
production is off. There is deliberately no way to override them from
configuration — a system that wants an overlay on a live site uses `gated`.

### Long-running processes

`boot()` measures from `REQUEST_TIME_FLOAT`, which is right for one process per
request. If the process survives between requests — Octane, Swoole, RoadRunner,
FrankenPHP, a queue worker — call `Sonar::start()` at the boundary. It drops
what was collected and restarts the clock:

```php
$server->on('request', function ($request, $response) {
    Sonar::start();
    // ...
});
```

Call it as early as you can. Anything recorded before it is thrown away.

## 2. Report what happened

Three calls, chosen by how much your system knows.

**One statement at a time.** The usual case: hook whatever event or wrapper your
database layer gives you.

```php
Sonar::record($sql, $milliseconds, source: 'db');
```

Only the fingerprint is kept — literals are replaced by `?` before storage — so
you can pass interpolated SQL without leaking bind values onto the page.
Identical statements are grouped and counted.

**Totals only.** For a system that counts but cannot enumerate: a cache, an
HTTP client, a search index.

```php
Sonar::add('cache', $hits + $misses, $milliseconds);
```

**Totals read at the end.** When the number only exists once the request is
done, hand over a resolver instead. It runs when the snapshot is taken.

```php
Sonar::addUsing('redis', fn (): array => [
    'count' => $redis->info()['total_commands_processed'],
    'timeMs' => 0,
]);
```

`source` is a free-form string and becomes the label in the per-source
breakdown. Keep it short and lowercase: `db`, `cache`, `evo`, `redis`.

Nothing stops you calling `record()` and `add()` for the same source, and the
totals will include both. That is occasionally what you want — some statements
visible individually, the rest as a lump — but it is a silent double count if
you did not mean it.

**Timings and metadata** are independent of any source:

```php
Sonar::mark('template render', 38.6);

$stop = Sonar::timer('ssr');
$html = $renderer->render($view);
$stop();

Sonar::meta('route', $route->getName());
Sonar::meta('cache', fn (): string => $page->fromCache ? 'hit' : 'miss');
```

Marks with the same name add up. Metadata closures resolve at snapshot time,
which is how you report something decided late in the request.

## 3. Get it onto the page

Two outputs, and most integrations do both.

```php
// HTML responses: the overlay goes in before </body>
$html = Sonar::inject($html);

// everything else: the numbers travel as headers
foreach (Sonar::headers() as $name => $value) {
    $response->setHeader($name, $value);
}
```

Both are no-ops unless the overlay is visible, so there is no need to guard
them.

`Sonar::inject()` returns the HTML untouched when there is no `</body>` or when
an overlay is already there, which makes it safe to call from more than one
place. Add the header names to `Access-Control-Expose-Headers` if the page may
read them across origins — that is what lets the panel show a query count for a
cross-origin fetch:

```php
use Hkyss\Sonar\Headers;

$response->setHeader(
    'Access-Control-Expose-Headers',
    Headers::expose($response->getHeader('Access-Control-Expose-Headers'), Headers::names())
);
```

## A whole integration

Roughly what the PSR-15 one does, with the framework parts left generic:

```php
use Hkyss\Sonar\Config;
use Hkyss\Sonar\Headers;
use Hkyss\Sonar\Overlay;
use Hkyss\Sonar\Sonar;

final class SonarPlugin
{
    public function boot(Application $app): void
    {
        if (Sonar::boot(Config::fromEnv()) === null) {
            return;
        }

        $app->database()->on('query', static function (Query $query): void {
            Sonar::record($query->sql(), $query->durationMs());
        });

        $app->on('request', static fn () => Sonar::start());

        $app->on('response', static function (Response $response): void {
            if (Overlay::isHtml($response->contentType())) {
                $response->setBody(Sonar::inject($response->body()));
            }

            foreach (Sonar::headers() as $name => $value) {
                $response->setHeader($name, $value);
            }
        });
    }
}
```

## The snapshot

`Sonar::snapshot()` is the whole request as an array, and an empty array while
the collector is off. Anything that renders its own view of the data reads this:

```php
[
    'queries' => [
        'count' => 54,
        'timeMs' => 86.0,
        'sources' => [
            'db' => ['count' => 41, 'timeMs' => 79.3],
            'cache' => ['count' => 12, 'timeMs' => 4.3],
        ],
    ],
    'statements' => [
        ['sql' => 'select * from `products` where `id` = ?', 'source' => 'db', 'count' => 38, 'timeMs' => 53.2, 'maxMs' => 4.1],
    ],
    'time' => ['totalMs' => 412.0, 'phpMs' => 326.0],
    'memory' => ['peakMb' => 2.0],
    'marks' => ['template render' => 38.6],
    'meta' => ['route' => 'products.show'],
    'truncated' => false,
]
```

`queries` is the total across every source; `statements` is the individual
statements, fingerprinted, grouped and capped at `max_queries` distinct entries
(`truncated` says whether the cap was hit). The shape is written down as a
PHPStan type in `Hkyss\Sonar\Collector`, so a static analyser will hold you to it.

## Testing yours

`Sonar::reset()` clears the collector and the configuration, which is what test
setup needs. The suites under `tests/Integration` are the shortest examples of
driving an integration end to end.
