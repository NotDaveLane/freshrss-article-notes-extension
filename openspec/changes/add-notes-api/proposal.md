# Add the notes API

## Why

Other systems need to fetch and analyze each user's notes, and keep a copy in sync, without a browser session.

## What changes

- `ApiMisc` hook answering `p/api/misc.php/ArticleNotes/v1/...` (`Api`, `ApiRequest`, `ApiResponse`): read-only JSON,
  `v1/status`, `v1/notes` (paged with an opaque continuation, `since`/`include_deleted` for incremental sync with
  tombstones) and `v1/notes/<entry_id>`.
- Authentication with the Google Reader API credentials (ClientLogin token or Basic with the API password).
- `NoteService::changedSince()` can exclude tombstones; `NoteService::existingEntryIds()` reports purged articles.
- New `notes-api` capability; README "API" section.
