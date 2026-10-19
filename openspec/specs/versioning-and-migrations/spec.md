# versioning-and-migrations Specification

## Purpose

Versioning rules, per-user schema migrations, the FreshRSS version guard and release packaging.

## Requirements

### Requirement: Version source of truth

The extension version SHALL be the semantic version in `xExtension-ArticleNotes/metadata.json`, and `CHANGELOG.md` SHALL
agree with it: its newest released section equals the version, or, while only `[Unreleased]` exists, the version is a 0.x
pre-release. A PHPUnit test SHALL enforce this.

#### Scenario: Drift detected
- **WHEN** `metadata.json` is bumped without a matching dated changelog section
- **THEN** the changelog test fails

### Requirement: Per-user schema migrations

The extension SHALL keep a schema version per user (`schema_version` in the user's extension configuration) and apply the
numbered migrations of `xExtension-ArticleNotes/Migrations/` that are newer than it, in order, recording the version after
each one. Migrations SHALL be idempotent and work on SQLite, MySQL/MariaDB and PostgreSQL. A failing migration SHALL leave
the recorded version at the last successful one.

#### Scenario: Fresh user
- **WHEN** a user without a schema version is migrated
- **THEN** every migration is applied and the recorded version is the latest

#### Scenario: Already current
- **WHEN** the migrator runs again
- **THEN** nothing is applied

#### Scenario: Older user
- **WHEN** a user is at an older version
- **THEN** only the missing migrations are applied, in order

#### Scenario: Failure
- **WHEN** a migration throws
- **THEN** the recorded version stays at the last successful migration and the error propagates

### Requirement: Migration triggers

Migrations SHALL run lazily on the first use of extension storage in a request (`ensureSchema()`) and in the
`FreshrssUserMaintenance` hook, which FreshRSS runs for every user during actualization. Migration `0001` SHALL be a no-op
baseline.

#### Scenario: Maintenance hook
- **WHEN** FreshRSS calls the `FreshrssUserMaintenance` hook for a user
- **THEN** that user's schema is brought up to date

### Requirement: Data survives disabling

`uninstall()`, which FreshRSS calls when an administrator disables the extension, SHALL keep every user's notes and
extension settings (FreshRSS's "Remove" button only deletes the extension directory and does not call it).

#### Scenario: Disable and enable again
- **WHEN** the extension is disabled (its `uninstall()` runs) and enabled again
- **THEN** every user's notes, tombstones and settings are unchanged

### Requirement: Explicit data deletion

`xExtension-ArticleNotes/cli/delete-data.php` SHALL permanently delete the extension's tables and the extension's user
settings of every user, or of the user given with `--user <name>`, and only when `--yes` is given; without it, or for an
unknown user, it SHALL exit non-zero without deleting anything.

#### Scenario: Confirmed deletion of one user
- **WHEN** an administrator runs `php extensions/xExtension-ArticleNotes/cli/delete-data.php --yes --user alice`
- **THEN** alice's notes table and ArticleNotes settings are gone, and other users' data is untouched

#### Scenario: Missing confirmation
- **WHEN** the script runs without `--yes`
- **THEN** it exits 1, names `--yes`, and deletes nothing

### Requirement: FreshRSS version guard

When `FRESHRSS_VERSION` is below 1.27.0, `init()` SHALL log a warning and SHALL NOT register the `ApiMisc` hook.

#### Scenario: Old FreshRSS
- **WHEN** the extension initializes on FreshRSS 1.26.9
- **THEN** a warning is logged and no API hook is registered

### Requirement: Supported FreshRSS versions

The extension SHALL work on every FreshRSS release from 1.27.0 on, on SQLite, MySQL/MariaDB and PostgreSQL. It SHALL only
use FreshRSS APIs available since 1.27.0 or fall back to such APIs: hooks are registered by name, and user configuration,
user listing and other users' configuration are accessed through version-independent helpers.

#### Scenario: Oldest supported line
- **WHEN** the PHPUnit suites (SQLite, MariaDB, PostgreSQL) and the Playwright suite run against FreshRSS 1.27.1
- **THEN** they pass, as they do against the default 1.30.1

### Requirement: Release packaging

`bin/package` SHALL build `dist/ArticleNotes-<version>.zip` containing only the `xExtension-ArticleNotes/` directory.

#### Scenario: Package contents
- **WHEN** `bin/package` runs
- **THEN** every archive entry starts with `xExtension-ArticleNotes/`

### Requirement: Release verification

`bin/verify-release [zip]` SHALL install a release zip (default `dist/ArticleNotes-<version>.zip`) into a clean stock
`freshrss/freshrss` container by unpacking it into `extensions/`, and a Playwright spec SHALL then, as described in the
README, enable ArticleNotes as the administrator, set an API password, add a note in the UI and fetch it through the API.

#### Scenario: Installable release
- **WHEN** `bin/verify-release` runs with the stack up
- **THEN** the API answers `404` (`503` on FreshRSS 1.27) before the extension is enabled, and after the steps `v1/status` reports the zip's
  version and `v1/notes` returns the note
