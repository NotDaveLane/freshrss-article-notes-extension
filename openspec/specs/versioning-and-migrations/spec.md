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

### Requirement: Uninstall cleanup

`uninstall()` SHALL drop the extension's tables for every user and remove its user and system configuration.

#### Scenario: Uninstall
- **WHEN** the extension is uninstalled
- **THEN** no `ArticleNotes` configuration remains for any user

### Requirement: FreshRSS version guard

When `FRESHRSS_VERSION` is below 1.27.0, `init()` SHALL log a warning and SHALL NOT register the `ApiMisc` hook.

#### Scenario: Old FreshRSS
- **WHEN** the extension initializes on FreshRSS 1.26.9
- **THEN** a warning is logged and no API hook is registered

### Requirement: Release packaging

`bin/package` SHALL build `dist/ArticleNotes-<version>.zip` containing only the `xExtension-ArticleNotes/` directory.

#### Scenario: Package contents
- **WHEN** `bin/package` runs
- **THEN** every archive entry starts with `xExtension-ArticleNotes/`
