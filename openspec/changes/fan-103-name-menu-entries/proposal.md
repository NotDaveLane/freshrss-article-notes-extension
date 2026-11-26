# Name the Configuration menu entries by purpose and link the Notes page from every note (FAN-103)

## Why
The Configuration menu offered "Article notes" (the settings) next to "Notes" (the list of notes): users looking for their
notes picked "Article notes" and landed on a settings form. The only in-article route to the Notes page was the "All my
notes" link of the Annotations panel, which only exists when the article has highlights.

## What Changes
- Menu entries and page titles: "My notes" (listed first) and "Article notes settings".
- The note block ends with an "All my notes" link (hidden inside the Annotations panel, which has its own); clicking it
  navigates to the Notes page instead of opening the note editor.

## Impact
- `extension.php`, `NoteBlock.php`, `i18n/en/ext.php`, `static/article-notes.js`, `static/article-notes.css`.
- Tests: `SettingsAndLabelTest`, `NoteDisplayTest`, e2e `settings`, `note-editor`, `notes-page`.
