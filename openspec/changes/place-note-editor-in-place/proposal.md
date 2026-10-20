# Open the note editor next to the note (FAN-22)

## Why
The note editor was inserted after the article text, below the whole Annotations panel: on an annotated article it
opened far below the fold, under the read-only note it edits (so the note showed twice), and its card was wider than the
article text column.

## What Changes
- The editor takes the place of the note block (hidden while editing, shown again on Cancel/Escape, updated on Save);
  without a note it opens above the Annotations panel, or at the end of the article text when there is no panel.
- The editor is scrolled into view (`block: 'nearest'`) and its textarea focused without a further jump.
- Being inside the article text element, the editor's edges match the article text column.

## Impact
- `static/article-notes.js` (`openEditor`, `closeEditor`); spec `note-editor`; e2e `specs/note-editor.spec.ts`.
