## MODIFIED Requirements

### Requirement: Inline edit form

Each article note, page note and highlight on the Notes page SHALL have an **Edit** disclosure containing a textarea with a visible `<label>` naming the item ("Note", "Page note" or "Note on this highlight"), **Save**, a red `btn-attention` **Delete** and a **Cancel** button. While the form is open, the read-only note text, the **Edit** toggle and the article or page note's heading SHALL be hidden, so the label is the only "Note" shown and Cancel is the way to close the form.

#### Scenario: Opening the form
- **WHEN** a user presses Edit under an article note
- **THEN** the textarea shows with its "Note" label, and neither the Edit button nor the "Note" heading is shown

#### Scenario: Cancel
- **WHEN** a user opens the edit form, changes the text and presses Cancel
- **THEN** the form closes without saving, focus returns to "Edit" and the original note is shown again

