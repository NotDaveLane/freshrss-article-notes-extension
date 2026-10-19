# Add the Notes page

## Why

Notes and highlights are written where the user reads, but there was no place to see everything annotated at once, search it,
tidy it up or take it elsewhere.

## What changes

- A Notes page served by the extension's controller (`?c=ArticleNotes&a=notes`) to any signed-in user: groups by article or
  page, search, filters, pagination, in-place edit and delete (`notesEdit`), and export (`notesExport`).
- `NotesFilter`, `NotesBrowser` (portable SQL, per-user connection), `NotesGroup`, `NotesPage`, `NotesExport` (Markdown and
  W3C JSON) and `WebAnnotation` (the reusable W3C mapping, for the API later).
- Migration `0007`: `articlenotes_highlight.quote`, the searchable selected text, backfilled from the selector.
- Links: Configuration menu, settings page, and the Annotations panel ("All my notes"); the article view opens and scrolls to a
  highlight requested with `articlenotes_hl=<uuid>`.
- README "The Notes page", CHANGELOG under `[Unreleased]`, PHPUnit and Playwright tests.
