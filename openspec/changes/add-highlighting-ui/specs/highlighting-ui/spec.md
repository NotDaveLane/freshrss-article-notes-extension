## ADDED Requirements

### Requirement: Selection toolbar

When the user selects text inside an article's content, the extension SHALL show a toolbar near the selection (below it on
touch devices) with a "Highlight" action using the default color, a choice of the five colors, and an "Add note" action.
It SHALL NOT show the toolbar for selections that are empty, whitespace-only, or not entirely inside the article content
(FreshRSS's own interface, the article note block and the Annotations panel are outside it), and SHALL show a clear message
instead of the actions for selections of more than 2,000 characters.

#### Scenario: Highlight with one click
- **WHEN** the user selects a passage of an article and activates "Highlight"
- **THEN** the passage is drawn at once in yellow and a highlight with `source` `freshrss` is stored for the article

#### Scenario: Selection outside the content
- **WHEN** the user selects the article title or text spanning from the content into FreshRSS's interface
- **THEN** no toolbar is shown

#### Scenario: Selection that is too long
- **WHEN** the user selects more than 2,000 characters
- **THEN** the toolbar area explains the limit and offers no highlight action

### Requirement: Optimistic create with rollback

The extension SHALL describe the selection with the anchoring library's `describe()` (article content element as root),
save it through the CSRF-protected controller actions with a client-generated UUID, and draw it before the server answers.
When saving fails, it SHALL remove the highlight again and show a translated, inline error.

#### Scenario: Save fails
- **WHEN** the server refuses to create a highlight
- **THEN** the drawn highlight disappears and an alert with the error is shown

### Requirement: Highlights are drawn when an article is displayed

When an article is displayed — in the normal, reading and global views, including articles loaded later by infinite scroll —
the extension SHALL load its highlights and anchor each with the anchoring library, drawing them as `<mark>` elements in
their color, without building HTML from stored text. Highlights with a note SHALL carry an indicator that does not rely on
color alone. Highlights with `source` `page` SHALL be drawn when they anchor. Orphaned highlights SHALL NOT be drawn in the
text. Drawn highlights SHALL be keyboard-focusable with an accessible name that includes the start of the quote.

#### Scenario: Reload
- **WHEN** a user highlights a passage and reloads the page
- **THEN** the same passage is drawn again

#### Scenario: Highlight from the original page
- **WHEN** a highlight with source `page` has a quote that is found in the article
- **THEN** it is drawn in its color

#### Scenario: Overlapping highlights
- **WHEN** two highlights overlap
- **THEN** both are drawn, also after reloading

### Requirement: Highlight popover

Activating a drawn highlight (click, tap, or Enter when focused) SHALL open a popover with its quote, color choices, an
editable note and Delete. Color changes SHALL apply at once and be rolled back with an error when saving fails; saving the
note and deleting SHALL update the article and the panel. Escape SHALL close the popover and return focus to the element
that opened it. Typing in the popover SHALL NOT trigger FreshRSS keyboard shortcuts. Notes SHALL always be shown as text.

#### Scenario: Edit the note
- **WHEN** the user opens a highlight, types a note and saves
- **THEN** the highlight gets the note indicator and the note persists after reloading

#### Scenario: Escape
- **WHEN** the popover is open and the user presses Escape
- **THEN** it closes and the highlight has focus

#### Scenario: HTML in a note
- **WHEN** a note contains `<img src=x onerror=...>`
- **THEN** the characters are shown literally and no element or script is created

### Requirement: Annotations panel

Under an article that has highlights, the extension SHALL show an "Annotations" panel containing the article note block and
the article's highlights in document order, each with its quote excerpt, color, note, and actions "Jump to" (scrolls to and
focuses the highlight) and "Edit" (opens the popover). Orphaned highlights SHALL be listed with "Passage not found", without
"Jump to". The panel SHALL be operable by keyboard alone and SHALL NOT be part of the text used for anchoring.

#### Scenario: Orphan
- **WHEN** a highlight's passage is no longer in the article
- **THEN** the panel lists its quote and note flagged "Passage not found", and it can still be edited and deleted

#### Scenario: Keyboard
- **WHEN** a keyboard user activates "Jump to"
- **THEN** the highlight receives focus, and Enter opens its popover

### Requirement: Fit and translation

Highlight colors SHALL use backgrounds with dark text that stay legible in FreshRSS's default and dark themes. The toolbar
and popover SHALL have touch-sized controls, the popover being a bottom sheet on small screens. All strings SHALL be
translated through the extension's i18n files.

#### Scenario: Strings
- **WHEN** the page is rendered
- **THEN** every label of the toolbar, popover and panel comes from `i18n`
