# Collapse the Notes page filters on phones and enlarge small controls (FAN-24)

## Why
At 390px the Notes page's filter form (search, three selects, checkbox, Apply) filled the whole first screen before any
note, and several controls were too small to tap: a 15px checkbox, 35px-high Edit buttons and ~19px export links.

## What Changes
- The search and Apply button stay visible; kind, colour, feed and "only with a note" sit behind a **Filters** toggle,
  closed on narrow screens unless one of them is set, always shown (toggle hidden) from 48rem wide.
- On touch screens (`pointer: coarse`) the Filters toggle, the "only with a note" row, the export links and the page's
  buttons (Edit, Open at highlight, Apply, Save, Delete) are at least 44px high.

## Impact
- `views/ArticleNotes/notes.phtml`, `static/article-notes.css`, `static/article-notes.js`, `NotesFilter::narrows()`;
  e2e `specs/notes-page.spec.ts`, `tests/Integration/NotesBrowserTest.php`.
