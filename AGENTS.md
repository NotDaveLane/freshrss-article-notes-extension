# AGENTS.md

Guidance for AI coding agents (and humans) working in this repository.

## Layout

- `xExtension-ArticleNotes/` – the installable FreshRSS extension (`metadata.json`, `extension.php`, `i18n/`).
  Everything else at the root is development tooling.
- `Dockerfile`, `docker-compose.yml`, `docker/` – dev FreshRSS image (stock FreshRSS + curl + seeding scripts + error-log
  format with the request URI).
- `bin/` – host-side wrappers (`ci`, `phpunit`, `e2e`, `lint`, `check-php-log`, `package`, `verify-release`,
  `screenshots`); `tests/` – `Unit/`, `Integration/`, `Support/`, `bootstrap.php`, `e2e/` (Playwright: `specs/` run by
  `bin/e2e`, `release/` by `bin/verify-release`, `screenshots/` by `bin/screenshots`); `composer.json`,
  `phpunit.xml.dist`, `phpcs.xml`, `phpstan.neon` – tooling config.
- `docs/screenshots/` – README pictures (`bin/screenshots`); `docs/community-listing.md` – draft entry for
  FreshRSS/Extensions.
- `tests/fixtures/feeds/feed.xml` – static fixture feed (stable GUIDs) served at `http://fixtures/feed.xml`.
- `openspec/` – `project.md` and capability specs in `openspec/specs/`.
- `CHANGELOG.md`, `README.md`, `LICENSE` (AGPL-3.0-or-later).

## Every tool runs in a container

Nothing (PHP, Composer, Node) is assumed on the host. Use throwaway containers, e.g.
`docker run --rm -v "$PWD":/app -w /app composer:2 ...`, and make sure created files are owned by
you (`--user "$(id -u):$(id -g)"` or a chown). `bin/ci` is the single host-side
full-suite entry point (HTML coverage in `coverage/index.html`); run it after every change.

## Dev instance

```sh
docker compose up --build -d --wait      # ready instance, no manual steps
docker compose port freshrss 80          # where it listens (default 8080)
docker compose down -v                   # reset
```

Credentials (development only): `admin` / `freshrss-dev-password`, API password `freshrss-dev-api`
(see `.env.example`). Seed feeds: Hacker News, Lobsters. Tests must use `http://fixtures/feed.xml`,
never the live feeds. First boot is idempotent: seeding re-runs safely on every start.

Useful: `docker compose exec freshrss php cli/actualize-user.php --user admin`,
`docker compose logs freshrss`.

## Tests, coverage and quality (`bin/ci`)

Prerequisite: the dev stack is up (`docker compose up --build -d --wait`); `bin/ci` exits 2 with a message otherwise.

```sh
bin/ci                 # phpcs, PHPStan, ESLint, PHPUnit + coverage (coverage/index.html), Playwright
                       # (anchoring.js coverage in coverage/js/index.html); SQLite only
bin/ci --all-dbs       # same, plus the integration suite on MariaDB and PostgreSQL
bin/ci --compat        # bin/ci --all-dbs on FreshRSS 1.27.1 and 1.30.1 (COMPAT_VERSIONS), throwaway stacks; no dev stack needed
bin/check-php-log      # PHP diagnostics caused by ArticleNotes in FreshRSS's container log (--since <time>)
bin/lint               # phpcs + PHPStan + ESLint (xExtension-ArticleNotes/static/) only
bin/phpunit            # whole PHPUnit suite, no coverage
bin/phpunit tests/Unit/MetadataTest.php           # one file
bin/phpunit --filter testVersionIsSemver          # one test
TEST_DB=postgres bin/phpunit --testsuite integration   # one engine: sqlite (default) | mariadb | postgres
bin/e2e                # all Playwright specs (needs the stack up)
bin/e2e specs/smoke.spec.ts                       # one spec (path relative to tests/e2e)
FRESHRSS_VERSION=1.27.1 bin/phpunit               # against another FreshRSS (builds that dev image)
bin/package && bin/verify-release                 # build the zip, install it into a clean freshrss/freshrss
```

- GitHub Actions (`.github/workflows/ci.yml`) runs the `bin/ci --compat` matrix on pushes to `main` and on pull
  requests, one job per FreshRSS version; when changing `COMPAT_VERSIONS` or the default version, update its matrix too.
- Tools run in the `dev` (PHP, image built from the same pinned FreshRSS; FreshRSS sources at
  `/var/www/FreshRSS`, repo at `/workspace`) and `e2e` (Playwright, version pinned to match
  `tests/e2e/package.json`) compose services; they are under profile `tools`, so `up` does not start them.
  `mariadb`/`postgres` are under profile `db-matrix` (the wrappers start them as needed).
- Composer deps install into `vendor/` on first use (run as your user). Add PHP deps with
  `docker compose run --rm --no-deps --user "$(id -u):$(id -g)" dev composer require --dev <pkg>`.
- Integration tests run against a real FreshRSS install in a temporary data directory with a throwaway
  user (`tests/Support/TestEnvironment.php`); nothing touches the dev instance. E2E tests get a
  per-run throwaway user subscribed to `http://fixtures/feed.xml` (`E2E_USER`/`E2E_PASSWORD`/`E2E_API_PASSWORD`), never
  the live feeds, plus one without an API password for the full-journey spec (`E2E_JOURNEY_USER`/`E2E_JOURNEY_PASSWORD`,
  which sets one) and one that never gets an API password (`E2E_NO_API_USER`/`E2E_NO_API_PASSWORD`).
