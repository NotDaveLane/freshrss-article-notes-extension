# Add per-user settings and the automatic "Annotated" label

## Why

Annotated articles should be browsable in FreshRSS and protected from its archiving purge, and each user must be able to
control that.

## What changes

- A settings page (`?c=ArticleNotes&a=settings`) for any signed-in user, linked from the Configuration menu
  (`MenuConfigurationEntry`): "Add a label to articles I annotate" (default on) and "Label name" (default "Annotated"),
  plus the user's API endpoint URL.
- `LabelApplier` labels the article when a note is created or revived and removes the label it applied when the note is
  deleted.
- Migration `0003` adds `label_id` to the notes table, so the label that was applied is removed even after a rename.
- Saved listeners of `NoteService` receive a flag telling whether the note was created or revived.
- New `settings-and-label` capability; `notes-storage` "Change notifications" modified.
