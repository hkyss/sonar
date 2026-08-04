# Sonar

Request performance overlay for PHP applications. Renders a small pill in the corner of the page: database queries, server timings, browser metrics, and every XHR/fetch the page makes with its own server-side query count.

- Framework-agnostic core, integrations for Laravel, Evolution CMS 3, PSR-15 and plain PDO.
- No dependencies, no build step, no published assets: CSS and JS are inlined before `</body>`.
- Off by default; when off, nothing is collected and nothing is rendered.

## Install

```bash
composer require --dev hkyss/sonar
```

## Enable

`SONAR` accepts `false` (off), `true` (visible to everyone — local only) or `gated` (collected always, shown only to whoever the gate allows).

```dotenv
SONAR=true
SONAR_MAX_QUERIES=200
```

## Laravel

The service provider is auto-discovered. It listens for `QueryExecuted`, attaches `SonarMiddleware` to every matched route, injects the overlay into HTML responses and adds `X-Sonar-*` headers to the rest.

```bash
php artisan vendor:publish --tag=sonar-config
```

```php
// config/sonar.php
return [
    'enabled' => env('SONAR', false),
    'max_queries' => 200,
    'gate' => fn ($app) => $app['auth']->user()?->isAdmin() ?? false,
];
```

With `'enabled' => 'gated'` and no `gate`, any authenticated user sees the overlay.

## Evolution CMS 3

Register the EVO provider instead of the Laravel one — it injects through `OnWebPagePrerender`, which also covers pages served from the EVO page cache, and gates on a manager login.

```php
// core/custom/config/app/providers/SonarServiceProvider.php
<?php return \Sonar\Integration\Evolution\SonarEvolutionServiceProvider::class;
```

Set `SONAR=gated` in `core/custom/.env` or in the web server environment (`env[SONAR]` in the php-fpm pool, `SetEnv SONAR gated` for mod_php).

Queries are split into two sources. EVO 3.1 runs its legacy `evo()->db` API on an Illuminate connection but calls `logQuery()` with **seconds** where Illuminate reports **milliseconds**; the integration tells the two apart by `Connection::beforeExecuting`, which only fires for Illuminate-driven queries, and normalises the legacy timings. `evo()->executedQueries` is not used — EVO never increments it.

Registration is idempotent: a package chain where several providers extend one another and each registers Sonar wires the listeners once.

## PSR-15

```php
use Sonar\Config;
use Sonar\Integration\Psr15\SonarMiddleware;
use Sonar\Sonar;

Sonar::boot(Config::fromEnv());

$pipeline->pipe(new SonarMiddleware($streamFactory));
```

## Plain PHP

```php
use Sonar\Config;
use Sonar\Integration\Pdo\TracingPdo;
use Sonar\Sonar;

Sonar::boot(Config::fromValue(true));

$pdo = new TracingPdo('mysql:host=localhost;dbname=app', $user, $password);

echo Sonar::inject($html);
```

## API

| Call | Purpose |
| --- | --- |
| `Sonar::boot(?Config)` | Start collecting; returns `null` when disabled |
| `Sonar::record($sql, $ms, $source)` | Log one statement |
| `Sonar::add($source, $count, $ms)` | Log totals for a source that cannot report statements |
| `Sonar::lazy($source, $resolver)` | Same, resolved at snapshot time |
| `Sonar::mark($name, $ms)` / `Sonar::timer($name)` | Named timing, e.g. SSR or a template render |
| `Sonar::meta($key, $value)` | Extra row in the panel; a closure is resolved at snapshot time |
| `Sonar::snapshot()` | Everything collected, as an array |
| `Sonar::headers()` | `X-Sonar-*` headers for the current request |
| `Sonar::inject($html)` | Overlay inserted before `</body>` |

Identical statements are fingerprinted (literals replaced by `?`) and collapsed into one row with a counter, so an N+1 reads as `×40` instead of forty lines.

## Reading the overlay

| Row | Meaning |
| --- | --- |
| `Queries` | Total across all sources, broken down per source |
| `Database` / `PHP` / `Total` | Wall clock split between SQL and everything else |
| `Requests` | XHR and fetch calls, each with its own server-side query count from the response headers |
| `Repeated queries` | Same statement run more than once |

Client metrics (TTFB, DOMContentLoaded, Load, FCP, LCP) come from the Navigation Timing and Performance Observer APIs.

## Notes

- The overlay script is a classic inline script, so it wraps `fetch`/`XHR` before any deferred module bundle runs.
- Cross-origin XHR needs `Access-Control-Expose-Headers`; the middlewares set it.
- `gated` mode still collects on every request. Keep `SONAR` unset in production unless that cost is acceptable.

## Development

```bash
composer install
composer test
composer cs
```

## License

MIT
