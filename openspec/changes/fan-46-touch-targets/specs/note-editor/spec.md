## ADDED Requirements

### Requirement: Editor tap targets on touch
On touch screens and narrow viewports (`(pointer: coarse), (max-width: 40em)`) the note editor's Save, Cancel and Delete buttons SHALL be at least 44×44 CSS pixels; with a fine pointer on a wide viewport they SHALL keep their regular size.

#### Scenario: Editor on a phone
- **WHEN** the user opens the editor of an article with a note at 390×844 with touch
- **THEN** Save, Cancel and Delete each have a bounding box of at least 44×44px and the page does not overflow horizontally

#### Scenario: Editor on desktop
- **WHEN** the user opens the editor at 1440×900 with a mouse
- **THEN** the Save button keeps its 40px height
