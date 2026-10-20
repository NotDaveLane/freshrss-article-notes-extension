# notes-page Specification

## Purpose

Editing article notes, page notes and highlights in place on the Notes page (`?c=ArticleNotes&a=notes`).

## Requirements

### Requirement: Inline edit form

Each article note, page note and highlight on the Notes page SHALL have an **Edit** disclosure containing a textarea with a visible `<label>` naming the item ("Note", "Page note" or "Note on this highlight"), **Save**, a red `btn-attention` **Delete** and a **Cancel** button. While the form is open, the read-only note text SHALL be hidden.

#### Scenario: Cancel
- **WHEN** a user opens the edit form, changes the text and presses Cancel
- **THEN** the form closes without saving, focus returns to "Edit" and the original note is shown again

### Requirement: Deleting from the Notes page is confirmed

Pressing **Delete** SHALL show a confirmation naming what is deleted (article note, page note or highlight) taken from the button's `data-confirm` attribute; the delete SHALL be submitted only when it is accepted.

#### Scenario: Dismissing the confirmation
- **WHEN** a user presses Delete and dismisses the confirmation
- **THEN** the item is kept

#### Scenario: Accepting the confirmation
- **WHEN** a user presses Delete and accepts the confirmation
- **THEN** the item is deleted and "Deleted." is shown
