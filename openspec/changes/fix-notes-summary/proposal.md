# Fix the Notes page count and the empty-state export (FAN-27)

## Why
The Notes page summary read "1 annotated articles and pages" for one result and "0 annotated articles and pages" next to
the empty or no-results message, and the export links were still offered when nothing was listed, downloading an empty
file.

## What Changes
- One listed group reads "1 annotated article or page" (new `summary_one` translation); other counts keep
  "%s annotated articles and pages".
- When nothing matches, the summary line and the export links are not rendered; only the empty or no-results message
  shows.

## Impact
- `views/ArticleNotes/notes.phtml`, `i18n/en/ext.php`; e2e `specs/notes-page.spec.ts`.
