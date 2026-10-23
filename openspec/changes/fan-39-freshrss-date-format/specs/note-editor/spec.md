## MODIFIED Requirements

### Requirement: Note display

The extension SHALL append the signed-in user's note to an article's content when the article has a live note, as a block
titled "Your note" containing the note as escaped text with line breaks preserved (no HTML or Markdown rendering) and the
last-updated time, written with FreshRSS's own date format (`timestamptodate()`, e.g. "23 October 2026 at 01:14") while the
`datetime` attribute stays machine-readable. The Notes page SHALL write each group's publication date the same way, without
the time. Articles without a note, or whose note was deleted, SHALL be left untouched.

#### Scenario: Note with markup
- **WHEN** an article's note is `<script>alert(1)</script>` followed by a second line
- **THEN** the block shows the markup as text and the last-updated time in FreshRSS's date format
