# anchoring Specification

## Purpose

The shared, dependency-free JavaScript library (`xExtension-ArticleNotes/static/anchoring.js`) that turns a text selection
into a highlight selector, finds a stored selector again in a document, and draws highlights. It is used by the FreshRSS
highlighting UI and by third-party clients (for example a browser extension on the original web page). The server
validates and stores selectors; it never anchors them.

## Requirements

### Requirement: Distributable module

The library SHALL be a single ES module in plain modern JavaScript, with no build step and no runtime dependencies,
exporting `describe`, `anchor`, `highlight`, `textOf` and `AnchoringError`. It SHALL be served unmodified from the
extension's static URL (`ext.php?f=xExtension-ArticleNotes/static/anchoring.js`) and be usable by copying the file.

#### Scenario: Static URL
- **WHEN** a client requests `ext.php?f=xExtension-ArticleNotes/static/anchoring.js`
- **THEN** FreshRSS answers 200 with a JavaScript content type and the file's exact content

### Requirement: Text model

`textOf(root)` SHALL return the data of `root`'s text nodes in document order, leaving out `<script>`, `<style>`,
`<noscript>`, `<template>`, form controls, elements that are `hidden` or not displayed, and the extension's own UI
(`[data-articlenotes-ui]`, the note block and editor). The model SHALL reflect the current DOM on every call.

#### Scenario: Ignored content
- **WHEN** an article contains a script, a style element, a hidden span, a `display: none` span and a note block
- **THEN** none of their text is part of `textOf(root)`

#### Scenario: Document changes
- **WHEN** a text node changes or an element becomes hidden after a call
- **THEN** the next call returns the new text

### Requirement: Block separators

The text model SHALL insert a line break at block-element boundaries and for `<br>` where the text has no whitespace there,
so that words of different blocks stay apart. Positions SHALL be offsets into the model text in UTF-16 code units.

#### Scenario: Blocks
- **WHEN** the content is a heading, a paragraph with a `<br>`, list items and table cells without whitespace between them
- **THEN** their texts are separated by line breaks

### Requirement: Describe a selection

`describe(range, root)` SHALL return `{quote: {exact, prefix, suffix}, position: {start, end}}` for a DOM `Range` within
`root`: `exact` is the selected text with whitespace runs collapsed to one space and trimmed, and `position` holds the
offsets of that trimmed text. Boundaries in element nodes SHALL be supported, and the part of a range outside `root`
ignored.

#### Scenario: Collapsed quote
- **WHEN** "quick brown\n fox" is selected in "The   quick brown\n fox jumps…"
- **THEN** `exact` is "quick brown fox" and `position` covers the original text

#### Scenario: Element boundaries
- **WHEN** a selection starts between two elements of a paragraph and ends after a list item
- **THEN** `exact` is the text between those points

### Requirement: Selection context

`prefix` and `suffix` SHALL be up to 64 characters (Unicode code points) of the whitespace-collapsed text just before and
after `exact`.

#### Scenario: Long surroundings
- **WHEN** more than 64 characters precede and follow the selection
- **THEN** `prefix` is the 64 characters just before it and `suffix` the 64 just after

### Requirement: Selection limits

`describe()` SHALL throw an `AnchoringError` whose `code` is `empty` when the selection has no text, `too-long` when
`exact` exceeds 2,000 characters (code points), and `outside-root` when the range does not intersect `root`.

#### Scenario: Length limit
- **WHEN** a selection has 2,000 emoji, or 2,001 letters
- **THEN** the first is described and the second throws `too-long`

#### Scenario: Blank or foreign selection
- **WHEN** a selection holds only whitespace, or lies outside `root`
- **THEN** it throws `empty`, respectively `outside-root`

### Requirement: Anchor by position

`anchor(selector, root)` SHALL return a DOM `Range` at `position` when the position is within the text and the text there,
whitespace-collapsed, equals `exact`; otherwise it SHALL search for the quote.

#### Scenario: Unchanged content
- **WHEN** a selector is anchored in the content it was described in
- **THEN** the range covers the described text

### Requirement: Anchor by quote and context

Without a matching position, `anchor()` SHALL return the occurrence of `exact` in the whitespace-collapsed text whose
surroundings best match `prefix` and `suffix` (characters compared outward from the quote, the unbroken run weighing
double); the distance to `position.start` SHALL only break ties.

#### Scenario: Repeated phrase
- **WHEN** a selector for the second "The red door" (suffix " closes.") has no position
- **THEN** the range is the second occurrence

#### Scenario: Edited content
- **WHEN** a paragraph was inserted before the quote and the punctuation around it changed
- **THEN** the quote is still found, at the occurrence whose context matches best

### Requirement: Whitespace-insensitive fallback

When the quote is not found, `anchor()` SHALL repeat the search with all whitespace removed from the text, the quote and the
context, and SHALL return `null` (the highlight is orphaned) when that fails too.

#### Scenario: Whitespace differences
- **WHEN** the other document has different line breaks, `<br>` instead of paragraphs, or no whitespace at a boundary
- **THEN** the quote is still found

#### Scenario: Orphaned
- **WHEN** the quoted passage no longer exists
- **THEN** `anchor()` returns `null`

### Requirement: Selector validation

`anchor()` SHALL throw an `AnchoringError` with code `invalid-selector` for selectors without a string `quote.exact`, with a
blank one, or with a non-string prefix or suffix, and SHALL ignore a malformed or out-of-range `position`.

#### Scenario: Malformed selector
- **WHEN** the selector is `null`, has no quote, or a numeric prefix
- **THEN** `anchor()` throws `invalid-selector`

### Requirement: Anchoring performance

Anchoring SHALL take under 250 ms in total for 200 selectors, half of them without a position, in a 50 KB article that
nothing has been anchored in yet.

#### Scenario: Many highlights
- **WHEN** 200 selectors are anchored in a fresh 50 KB article
- **THEN** all are found within 250 ms

### Requirement: Draw highlights

`highlight(range, attrs)` SHALL wrap the covered parts of text nodes in `<mark class="articlenotes-hl">` elements carrying
`attrs` as `data-*` attributes, by splitting text nodes and DOM operations only (never building HTML from text), skipping
ignored elements and whitespace-only text between or at the edges of blocks, and update `range` to cover the marked text.

#### Scenario: Across blocks
- **WHEN** a highlight spans a link, emphasis, paragraphs and list items
- **THEN** every covered text is marked and no mark is placed directly inside a list

#### Scenario: No HTML interpretation
- **WHEN** the highlighted text reads `<img src=x onerror=alert(1)>`
- **THEN** it stays text and no element is created

### Requirement: Remove highlights

`highlight()` SHALL return `{marks, remove()}`; `remove()` (idempotent) SHALL unwrap the marks and merge the text nodes
split for them, so that once every highlight is removed the DOM has its original structure. Overlapping and adjacent
highlights SHALL work, removed in any order.

#### Scenario: Overlapping
- **WHEN** four overlapping and adjacent highlights are drawn and removed in different orders
- **THEN** each highlight's marks hold its quote, and the original DOM is restored each time

#### Scenario: Unicode
- **WHEN** quotes contain emoji, combining characters or right-to-left text
- **THEN** they are described, anchored, marked and removed intact
