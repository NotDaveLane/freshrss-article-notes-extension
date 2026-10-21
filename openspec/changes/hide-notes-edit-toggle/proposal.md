# Hide the Edit toggle and repeated heading while a Notes page edit form is open (FAN-30)

## Why
With an edit form open on the Notes page, the **Edit** toggle stayed above the form looking unchanged (clicking it
discarded the edit) and the "Note" heading was followed straight away by the textarea's "Note" label.

## What Changes
- The `<summary>` toggle of an open edit form is hidden; Cancel closes the form and focuses Edit again.
- The article or page note's `<h3>` is hidden while its form is open; the textarea label names the note.

## Impact
- `static/article-notes.css`; e2e `specs/notes-page.spec.ts`.
