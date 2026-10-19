# Add the note editor

## Why

Users need to add, view, edit and delete the note of an article from FreshRSS's reading views.

## What changes

- `EntryBeforeDisplay` hook appends the user's note (escaped, line breaks kept, last-updated time) to the article content.
- A "Note" action on every article opens an inline editor (`static/article-notes.js` / `.css`), also for articles loaded dynamically.
- `FreshExtension_ArticleNotes_Controller` with POST `save` and `delete` actions answering JSON.
- New `note-editor` capability.
