# AGENTS.md

Guidance for AI coding agents (and humans) working in this repository.

## Layout

- `xExtension-ArticleNotes/` – the installable FreshRSS extension (`metadata.json`, `extension.php`, `i18n/`).
  Everything else at the root is development tooling.
- `Dockerfile`, `docker-compose.yml`, `docker/` – dev FreshRSS image (stock FreshRSS + curl + seeding scripts).
- `bin/` – host-side wrappers (`ci`, `phpunit`, `e2e`, `lint`, `package`); `tests/` – `Unit/`, `Integration/`, `Support/`,
  `bootstrap.php`, `e2e/` (Playwright); `composer.json`, `phpunit.xml.dist`, `phpcs.xml`, `phpstan.neon` – tooling config.
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
bin/ci                 # phpcs, PHPStan, PHPUnit + coverage (coverage/index.html), Playwright; SQLite only
bin/ci --all-dbs       # same, plus the integration suite on MariaDB and PostgreSQL
bin/lint               # phpcs + PHPStan only
bin/phpunit            # whole PHPUnit suite, no coverage
bin/phpunit tests/Unit/MetadataTest.php           # one file
bin/phpunit --filter testVersionIsSemver          # one test
TEST_DB=postgres bin/phpunit --testsuite integration   # one engine: sqlite (default) | mariadb | postgres
bin/e2e                # all Playwright specs (needs the stack up)
bin/e2e specs/smoke.spec.ts                       # one spec (path relative to tests/e2e)
```

- Tools run in the `dev` (PHP, image built from the same pinned FreshRSS; FreshRSS sources at
  `/var/www/FreshRSS`, repo at `/workspace`) and `e2e` (Playwright, version pinned to match
  `tests/e2e/package.json`) compose services; they are under profile `tools`, so `up` does not start them.
  `mariadb`/`postgres` are under profile `db-matrix` (the wrappers start them as needed).
- Composer deps install into `vendor/` on first use (run as your user). Add PHP deps with
  `docker compose run --rm --no-deps --user "$(id -u):$(id -g)" dev composer require --dev <pkg>`.
- Integration tests run against a real FreshRSS install in a temporary data directory with a throwaway
  user (`tests/Support/TestEnvironment.php`); nothing touches the dev instance. E2E tests get a
  per-run throwaway user subscribed to `http://fixtures/feed.xml` (`E2E_USER`/`E2E_PASSWORD`), never the live feeds.
- PHPStan is level 10 with no baseline; phpcs uses FreshRSS's own `phpcs.xml`. Fix findings, don't suppress them.
- **Red/green TDD**: write the failing test first (`bin/phpunit <file>` / `bin/e2e <spec>`), see it fail for the
  right reason, make it pass, then run `bin/ci` (and `bin/ci --all-dbs` when touching storage code) before committing.
- Updating the pinned Playwright: bump `@playwright/test` in `tests/e2e/package.json`, regenerate the lockfile, and
  change the `e2e` image tag in `docker-compose.yml` to the same version.

## FreshRSS extension conventions

- System extension (`"type": "system"`), enabled via `extensions_enabled`; per-user settings on the
  extension's own controller page linked via the `MenuConfigurationEntry` hook. API via `Minz_HookType::ApiMisc` (FreshRSS ≥ 1.27.0).
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
3. Commit, tag `vX.Y.Z`, run `bin/package` (writes `dist/ArticleNotes-X.Y.Z.zip`, only `xExtension-ArticleNotes/`).

### Schema migrations

Numbered files in `xExtension-ArticleNotes/Migrations/` (`NNNN_description.php`, returning an `ArticleNotes\Migration`
whose `up()` is idempotent and works on SQLite, MySQL/MariaDB and PostgreSQL). Never edit a shipped migration; add a new
one. `ArticleNotes\Migrator` applies the missing ones per user (version in the user's extension config
`schema_version`, recorded after each step), lazily via `ArticleNotesExtension::ensureSchema()` — call it before first
using storage in a request — and from the `FreshrssUserMaintenance` hook. List new tables in `Storage::TABLES` so
`uninstall()` drops them.

## OpenSpec

Consult the relevant spec in `openspec/specs/` before changing covered behavior, and update the spec in
the same commit as the behavior change. Validate:

```sh
docker run --rm -v "$PWD":/work -w /work node:22 npx --yes @fission-ai/openspec validate --specs --strict
```

## Commits

Small, well-scoped commits referencing the task id (e.g. FAN-1).
