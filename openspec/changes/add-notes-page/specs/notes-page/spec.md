## ADDED Requirements

### Requirement: Notes page access and links

The extension SHALL serve a Notes page at `?c=ArticleNotes&a=notes` to every signed-in user (not only administrators) in
FreshRSS's layout, translated and usable on small screens, and SHALL refuse it with 403 to visitors who are not signed in.
The page SHALL be linked from the Configuration menu, from the extension's settings page and from the Annotations panel of an
article ("All my notes").

#### Scenario: Reaching the page
- **WHEN** a signed-in user without administrator rights opens Configuration and chooses "Notes"
- **THEN** the Notes page is shown

#### Scenario: Signed out
- **WHEN** a visitor who is not signed in requests the page
- **THEN** the answer is 403

### Requirement: Groups of annotations

The page SHALL list the user's live article notes, page notes and highlights grouped by target (a FreshRSS article, or a web
page identified by its normalized URL), groups ordered by their most recent annotation, 25 groups per page, with a pager that
keeps the active filters. Only data of the signed-in user SHALL be shown, and all stored text SHALL be shown escaped.

#### Scenario: Grouping
- **WHEN** an article has a note and two highlights and a page has a page note and a highlight
- **THEN** the page shows two groups, each with its note and its highlights

#### Scenario: HTML in a note
- **WHEN** a note contains `<img src=x onerror=alert(1)>`
- **THEN** the text is displayed literally and no image or script is created

#### Scenario: Another user
- **WHEN** two users annotate articles
- **THEN** neither sees the other's annotations, feeds or counts

### Requirement: Group content

A group SHALL show a title (linking to the article in FreshRSS, or to the page in a new tab with `rel="noopener noreferrer"`),
the feed name or site host, the article's publication date, "Article no longer in FreshRSS" when the article was purged, the
note, and the highlights in document order with colour, quote and note. A highlight of an existing FreshRSS article whose
quote is no longer in the article text SHALL be flagged "Passage not found".

#### Scenario: Purged article
- **WHEN** FreshRSS purged an annotated article
- **THEN** its group is still listed, from the snapshot, marked "Article no longer in FreshRSS"

#### Scenario: Orphaned highlight
- **WHEN** a highlight's quote is not in the text of its article anymore
- **THEN** the highlight is listed with its quote and flagged "Passage not found"

### Requirement: Search and filters

The page SHALL offer a search over notes, highlight quotes and titles using portable, case-insensitive `LIKE` matching in which
`%`, `_` and the escape character are ordinary characters, and filters for kind (article notes, page notes, highlights),
colour, feed and "only with a note". The search and filters SHALL combine, SHALL be kept in the query string, and SHALL apply
to the groups' content: a group shows only the annotations that match.

#### Scenario: Search in a quote
- **WHEN** the user searches for text that only occurs in a highlight's quote
- **THEN** only the group of that highlight is listed

#### Scenario: Wildcards are literal
- **WHEN** the user searches for `100%`
- **THEN** only annotations containing the text `100%` match

#### Scenario: Combined filters across pages
- **WHEN** the user filters by kind "page notes" and a search term and goes to the next page
- **THEN** the next page's address and results keep both

### Requirement: Editing in place

The page SHALL let the user change the text of an article note, a page note or a highlight's note, change a highlight's
colour, and delete any of them. Each change SHALL be a POST protected by FreshRSS's CSRF token, SHALL go through
`NoteService` / `HighlightService` (same validation, tombstones and "Annotated" label rules as elsewhere), SHALL report
the outcome, and SHALL return to the same page of the list with the same filters.

#### Scenario: Change a highlight
- **WHEN** the user changes a highlight's colour and note and saves
- **THEN** the change is stored and the list shows the new colour and note

#### Scenario: Missing token
- **WHEN** an edit is posted without a valid CSRF token
- **THEN** nothing changes and an error is shown

### Requirement: Opening an article at a highlight

For a highlight of an article FreshRSS still has, the page SHALL link to the article in FreshRSS with the request
`articlenotes_hl=<uuid>`; the article view SHALL then open the article and scroll to that highlight.

#### Scenario: Jump to
- **WHEN** the user follows "Open article at this highlight"
- **THEN** the article is open and the highlight is in view

### Requirement: Export

The page SHALL offer downloads of the annotations matching the current search and filters: Markdown (grouped like the page,
quotes as blockquotes) and W3C Web Annotation JSON-LD, an `AnnotationCollection` with one `Annotation` per note or
highlight (`id`, `type`, `motivation`, `created`, `modified`, `body` as `TextualBody` when there is text, `target.source`
the URL, `TextQuoteSelector` and `TextPositionSelector` for highlights). The mapping SHALL live in a reusable class
(`WebAnnotation`).

#### Scenario: Filtered Markdown export
- **WHEN** the user exports Markdown while filtering by kind "page notes"
- **THEN** the file contains the matching page notes only and is downloaded as an attachment

#### Scenario: W3C export
- **WHEN** the user exports JSON
- **THEN** every item has `type` `Annotation`, an IRI `id` and a `target` with a `source` URL
