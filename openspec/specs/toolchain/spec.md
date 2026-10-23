# toolchain Specification

## Purpose

The test, coverage and quality toolchain, and `bin/ci`, the single host-side full-suite entry point. Every tool runs in a
container; nothing (PHP, Composer, Node) is assumed on the host.

## Requirements

### Requirement: Dev tooling image

A `dev` build target (compose service `dev`, profile `tools`) SHALL be based on the same pinned FreshRSS image
(`FRESHRSS_VERSION`), add Composer and PCOV, provide the SQLite, MySQL and PostgreSQL PDO drivers, mount the repository at
`/workspace`, and keep FreshRSS's sources at `/var/www/FreshRSS` for tests and static analysis.

#### Scenario: Tools available
- **WHEN** running `php -m` in the `dev` service
- **THEN** `pcov`, `pdo_sqlite`, `pdo_mysql` and `pdo_pgsql` are loaded

### Requirement: Single full-suite entry point

`bin/ci` SHALL run on the host, fail fast with a clear message and exit code 2 when the dev stack is not up and healthy, and
otherwise run inside containers, in order: PHP_CodeSniffer, PHPStan, ESLint, PHPUnit with coverage, the Playwright suite,
and the PHP diagnostics check of FreshRSS's log (`bin/check-php-log`).
It SHALL run every step even after a failure, print a per-step PASS/FAIL summary, and exit non-zero if any step failed.

#### Scenario: Green run
- **WHEN** the stack is up and `bin/ci` runs on a clean tree
- **THEN** it exits 0 and prints `bin/ci: OK`

#### Scenario: Stack down
- **WHEN** the `freshrss` service is not healthy
- **THEN** `bin/ci` exits 2 with a message pointing at `docker compose up --build -d --wait`

#### Scenario: Failing check
- **WHEN** a test fails, a style rule is violated, PHPStan reports an error or ESLint reports a problem
- **THEN** `bin/ci` exits non-zero and marks that step FAIL

### Requirement: Coverage report

The PHPUnit step SHALL write an HTML coverage report to `coverage/index.html` (owned by the host user) and a text summary to
the console. Coverage SHALL include `xExtension-ArticleNotes/` only.

#### Scenario: Report written
- **WHEN** `bin/ci` completes the PHPUnit step
- **THEN** `coverage/index.html` exists and reports `xExtension-ArticleNotes`

### Requirement: JavaScript coverage report

The Playwright suite SHALL collect the V8 coverage of the anchoring library during its unit tests and `bin/e2e` SHALL write
an HTML report to `coverage/js/index.html` (owned by the host user) and a summary to the console; `bin/ci` SHALL print the
report's path.

#### Scenario: Report written
- **WHEN** `bin/e2e specs/anchoring.spec.ts` completes
- **THEN** `coverage/js/index.html` reports `xExtension-ArticleNotes/static/anchoring.js`

### Requirement: PHPUnit suites on real FreshRSS

`phpunit.xml.dist` SHALL define `unit` (`tests/Unit`) and `integration` (`tests/Integration`) suites. The bootstrap SHALL
install FreshRSS from the image into an isolated temporary data directory, create a throwaway user, and load the extension
from the repository, so integration tests run against real FreshRSS classes and a real database. The data directory (and, for
server databases, the user's tables) SHALL be removed on exit.

#### Scenario: Isolation
- **WHEN** the suite runs
- **THEN** neither the dev instance's data nor the host's files other than `coverage/` and `.phpunit.cache/` are modified

### Requirement: Database matrix

The environment variable `TEST_DB` (`sqlite` default, `mariadb`, `postgres`) SHALL select the database engine of the
integration tests. Compose profile `db-matrix` SHALL provide `mariadb` and `postgres` services with healthchecks.
`bin/ci --all-dbs` SHALL additionally run the integration suite against MariaDB and PostgreSQL; plain `bin/ci` SHALL use SQLite only.

#### Scenario: All engines
- **WHEN** running `bin/ci --all-dbs`
- **THEN** the integration suite passes on SQLite, MariaDB and PostgreSQL, each reported as its own step

### Requirement: Static analysis and style

PHPStan SHALL run at level 10 over `xExtension-ArticleNotes/` and `tests/` with FreshRSS's sources scanned, and without a
baseline. PHP_CodeSniffer SHALL use FreshRSS's own `phpcs.xml` as its base (tabs, PSR-12 derived). ESLint (pinned in
`tests/e2e/package.json`, configured in `tests/e2e/eslint.config.mjs`, run in the `e2e` service) SHALL check
`xExtension-ArticleNotes/static/` with its recommended rules and no warnings allowed.

