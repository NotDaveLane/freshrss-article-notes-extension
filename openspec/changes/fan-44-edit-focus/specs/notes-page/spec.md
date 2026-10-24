## ADDED Requirements

### Requirement: Edit form focus

Opening an **Edit** disclosure on the Notes page SHALL move focus to its textarea with the caret at the end, and Escape inside the open form SHALL close it and focus **Edit**.

#### Scenario: Opening by keyboard
- **WHEN** a user focuses Edit and presses Enter
- **THEN** the item's textarea is focused

#### Scenario: Escape
- **WHEN** a user presses Escape in the open form
- **THEN** the form closes and Edit is focused