- `static/anchoring.js` (shared highlight anchoring library, plain ES module, no build) is unit-tested in Chromium by
  `tests/e2e/specs/anchoring.spec.ts` (the `e2e` service mounts the extension read-only at `/extension`); ESLint's config
  is `tests/e2e/eslint.config.mjs`.
- **PHP diagnostics fail the suite.** Specs import `test`/`expect` (and Playwright types) from `tests/e2e/support/test.ts`,
  never `@playwright/test`: its automatic fixture fails a test when any response (page, fetch, `request` fixture) contains
  a PHP warning, notice, deprecation or error. `bin/ci` then runs `bin/check-php-log`, which fails on PHP diagnostics in the
  FreshRSS container log during the e2e run that come from `xExtension-ArticleNotes/` or from requests to `c=ArticleNotes`
  / `api/misc.php/ArticleNotes` (the dev image tags error-log lines with the request URI, `docker/apache-error-log.conf`;
  other diagnostics are listed as ignored; documented exceptions go in the script's `ALLOW_LIST`). PHPUnit fails on
  warnings, notices and deprecations.
- PHPStan is level 10 with no baseline; phpcs uses FreshRSS's own `phpcs.xml`. Fix findings, don't suppress them.
- **Red/green TDD**: write the failing test first (`bin/phpunit <file>` / `bin/e2e <spec>`), see it fail for the
  right reason, make it pass, then run `bin/ci` (and `bin/ci --all-dbs` when touching storage code) before committing.
- Updating the pinned Playwright: bump `@playwright/test` in `tests/e2e/package.json`, regenerate the lockfile, and
  change the `e2e` image tag in `docker-compose.yml` to the same version.

## FreshRSS extension conventions

- Controllers extend `FreshRSS_ActionController` (never `Minz_ActionController`): FreshRSS's layout needs a
  `FreshRSS_View` and prints warnings with a plain `Minz_View`.
- System extension (`"type": "system"`), enabled via `extensions_enabled`; per-user settings on the
  extension's own controller page linked via the `MenuConfigurationEntry` hook. API via the `api_misc` hook (FreshRSS ≥ 1.27.0).
- Supported: FreshRSS ≥ 1.27.0. Use only APIs that 1.27 has, or fall back: register hooks by name (`'api_misc'`, not
  `Minz_HookType`, which 1.27 lacks), and use `ArticleNotesExtension`'s helpers (`setUserValue()`, `users()`,
  `userConfiguration()`). `bin/ci --compat` checks 1.27.1 and 1.30.1.
- PHP: tabs, `declare(strict_types=1);`, FreshRSS code style, `final` classes, `#[\Override]`.
- Translations through `registerTranslates()` and `i18n/<lang>/ext.php`; no hardcoded UI strings.
- CSRF tokens on every state-changing form/request; escape all output (notes are rendered escaped).
- Prepared statements only; portable SQL (SQLite, MySQL/MariaDB, PostgreSQL); per-user tables using
  FreshRSS's table prefix.
- Data is per user; never leak across users. Never modify FreshRSS core.

## Versioning

Semantic versioning in `xExtension-ArticleNotes/metadata.json`; record every user-visible change in
`CHANGELOG.md` (Keep a Changelog, under `## [Unreleased]`). Schema changes use numbered, idempotent
per-user migrations (FreshRSS has no extension upgrade hook).

### Release procedure

1. Bump `version` in `xExtension-ArticleNotes/metadata.json` (the single source of truth).
2. In `CHANGELOG.md`, move the `[Unreleased]` entries under `## [X.Y.Z] - YYYY-MM-DD` (newest first) and leave an empty
   `## [Unreleased]`. `tests/Unit/ChangelogTest.php` fails if the two drift apart.
3. Run `bin/ci --compat`, commit, run `bin/package` (writes `dist/ArticleNotes-X.Y.Z.zip`, only
   `xExtension-ArticleNotes/`) and `bin/verify-release` (installs that zip into a clean `freshrss/freshrss`).
4. Push to `main`; once its CI run passes, merge `main` into `production` and push (Throughput's Promote).
   `.github/workflows/release.yml` releases `metadata.json`'s version if it has no `vX.Y.Z` tag yet: CI matrix, then
   `bin/package` + `bin/verify-release`, then it tags the commit and publishes the GitHub release (zip + changelog notes).
   It fails if that version was already released from another commit, so never promote without bumping the version.

### Schema migrations

Numbered files in `xExtension-ArticleNotes/Migrations/` (`NNNN_description.php`, returning an `ArticleNotes\Migration`
whose `up()` is idempotent and works on SQLite, MySQL/MariaDB and PostgreSQL). Never edit a shipped migration; add a new
one. `ArticleNotes\Migrator` applies the missing ones per user (version in the user's extension config
`schema_version`, recorded after each step), lazily via `ArticleNotesExtension::ensureSchema()` — call it before first
using storage in a request — and from the `freshrss_user_maintenance` hook. List new tables in `Storage::TABLES` so
`cli/delete-data.php` drops them. `uninstall()` keeps all data: FreshRSS calls it when the extension is *disabled*.
Probe with `query()` + `closeCursor()`, never `exec('SELECT …')` (it breaks MySQL connections).

## OpenSpec

Consult the relevant spec in `openspec/specs/` before changing covered behavior, and update the spec in
the same commit as the behavior change. Validate:

```sh
docker run --rm -v "$PWD":/work -w /work node:22 npx --yes @fission-ai/openspec validate --specs --strict
```

## Commits

Small, well-scoped commits referencing the task id (e.g. FAN-1).
