## ADDED Requirements

### Requirement: Editor placement

The note editor SHALL open in place of the article's note block, which is hidden while the editor is open and shown
again (updated after Save) when it closes; without a note it SHALL open above the Annotations panel, or at the end of the
article text when there is no panel. It SHALL be scrolled into view when opened and its edges SHALL line up with the
article text column.

#### Scenario: Editing an existing note
- **WHEN** a user opens the editor on an article with a note and highlights, from the "Note" action or the note block, at
  1440x900 or 390x844
- **THEN** the editor is in the viewport where the note block was, the note text is shown once (in the textarea), and
  its edges line up with the article text

#### Scenario: Closing the editor
- **WHEN** the user presses Cancel or Escape, or saves
- **THEN** the note block is shown again in its place, with the saved text after Save

#### Scenario: Article without a note
- **WHEN** a user opens the editor on an article with highlights but no note
- **THEN** the editor opens above the Annotations panel, in the viewport
