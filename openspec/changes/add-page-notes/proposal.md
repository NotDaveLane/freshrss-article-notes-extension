# Add page notes

## Why

A browser extension should be able to keep a note on any web page, even one that is not a FreshRSS article. An article still
has at most one note; a page URL that matches no article can have one page note.

## What changes

- Migration `0005`: `entry_id` of the notes table becomes nullable (unique when present) and `page_url_hash` (unique, NULL on
  article notes) makes page notes unique per normalized URL. SQLite rebuilds the table in a transaction; MySQL/MariaDB and
  PostgreSQL alter in place. Idempotent; keeps every note, tombstone and id.
- `NoteService::savePageNote()` / `deletePageNote()` / `getPageNote()`; a URL that is a FreshRSS article is refused with the
  article's `entry_id`. Page notes never get the "Annotated" label. The URL target (nullable `entry_id`, `url`,
  `normalized_url`, title) uses the snapshot columns, for reuse by highlights.
- API: `GET`/`PUT`/`DELETE v1/page-notes?url=`; `GET v1/lookup` adds `page_note`; note objects gain `kind`, `title`, and
  `entry_id`/`article`/`greader_item_id` are null for page notes, which `GET v1/notes` lists.
- README, CHANGELOG under `[Unreleased]` (no version bump: part of 1.1.0).
