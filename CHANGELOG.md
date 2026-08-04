# Changelog

Notable changes, newest first. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[semantic versioning](https://semver.org/spec/v2.0.0.html) — while the major is
`0`, a minor bump may break things.

## [0.2.0] — 2026-08-04

First release intended for anyone but its author. Everything below is breaking,
and all of it lands at once because the package had no users yet.

### Changed

- The root namespace is `Hkyss\Sonar\`, not `Sonar\`. Claiming a bare top-level
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
