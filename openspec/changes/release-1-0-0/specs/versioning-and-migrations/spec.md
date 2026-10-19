## ADDED Requirements

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


### Requirement: Supported FreshRSS versions

The extension SHALL work on every FreshRSS release from 1.27.0 on, on SQLite, MySQL/MariaDB and PostgreSQL. It SHALL only
use FreshRSS APIs available since 1.27.0 or fall back to such APIs: hooks are registered by name, and user configuration,
user listing and other users' configuration are accessed through version-independent helpers.

#### Scenario: Oldest supported line
- **WHEN** the PHPUnit suites (SQLite, MariaDB, PostgreSQL) and the Playwright suite run against FreshRSS 1.27.1
- **THEN** they pass, as they do against the default 1.30.1


### Requirement: Release verification

`bin/verify-release [zip]` SHALL install a release zip (default `dist/ArticleNotes-<version>.zip`) into a clean stock
`freshrss/freshrss` container by unpacking it into `extensions/`, and a Playwright spec SHALL then, as described in the
README, enable ArticleNotes as the administrator, set an API password, add a note in the UI and fetch it through the API.

#### Scenario: Installable release
- **WHEN** `bin/verify-release` runs with the stack up
- **THEN** the API answers `404` (`503` on FreshRSS 1.27) before the extension is enabled, and after the steps `v1/status` reports the zip's
  version and `v1/notes` returns the note

## REMOVED Requirements

### Requirement: Uninstall cleanup

**Reason**: FreshRSS calls `uninstall()` when an administrator disables the extension, so dropping every user's notes there
lost data on a simple disable.

**Migration**: Use `cli/delete-data.php --yes [--user <name>]` to delete the data on purpose.
