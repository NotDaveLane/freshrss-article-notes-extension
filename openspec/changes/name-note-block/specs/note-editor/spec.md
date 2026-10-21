## MODIFIED Requirements

### Requirement: Editor accessibility

The editor SHALL be keyboard accessible, move focus into the textarea when opened, return focus to the "Note" action when
closed (Save, Cancel, Delete or Escape), and keep typing from triggering FreshRSS keyboard shortcuts. The note block SHALL
be focusable, be a region named by its translated "Your note" heading, carry a translated device-neutral "Edit your note"
title and accessible description (no wording that assumes touch, such as "Tap"), and open the editor on Enter or Space.

#### Scenario: Cancel
- **WHEN** the user presses Escape or Cancel in the editor
- **THEN** the editor closes without saving and focus returns to the "Note" action

#### Scenario: Keyboard on the note block
- **WHEN** the user focuses the note block and presses Enter
- **THEN** the editor opens with focus in the textarea, and Escape closes it and moves focus to the "Note" action

#### Scenario: Accessible name of the note block
- **WHEN** an article with a saved note is shown, in the normal, reading or global view
- **THEN** assistive technology exposes a region named "Your note" with the description "Edit your note", and hovering it
  shows the tooltip "Edit your note"
