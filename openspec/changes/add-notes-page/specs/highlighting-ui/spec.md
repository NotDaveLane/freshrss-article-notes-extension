## ADDED Requirements

### Requirement: Link to the Notes page and open at a highlight

The Annotations panel SHALL link to the Notes page ("All my notes"). When the article view is loaded with the request
parameter `articlenotes_hl=<uuid>` for a highlight of an article in the list, it SHALL open that article and scroll to the
highlight once it is drawn.

#### Scenario: Panel link
- **WHEN** the user follows "All my notes" in the panel
- **THEN** the Notes page is shown

#### Scenario: Requested highlight
- **WHEN** the article list is opened with `articlenotes_hl` naming a highlight of its article
- **THEN** the article is open and the highlight is scrolled into view
