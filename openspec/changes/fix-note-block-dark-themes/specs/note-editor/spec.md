## ADDED Requirements

### Requirement: Note block readability

The note block SHALL be drawn on the article's own background with the theme's own text colour (no theme overlay
variable such as `--frss-background-color-transparent`, no reduced opacity), so that its title, text and last-updated
line keep a contrast of at least 4.5:1 in FreshRSS's bundled light and dark themes, at rest and on hover.

#### Scenario: Readable in every theme
- **WHEN** a note is saved while the user's theme is Origine (light or dark colour scheme), Alternative Dark, Dark, Nord
  or Ansum
- **THEN** the block's title, text and "Last updated" line have a contrast of at least 4.5:1 against the block's rendered
  background, at rest and on hover
