## ADDED Requirements

### Requirement: Highlight hint in the editor

While the article has no highlight, the note editor SHALL show a help line telling the user to select text in the article
to highlight it, referenced from the textarea by `aria-describedby`. It SHALL NOT be shown when the article already has a
highlight.

#### Scenario: Article without highlights
- **WHEN** the user opens the note editor on an article without highlights
- **THEN** the hint "Tip: select text in the article to highlight it." is shown and described by the textarea

#### Scenario: Article with a highlight
- **WHEN** the user opens the note editor on an article that has a highlight
- **THEN** no hint is shown
