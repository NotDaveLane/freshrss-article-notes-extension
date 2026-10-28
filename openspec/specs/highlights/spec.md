# highlights Specification

## Purpose
Store highlights (selected passages with an optional note, on FreshRSS articles or any web page) and expose them through the API and to FreshRSS.

## Requirements

### Requirement: Highlight storage

Each user SHALL have a table `articlenotes_highlight` (FreshRSS's per-user table prefix), created by an idempotent migration
`0006` that works on SQLite, MySQL/MariaDB and PostgreSQL and leaves notes and page notes untouched. `cli/delete-data.php` SHALL drop it.

#### Scenario: Columns and indexes
- **WHEN** the migration has run
- **THEN** the table has a unique `uuid`, a nullable `entry_id`, `url`, `normalized_url`, a page `title`, the article snapshot, `source`, the selector as JSON text, `color`, `note`, `created_at`, `updated_at`, `deleted_at` and `label_applied`/`label_id`, indexed on `entry_id`, the normalized-URL hash and `updated_at`

#### Scenario: Upgrade from 1.0
- **WHEN** a user whose schema is at 0005 has article notes and page notes and the migration runs (twice)
- **THEN** the highlights table exists, every note row is unchanged and no highlight is lost by the second run

#### Scenario: Unique uuid
- **WHEN** a second row with an existing `uuid` is inserted
- **THEN** the database refuses it

### Requirement: Selector validation

`HighlightService` SHALL be the only writer of highlights and SHALL refuse a `selector` that is not
`{"quote": {"exact", "prefix"?, "suffix"?}, "position"?: {"start", "end"}}` with `invalid_selector`; unknown keys SHALL be dropped.

#### Scenario: Invalid selectors
- **WHEN** `exact` is missing, blank or over 2,000 characters, `prefix` or `suffix` is over 64 characters, or `position` is not integers with `0 <= start < end`
- **THEN** the highlight is refused with `invalid_selector`

#### Scenario: Boundaries
- **WHEN** a highlight has a 2,000-character `exact`, 64-character `prefix`/`suffix`, a 500-character title and a 20,000-character note
- **THEN** it is stored

### Requirement: Value validation

`HighlightService` SHALL refuse a `source` other than `freshrss` or `page` (`invalid_source`), a `color` other than `yellow`
(the default), `green`, `blue`, `pink` or `purple` (`invalid_color`), a note over 20,000 characters (`note_too_long`), a title over 500 characters
(`title_too_long`) and a `uuid` that is not a UUID v4 (`invalid_uuid`). Notes SHALL be stored as entered.

#### Scenario: Bad color
- **WHEN** a highlight is created with the color `red`
- **THEN** it is refused with `invalid_color` and nothing is stored

### Requirement: Highlight limit

A target (an article, or a page URL) SHALL have at most 1,000 live highlights; one more SHALL be refused with `too_many_highlights`.

#### Scenario: Too many
- **WHEN** an article has 1,000 live highlights and another is created
- **THEN** it is refused, and after one is deleted the creation succeeds

### Requirement: Highlight targets

A highlight with an `entry_id` SHALL be attached to that article (snapshotting it); a highlight with only a `url` SHALL be
attached to the article that URL stands for (same lookup as `GET v1/lookup`) or, when none matches, SHALL be a page
highlight. A request with neither SHALL be refused with `invalid_target`, a `url` that is not http(s) with `invalid_url`,
and an `entry_id` the user does not have with `unknown_entry` (404).

#### Scenario: URL of an article
- **WHEN** a highlight is created for a URL that is a FreshRSS article's link
- **THEN** it has that article's `entry_id`, snapshot and URL

#### Scenario: Page highlight
- **WHEN** a highlight is created for a URL that matches no article
- **THEN** `entry_id`, `greader_item_id` and `article` are null

### Requirement: Idempotent creation

A client MAY supply the `uuid` on create. The same `uuid` on the same target SHALL return the existing highlight with 200
instead of creating another; the same `uuid` on another target, or of a deleted highlight, SHALL be refused with 409
`uuid_conflict`. Without a `uuid` the server SHALL generate a UUID v4.

#### Scenario: Retry
- **WHEN** a client repeats a create request with the same uuid and payload
- **THEN** the second response is 200 with the same highlight and only one exists

### Requirement: Update and delete

Only `note` and `color` SHALL be changeable (`PATCH`); the selector is immutable (`selector_immutable`). A change SHALL set
`updated_at` strictly after the previous value. Deleting SHALL keep a tombstone (`deleted_at`) whose note is not
exposed, and a deleted highlight SHALL be neither readable by uuid nor listed for its target.

#### Scenario: Immutable selector
- **WHEN** a `PATCH` body contains a `selector`
- **THEN** the response is 422 `selector_immutable` and nothing changes

### Requirement: Highlight write endpoints

The API SHALL provide `POST v1/highlights` (201 with `{"highlight": {…}}`, 200 for an existing identical uuid), `PATCH v1/highlights/<uuid>`
(`note` and/or `color`) and `DELETE v1/highlights/<uuid>` (204), with the authentication, JSON errors, `ETag`/`If-Match` (412), body limit and method rules of the other write endpoints.

#### Scenario: Stale If-Match
- **WHEN** a client sends `PATCH` or `DELETE` with an `If-Match` that is not the current ETag
- **THEN** the response is 412 `precondition_failed` and nothing changes

### Requirement: Highlight read endpoints

`GET v1/highlights/<uuid>` SHALL return the live highlight with its `ETag`. `GET v1/highlights?entry_id=` or `?url=` SHALL return the
target's live highlights in document order (by `position.start` when present, else creation). `GET v1/highlights?since=&limit=&continuation=&include_deleted=`
SHALL be a changed-since sync with the paging contract of `v1/notes`, answering `{"highlights", "continuation", "server_time"}`.

#### Scenario: Document order
- **WHEN** an article has highlights with positions 50, none, 5 and 20
- **THEN** the list is ordered 5, 20, 50, then the one without a position

#### Scenario: Sync
- **WHEN** a client pages through `v1/highlights?since=0&limit=2` with the continuation
- **THEN** it sees every highlight once, tombstones included

#### Scenario: Another user
- **WHEN** user B requests user A's highlight uuid
- **THEN** the response is 404

### Requirement: Highlight object

A highlight SHALL be returned as `{"id", "uuid", "kind": "highlight", "entry_id", "greader_item_id", "url", "normalized_url",
"title", "source", "selector", "color", "note", "created_at", "created_at_iso", "updated_at", "updated_at_iso", "deleted_at",
"deleted_at_iso", "label_applied", "article"}`, where `article` is the note snapshot object (with `exists`) or null for a page
highlight, `greader_item_id` is set when there is an article, and `note` is null when empty or deleted.

#### Scenario: Purged article
- **WHEN** FreshRSS purged the article of a highlight
- **THEN** the highlight is still returned with its snapshot and `article.exists` false

### Requirement: Annotated label follows notes and highlights

The "Annotated" label SHALL be applied (per the user's settings) when an article gains its first article note or highlight, and
SHALL be removed, only if the extension applied it, when the article has neither a live note nor a live highlight. Page
highlights SHALL never be labelled.

#### Scenario: Last annotation
- **WHEN** an article has a note and a highlight and the note is deleted
- **THEN** the label stays; deleting the highlight too removes it

#### Scenario: User's own label
- **WHEN** the user had already put that label on the article
- **THEN** the extension never removes it

### Requirement: FreshRSS highlight actions

The extension controller SHALL provide `highlights` (GET or POST, signed-in users only, returns an article's highlights as
`{"ok": true, "highlights": […]}`) and the POST-only, CSRF-protected `highlightCreate`, `highlightUpdate` and `highlightDelete`
(JSON `{"ok": true, "highlight": {…}}`), all through `HighlightService`. Refusals SHALL be 403 for signed-out users or a bad
CSRF token, 405 for a non-POST write, and the service's status with a translated message otherwise.

#### Scenario: Missing CSRF token
- **WHEN** a signed-in user posts a highlight without the CSRF token
- **THEN** the response is 403 and nothing is stored

### Requirement: Highlight deletion is confirmed

The highlight popover's **Delete** button SHALL be styled `btn-attention` and SHALL ask for confirmation (`ext.article_notes.highlight.confirm_delete`, passed to the script by `jsVars()`) before calling the delete endpoint.

#### Scenario: Dismissing the confirmation
- **WHEN** a user presses Delete in the popover and dismisses the confirmation
- **THEN** the highlight is kept and the popover stays open

#### Scenario: Accepting the confirmation
- **WHEN** a user presses Delete in the popover and accepts the confirmation
- **THEN** the highlight is deleted

### Requirement: Floating UI matches the article colours

The highlight popover, selection toolbar and error notice (appended to `<body>`) SHALL use the text colour and the first opaque background colour of the article they belong to (`--articlenotes-text` / `--articlenotes-surface`, set by the script), so that their text keeps at least 4.5:1 contrast in FreshRSS's light and dark themes. Translucent article backgrounds SHALL be skipped so the page behind is never visible through them.

#### Scenario: Popover in a dark theme
- **WHEN** a user with the Dark or Alternative-Dark theme opens a highlight's popover
- **THEN** the popover background equals the article background and its quote and label have at least 4.5:1 contrast with it

#### Scenario: Toolbar and notice in a dark theme
- **WHEN** a user with a dark theme selects text, or a save fails
- **THEN** the toolbar and the notice use the article's colours with at least 4.5:1 contrast

### Requirement: Highlighted text is readable in every theme

Highlighted text SHALL keep at least 4.5:1 contrast (WCAG AA) with its highlight colour, in every highlight colour, in the default theme (light and dark colour schemes) and in every bundled dark theme, including text inside links and inline formatting. Each `<mark>` wraps a single text node, so its own dark text colour applies whatever the theme sets on the elements around it.

#### Scenario: Highlight across a link and inline formatting in a dark theme
- **WHEN** a user with the Dark, Alternative-Dark, Dark-pink or Nord theme, or Origine / Origine-compact in the dark colour scheme, highlights text that spans a link, bold, emphasis and code, and switches the highlight through yellow, green, blue, pink and purple
- **THEN** every highlighted piece of text has at least 4.5:1 contrast with the highlight background

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

### Requirement: Highlight tap targets on touch
On touch screens and narrow viewports (`(pointer: coarse), (max-width: 40em)`) every button and link of the selection toolbar, the highlight bottom sheet and the Annotations panel, colour swatches and "All my notes" included, SHALL be at least 44×44 CSS pixels, without horizontal overflow; with a fine pointer on a wide viewport they SHALL keep their regular size.

#### Scenario: Controls on a phone
- **WHEN** the user selects text, then taps a highlight, at 390×844 with touch
- **THEN** every button and link in the toolbar, the bottom sheet and the Annotations panel is at least 44×44px
- **AND** the swatches are at least 0.4rem apart and the toolbar wraps onto several rows inside the viewport without horizontal overflow
- **AND** the decorative colour dots in the Annotations panel list, which are not controls, stay 1rem wide

#### Scenario: Controls on desktop
- **WHEN** the user selects text and clicks a highlight at 1440×900 with a mouse
- **THEN** the toolbar's Highlight button and swatches and the popover's Close button keep their 40px height

### Requirement: Script start independent of load order
The extension's scripts SHALL wait until FreshRSS has provided the extension configuration before decorating articles, so every article in the list gets its highlights and toolbar regardless of the order in which the scripts finish loading.

#### Scenario: FreshRSS main script loads late
- **WHEN** `main.js` finishes after the extension script has executed
- **THEN** saved highlights are drawn and the selection toolbar works once the page has loaded

#### Scenario: Extension script loads late
- **WHEN** the extension script finishes after `main.js`
- **THEN** saved highlights are drawn and the selection toolbar works once the page has loaded
