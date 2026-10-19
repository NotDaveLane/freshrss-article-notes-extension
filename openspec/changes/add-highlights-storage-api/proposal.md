# Add highlight storage and API

## Why

ArticleNotes 1.1 grows into annotations usable inside FreshRSS and from third-party clients such as a browser extension. A
highlight is a passage the user selected on a FreshRSS article or on any web page, with an optional note. This change adds
the server side: storage, one write service and the API. The FreshRSS UI follows in a later task.

## What changes

- Migration `0006`: per-user `articlenotes_highlight` table (unique `uuid`, nullable `entry_id`, URL and article snapshot,
  `source`, JSON `selector`, `color`, `note`, timestamps, `deleted_at` tombstone, `label_applied`/`label_id`), indexed on
  `entry_id`, the normalized-URL hash and `updated_at`.
- `HighlightService`: the single write entry point (validation, target resolution through the URL normalizer / lookup,
  idempotent create by `uuid`, update of note and color, tombstone). `ArticleSnapshot` is the shared article snapshot.
- Label rule shared by notes and highlights in `LabelApplier`: the "Annotated" label is applied with an article's first
  note or highlight and removed, only if the extension applied it, when neither remains.
- API: `POST v1/highlights`, `GET|PATCH|DELETE v1/highlights/<uuid>`, `GET v1/highlights` (by `entry_id`, by `url`, or the
  changed-since sync), `highlights` and `page_highlights` in `GET v1/lookup`.
- FreshRSS controller actions (`highlights`, `highlightCreate`, `highlightUpdate`, `highlightDelete`) for the UI task.
- README API section, CHANGELOG under `[Unreleased]` (no version bump: part of 1.1.0).
