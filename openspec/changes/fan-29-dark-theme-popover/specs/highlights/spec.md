## ADDED Requirements

### Requirement: Floating UI matches the article colours
The highlight popover, selection toolbar and error notice SHALL use the text colour and the first non-transparent background colour of the article they belong to, so that text keeps at least 4.5:1 contrast in FreshRSS's light and dark themes.

#### Scenario: Popover in a dark theme
- **WHEN** a user with the Dark or Alternative-Dark theme opens a highlight's popover
- **THEN** the quote text has at least 4.5:1 contrast with the popover background, which is dark like the article