#### Scenario: Violation detected
- **WHEN** a file violates the style, has a type error, or a JavaScript file has an ESLint problem
- **THEN** `bin/lint` and `bin/ci` exit non-zero

### Requirement: End-to-end tests

An `e2e` compose service (profile `tools`) SHALL use the official `mcr.microsoft.com/playwright` image, pinned to the exact
`@playwright/test` version in `tests/e2e/package.json`, and reach FreshRSS at `http://freshrss`.

#### Scenario: Smoke test
- **WHEN** the Playwright suite runs
- **THEN** the dev admin can sign in and ArticleNotes is shown as an enabled system extension

### Requirement: End-to-end PHP diagnostics guard

Every Playwright spec SHALL use the guarded `test` from `tests/e2e/support/test.ts`, which fails a test when any response
received by its browser context or its `request` fixture contains a PHP diagnostic (`Warning`, `Notice`, `Deprecated`,
`Fatal error`, `Parse error`) in PHP's HTML or plain-text format.

#### Scenario: Warning in a page
- **WHEN** a page loaded by a spec prints a PHP warning
- **THEN** that test fails and lists the URL and the warning

### Requirement: PHP log check

After the end-to-end suite, `bin/ci` SHALL run `bin/check-php-log` over the FreshRSS container log written during the run
and fail with the offending lines when a PHP diagnostic comes from `xExtension-ArticleNotes/` or from a request to the
extension's pages (`c=ArticleNotes`) or API (`/api/misc.php/ArticleNotes`). The dev image SHALL tag each error-log line
with its request URI. Other diagnostics SHALL be listed as ignored; known exceptions go in the script's allow-list.

#### Scenario: Warning logged on an extension page
- **WHEN** the log holds a PHP warning from a request to `?c=ArticleNotes&a=settings`
- **THEN** `bin/check-php-log` prints it and exits 1, and `bin/ci` exits non-zero

#### Scenario: Unrelated diagnostic
- **WHEN** the log holds a PHP deprecation from FreshRSS core on a request that is not one of the extension's
- **THEN** `bin/check-php-log` lists it as ignored and exits 0

### Requirement: PHPUnit fails on PHP diagnostics

PHPUnit SHALL fail the run on PHP warnings, notices and deprecations triggered during the tests.

#### Scenario: Deprecation in a test
- **WHEN** code under test triggers a PHP deprecation
- **THEN** `bin/phpunit` exits non-zero

### Requirement: Throwaway e2e user

`bin/e2e` SHALL create a throwaway user per run via FreshRSS's CLI, subscribe it to `http://fixtures/feed.xml` (never the
live feeds), pass its credentials as `E2E_USER`/`E2E_PASSWORD` (and its API password as `E2E_API_PASSWORD`), and delete
it afterwards. It SHALL likewise create a second throwaway user without an API password for the full-journey spec
(`E2E_JOURNEY_USER`/`E2E_JOURNEY_PASSWORD`). The dev instance SHALL allow the `fixtures` host in `internal_host_allowlist`.

#### Scenario: Fixture articles
- **WHEN** the throwaway user signs in
- **THEN** the fixture feed's articles are listed

### Requirement: E2E user without an API password

`bin/e2e` SHALL also create, and delete afterwards, a throwaway user that never gets an API password
(`E2E_NO_API_USER`/`E2E_NO_API_PASSWORD`), since FreshRSS cannot remove an API password once the full-journey spec set one.

#### Scenario: Settings warning
- **WHEN** the settings spec signs in as `E2E_NO_API_USER`
- **THEN** the user has no API password, whichever specs ran before

### Requirement: Full-journey end-to-end test

The Playwright suite SHALL include a full-journey spec in which a user without an API password sets one in the profile,
adds a note in the UI, sees the article under the "Annotated" label, fetches the note through the API with HTTP Basic,
edits it and receives the newer `updated_at` through `since`, deletes it and receives the tombstone, and no longer finds
the article under the label.

#### Scenario: Journey
- **WHEN** `bin/e2e specs/full-journey.spec.ts` runs
- **THEN** every step of the journey passes against the fixture feed

