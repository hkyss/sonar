# Changelog

Notable changes, newest first. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[semantic versioning](https://semver.org/spec/v2.0.0.html). From 1.0.0 the
public surface is stable and a breaking change needs a major version. That
surface is: the classes under `hkyss\Sonar\` that are not marked internal —
`Sonar`, `Collector`, `Config`, `Headers`, `Overlay` — the shape of the
snapshot array, the `X-Sonar-*` header names, the keys in `config/sonar.php`,
and the four integrations (Laravel, Evolution CMS 3, PSR-15, PDO). The overlay
markup and its CSS are not: they are output, not API.

## [Unreleased]

## [1.2.0] — 2026-09-23

### Added

- A `Pages` section: the pages this tab has loaded, newest first, with the
  server time, the query count and the load time of each, and the median across
  them. One page load is one sample; the median of a few is what a page costs.
  The pages live in `sessionStorage`, twenty at most, and `clear` forgets all
  but the current one.
- Every request in the panel is split into server, database and network time,
  read from the `X-Sonar-Time` and `X-Sonar-Query-Time` headers its response
  already carried and the overlay never showed.
- Once there are two requests, the `Requests` section opens with their median,
  their 95th percentile and the slowest of them.

### Changed

- The pill keeps the page's own figures once the page makes a request. The last
  request the application answered used to take their place; it now sits beside
  them, so the corner says both what the page cost and what the last thing it
  asked for cost, and the page's reading no longer vanishes at the first
  background call.
- The `Requests` heading no longer adds up round trips. Requests overlap, and a
  sum of overlapping round trips is not a time anything took; the median, the
  95th percentile and the slowest request say what that sum stood in for.

## [1.1.0] — 2026-08-27

### Added

- `Sonar::injectPage()`, and `Overlay::injectIntoPage()` under it, for output
  that is a whole page rather than one response among many. Where `inject()`
  leaves output with no `</body>` alone, this appends to the end. Only a caller
  that knows a complete document is what it holds can tell a bare page from a
  fragment of one, so it says so by calling this instead; `inject()` is
  unchanged and stays the right call everywhere else.

### Fixed

- The Evolution CMS overlay is drawn on documents whose template spells no
  `</body>`, which is what a fresh installation serves until it is given
  templates of its own. `OnWebPagePrerender` fires once per rendered document,
  so the provider now injects as a page. It was collecting, visible and holding
  a snapshot the whole time — there was simply nowhere it was willing to put the
  markup, and it returned the document untouched without saying so.
- The Evolution CMS provider skips documents that are not HTML, by their own
  content type, the way the Laravel and PSR-15 integrations skip responses by
  theirs. Until now a sitemap rendered from a template was spared only because
  it happened to carry no `</body>`.

## [1.0.1] — 2026-08-26

### Fixed

- The overlay script no longer spells the closing body tag in its opening
  comment. A host that inserts its own markup by replacing the first `</body>`
  in a response — nginx's `sub_filter`, for one — matched that comment instead
  of the document's own tag, landed inside this script, and ended it early at
  the `</script>` it brought with it. The rest of the overlay spilled into the
  page as text and nothing was drawn, with the markup all present and the page
  otherwise intact.

## [1.0.0] — 2026-08-18

No behaviour changes since 0.2.0. The version says the surface above is now
settled, which 0.2.0 could not promise.

### Added

- A release workflow: a pushed `v*` tag becomes a GitHub release, with the notes
  taken from the matching section of this file.

### Changed

- The README installs from Packagist rather than from a VCS repository entry.

## [0.2.0] — 2026-08-04

First release intended for anyone but its author. Everything below is breaking,
and all of it lands at once because the package had no users yet.

### Changed

- The root namespace is `hkyss\Sonar\`, not `Sonar\`. Claiming a bare top-level
  namespace is not something a public package should do.
- The snapshot renames its keys. `db` — the totals across every source — is now
  `queries`, and `queries` — the individual statements — is now `statements`.
  The old shape had `db.sources.db`, because `db` was both the totals and the
  name of the default source.
- `X-Sonar-Db-Time` is `X-Sonar-Query-Time`, following the snapshot.
- `Sonar::lazy()` is `Sonar::addUsing()`, which pairs it with the `add()` it
  defers.
- `Collector::topQueries()` is `Collector::topStatements()`.
- The inlined assets are `overlay.css` and `overlay.js`, after the `Overlay`
  class that ships them, rather than `console.*`.
- Laravel no longer supplies a default gate. It used to fall back to
  `auth()->check()`, so on a site with open registration every account that
  signed up could read the schema, the query counts and the timings. Without a
  callable `sonar.gate`, `gated` is off.
- `SONAR=true` is ignored when the environment is production. `gated` still
  works there, and is the supported way to look at a live site.

### Added

- `Sonar::start()` opens a new request: it drops what was collected and restarts
  the clock. Processes that survive between requests — Octane, Swoole,
  RoadRunner, queue workers — need it, and without it they reported the age of
  the worker as the request time. The Laravel provider wires it to Octane's
  `RequestReceived`; the PSR-15 middleware calls it on the way in, which can be
  turned off with its second constructor argument.
- PHPStan at level 8 over `src` and `tests`, and the snapshot is written down as
  a type rather than `array<string, mixed>`.
- A test suite for the overlay script, which was a third of the code and had
  none. It runs in jsdom against the script as it ships.

### Security

- Only the fingerprint of a statement is stored. Literals were previously
  replaced to build the grouping key but the raw text was kept for display, so
  the row shown for forty collapsed statements was whichever ran first — with
  its bind values, under any driver that interpolates them.

### Removed

- Tags `v0.1.0` through `v0.1.2` were withdrawn. They were never published to a
  package registry, and they carry the old namespace.
