# notes-page Specification

## Purpose

Editing article notes, page notes and highlights in place on the Notes page (`?c=ArticleNotes&a=notes`).

## Requirements

### Requirement: Inline edit form

Each article note, page note and highlight on the Notes page SHALL have an **Edit** disclosure containing a textarea with a visible `<label>` naming the item ("Note", "Page note" or "Note on this highlight"), **Save**, a red `btn-attention` **Delete** and a **Cancel** button. While the form is open, the read-only note text, the **Edit** toggle and the article or page note's heading SHALL be hidden, so the label is the only "Note" shown and Cancel is the way to close the form.

#### Scenario: Opening the form
- **WHEN** a user presses Edit under an article note
- **THEN** the textarea shows with its "Note" label, and neither the Edit button nor the "Note" heading is shown

#### Scenario: Cancel
- **WHEN** a user opens the edit form, changes the text and presses Cancel
- **THEN** the form closes without saving, focus returns to "Edit" and the original note is shown again

### Requirement: Edit form focus

Opening an **Edit** disclosure on the Notes page, by keyboard or click, SHALL move focus to its textarea with the caret at the end, keeping the form in view. Pressing Escape inside the open form SHALL act like **Cancel**: close the form and focus **Edit**.

#### Scenario: Opening by keyboard
- **WHEN** a user focuses Edit and presses Enter or Space
- **THEN** the item's textarea is focused

#### Scenario: Escape
- **WHEN** a user presses Escape in the open form
- **THEN** the form closes without saving and Edit is focused

### Requirement: Deleting from the Notes page is confirmed

Pressing **Delete** SHALL show a confirmation naming what is deleted (article note, page note or highlight) taken from the button's `data-confirm` attribute; the delete SHALL be submitted only when it is accepted.

#### Scenario: Dismissing the confirmation
- **WHEN** a user presses Delete and dismisses the confirmation
- **THEN** the item is kept

#### Scenario: Accepting the confirmation
- **WHEN** a user presses Delete and accepts the confirmation
- **THEN** the item is deleted and "Deleted." is shown
