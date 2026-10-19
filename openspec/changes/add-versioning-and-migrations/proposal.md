## Why

FreshRSS has no upgrade hook for extensions; ArticleNotes needs a reliable upgrade story before it stores data.

## What Changes

- Changelog/version consistency test, release procedure docs.
- Per-user migration framework with a no-op baseline migration, run lazily and from the user-maintenance hook.
- FreshRSS >= 1.27.0 guard for the API hook; uninstall cleanup; `bin/package`.
