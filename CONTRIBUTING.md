# Contributing

## Getting set up

```bash
composer install
npm install
```

PHP 8.1 is the minimum; CI runs 8.1 through 8.4. The package has no runtime
dependencies — the Laravel, Evolution CMS and PSR-15 integrations rely on
packages that are dev dependencies here and `suggest`ed there. Keep it that way:
anything added to `require` has to earn its place in every install.

## Checks

```bash
composer check   # style, PHPStan, PHPUnit
npm test         # the overlay script, in jsdom
```

Individually:

```bash
composer cs      # style check
composer cs:fix  # apply it
composer stan    # PHPStan, level 8 over src and tests
composer test    # PHPUnit
```

All of these run in CI on every pull request, so there is no value in pushing
something that fails locally.

## The overlay script

`src/Assets/overlay.js` is a classic IIFE, not a module, and has to stay one.
Being a plain inline script is what lets it wrap `fetch` and `XHR` before a
deferred module bundle gets the chance — as a module it would run too late to
see the page's own requests. It is inlined verbatim, so there is no build step
and no transpiler: write it for the oldest browser you are willing to support.

Its tests load the file into jsdom the way a page loads it and assert on what it
renders. `tests/js/overlay.js` sets that up.

## What to keep in mind

- **Nothing sensitive reaches the page.** Statements are stored as fingerprints
  and anything rendered is escaped. A change that puts raw input into the
  overlay needs a test proving it cannot carry markup or bind values.
- **Off means off.** While the collector is off, no call on the facade does any
  work. New entry points should be no-ops too, not guarded at each call site.
- **The safe reading wins.** `gated` without a gate is off; `true` in production
  is off. If a change introduces another ambiguous setting, resolve it the same
  way.
- **The snapshot is a contract.** Its shape is a PHPStan type in
  `hkyss\Sonar\Collector` and it is read by the overlay script, by the headers
  and by anyone integrating a system of their own. Changing it is a breaking
  change and belongs in the changelog.

## Adding an integration

[docs/integrations.md](docs/integrations.md) describes the calls. If you write
one for a system that is not covered, an issue describing how that system
reports its queries is a good first step — some of them need a trick, as
Evolution CMS did.

## Commits and pull requests

Commit subjects are imperative and describe the change, not the file
(`fix: keep the collector per-request under Octane`). Explain in the body why
the change is right, not what the diff already shows. Mark breaking changes
with `!`.

One concern per pull request. If a change needs a refactor first, that is two
pull requests.
