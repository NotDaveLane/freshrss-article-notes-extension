## ADDED Requirements

### Requirement: Editor errors follow the text
The note editor SHALL hide its error once the text is edited, mark the textarea `aria-invalid="true"` while an error is shown or the note exceeds the limit, reference a visible error from the textarea's `aria-describedby`, and disable Save while the note is over the limit. The counter SHALL format numbers with the page language (`20,001 / 20,000` in English). The highlight popover SHALL hide its error on edit.

#### Scenario: Edit after a failed save
- **WHEN** a save failed and the user types in the textarea
- **THEN** the error is hidden and the textarea is no longer invalid

#### Scenario: Over the limit
- **WHEN** the note has 20,001 characters
- **THEN** Save is disabled, no request is sent, and deleting one character enables Save again