### Requirement: Compatibility matrix

`bin/ci --compat` SHALL run `bin/ci --all-dbs` once per FreshRSS version in `COMPAT_VERSIONS` (default: 1.27.1 and the
default version), each in its own throwaway compose project with its own FreshRSS instance, without touching the dev stack,
and print a per-version PASS/FAIL summary. Linting (phpcs, PHPStan) SHALL run on the default version only; `bin/ci
--no-lint` skips it.

#### Scenario: Matrix
- **WHEN** running `bin/ci --compat`
- **THEN** PHPUnit on SQLite, MariaDB and PostgreSQL and the Playwright suite pass on FreshRSS 1.27.1 and 1.30.1, and it
  prints `bin/ci --compat: OK`

### Requirement: Continuous integration

A GitHub Actions workflow (`.github/workflows/ci.yml`) SHALL run the compatibility matrix on every push to `main` and every
pull request: one job per FreshRSS version in `COMPAT_VERSIONS`, each starting the dev stack for that version and running
`bin/ci --all-dbs` (with `--no-lint` on all but the default version), uploading its coverage reports and Playwright results.

#### Scenario: Push to main
- **WHEN** a commit is pushed to `main`
- **THEN** a job per FreshRSS version runs `bin/ci --all-dbs` and the workflow fails if any of them prints `bin/ci: FAILED`

### Requirement: Version bump

`bin/bump [patch|minor|major|X.Y.Z]` (default patch) SHALL move `CHANGELOG.md`'s `[Unreleased]` entries under a new
`## [X.Y.Z] - YYYY-MM-DD` heading, merging repeated `###` sections, and write the new version into `metadata.json`,
`docs/openapi.yaml` and its served `openapi.json`, and the version examples of the README and the community listing,
leaving history such as the README's Upgrading notes alone. It SHALL change nothing and fail when `[Unreleased]` is empty
or the version is not newer.

#### Scenario: Patch release
- **WHEN** `bin/bump` runs at 1.1.2 with entries under `[Unreleased]`
- **THEN** every version reference becomes 1.1.3, the entries sit under `## [1.1.3] - <today>`, and the unit suite passes

#### Scenario: Nothing to release
- **WHEN** `bin/bump` runs with an empty `[Unreleased]`
- **THEN** it exits non-zero with "nothing to release" and no file changes

### Requirement: Release workflow

A GitHub Actions workflow (`.github/workflows/release.yml`) SHALL publish a GitHub release when the `production` branch is
pushed with an untagged `metadata.json` version, after the CI matrix, `bin/package` and `bin/verify-release` pass on that
commit: it tags the commit `vX.Y.Z` and attaches the zip, with that version's `CHANGELOG.md` section as notes. A version
already tagged SHALL never be released again.

#### Scenario: Promote a new version
- **WHEN** `production` is pushed with `metadata.json` at `1.1.3` and no `v1.1.3` tag exists
- **THEN** after the CI matrix passes, `v1.1.3` is tagged on that commit and released with `ArticleNotes-1.1.3.zip` and
  the `## [1.1.3]` changelog entries as its notes

#### Scenario: Already released here
- **WHEN** `production` is pushed at the commit `v1.1.2` already points to
- **THEN** the workflow succeeds without running the matrix or publishing anything

#### Scenario: Promoted without a version bump
- **WHEN** `production` is pushed at a new commit while `metadata.json` still says `1.1.2`, already tagged elsewhere
- **THEN** the workflow fails, asking for a version bump, and publishes nothing

### Requirement: README screenshots

`bin/screenshots` SHALL capture the README pictures into `docs/screenshots/` (owned by the host user) from the dev
instance as the dev admin with the seeded Hacker News content, and delete the note it added for them.

#### Scenario: Refresh
- **WHEN** `bin/screenshots` runs with the stack up and the seed feeds fetched
- **THEN** `note-editor.png`, `note.png`, `annotated-label.png` and `settings.png` are written to `docs/screenshots/`

### Requirement: Single-run wrappers

`bin/phpunit [args]` (no coverage; honours `TEST_DB`), `bin/e2e [spec]` and `bin/lint` SHALL run one tool in its container.

#### Scenario: One test
- **WHEN** running `bin/phpunit tests/Unit/MetadataTest.php`
- **THEN** only that file's tests run
