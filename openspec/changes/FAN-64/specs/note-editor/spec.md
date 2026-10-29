## ADDED Requirements

### Requirement: Unsaved note text is not silently discarded
The editor SHALL keep changed text when closed with Escape or the "Note" action and restore it, with an "Unsaved draft restored." hint, on reopening; Cancel SHALL ask for confirmation when the text changed; and leaving the page SHALL trigger the browser's prompt while unsaved text exists.

#### Scenario: Escape then reopen
- **WHEN** the user types, presses Escape and reopens the editor
- **THEN** the typed text is restored with the hint

#### Scenario: Unchanged editor
- **WHEN** the user presses Escape in an unchanged editor
- **THEN** it closes immediately without a dialog
