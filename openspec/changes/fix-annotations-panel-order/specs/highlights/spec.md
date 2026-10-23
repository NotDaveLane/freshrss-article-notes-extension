## ADDED Requirements

### Requirement: Annotations panel lists highlights in reading order

The Annotations panel under an opened article SHALL list the article's highlights in reading order: highlights drawn in
the text by where they start in it, first one first; when either of two highlights is not drawn ("Passage not found"), by
`position.start` (one without a position after those with one), then by creation. The order SHALL be the same in the
normal and the reading view and after a reload, and SHALL match the Notes page's order for the same article.

#### Scenario: Highlights created out of text order
- **WHEN** an article has highlights on "component model", "modules", "typed interfaces" and "different languages"
  (created in that order) and one whose passage is gone with `position.start` 60
- **THEN** the panel lists "component model", "modules", "different languages", the missing passage, then "typed
  interfaces", in the normal and the reading view, after a reload, and on the Notes page
