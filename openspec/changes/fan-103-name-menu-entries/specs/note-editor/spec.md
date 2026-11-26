## ADDED Requirements

### Requirement: Notes page link in the note block

The note block SHALL end with an "All my notes" link to the Notes page (`?c=ArticleNotes&a=notes`), with the same
`a.articlenotes-notes-link` markup and styling as the Annotations panel's link, both when the page is rendered and after
the note is saved in the editor. Inside the Annotations panel the block's link SHALL be hidden, so the panel shows its own
link only once.

#### Scenario: Note-only article
- **WHEN** an article with a note and no highlights is shown in the normal or reading view, on a desktop or mobile screen
- **THEN** its note block shows an "All my notes" link to the Notes page, without horizontal overflow

#### Scenario: Article with highlights
- **WHEN** an article with a note and highlights is shown
- **THEN** the Annotations panel shows exactly one visible "All my notes" link

### Requirement: The note block link does not open the editor

Clicking the note block's "All my notes" link SHALL navigate to the Notes page without opening the note editor; clicking
elsewhere in the block SHALL still open the editor.

#### Scenario: Following the link
- **WHEN** a user clicks the block's title, cancels the editor, then clicks the block's "All my notes" link
- **THEN** the title opens the editor, and the link opens the Notes page without opening it
