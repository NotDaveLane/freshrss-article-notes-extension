# Release ArticleNotes 1.0.0

## Why

ArticleNotes must be ready to try and to share: verified end to end on every supported FreshRSS line and database,
installable from a release zip alone, and documented for first-time installers.

## What changes

- Full-journey Playwright spec (API password set in the profile, note, label, API, edit via `since`, tombstone, label
  removed) with a second throwaway e2e user.
- FreshRSS 1.27 support: hooks registered by name, version-independent configuration and user-listing helpers; no
  re-tagging of already labelled articles (PostgreSQL on 1.27); migration 0003 probes with `query()` (MySQL). 0003 has
  never shipped in a release, so it is fixed in place.
- Disabling the extension no longer deletes data (FreshRSS calls `uninstall()` on disable); `cli/delete-data.php` deletes
  it on purpose.
- `bin/ci --compat` (version × database matrix), `bin/ci --no-lint`, `bin/verify-release`, `bin/screenshots`.
- README final pass, `docs/community-listing.md`, version 1.0.0.
