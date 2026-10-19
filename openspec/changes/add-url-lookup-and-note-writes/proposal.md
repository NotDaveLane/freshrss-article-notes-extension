# Add URL lookup and note write endpoints

## Why

Third-party clients such as a browser extension need to ask "is the page I am on a FreshRSS article, and what is my note
on it?", and to create, update and delete that note, without a FreshRSS browser session.

## What changes

- `UrlNormalizer` (pure): one canonical form per web page, so variants of a URL match.
- Migration `0004`: per-user URL index table `articlenotes_url` (normalized URL hash → entry ids, with link and GUID) and the
  normalized URL (+ hash) on notes. `UrlIndex` fills it after insertion (user maintenance and lazily on lookup, bounded per
  request, `index_complete` reports progress), prunes purged articles and drops rows of updated articles (`entry_before_update`).
- API `v1`: `GET v1/lookup?url=`, `PUT` and `DELETE v1/notes/<entry_id>`, ETag / `If-Match`, JSON body rules (415/413/400), a
  method list per path (405 + `Allow`). Note objects gain `url` and `normalized_url`.
- `NoteService::save()` makes `updated_at` strictly increase, so `(id, updated_at)` (the ETag) changes with every save.
- README API section; CHANGELOG under `[Unreleased]` (no version bump: part of 1.1.0).
