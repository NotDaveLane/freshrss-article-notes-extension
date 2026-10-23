# note-editor

## Purpose

Add, view, edit and delete the note of an article from the reading views.

## Requirements

### Requirement: Note display

The extension SHALL append the signed-in user's note to an article's content when the article has a live note, as a block
titled "Your note" containing the note as escaped text with line breaks preserved (no HTML or Markdown rendering) and the
last-updated time, written in FreshRSS's own date format (`timestamptodate()`, e.g. "23 October 2026 at 01:14"; the
`datetime` attribute stays machine-readable). The Notes page SHALL write group dates the same way, without the time.
Articles without a note, or whose note was deleted, SHALL be left untouched.

#### Scenario: Note with markup
- **WHEN** an article's note is `<script>alert(1)</script>` followed by a second line
- **THEN** the block shows the characters literally, the line break is rendered, and no element is created from the text

#### Scenario: Deleted note
- **WHEN** the note of an article was deleted
- **THEN** the article is displayed without a note block

### Requirement: Note editor

Every article SHALL offer a "Note" action alongside FreshRSS's article actions that opens an inline editor with a labeled
textarea, Save and Cancel buttons, a Delete button when a note exists, and a counter of characters used against the
20,000-character limit. It SHALL work in the normal, reading and global views and for articles loaded after the page
(infinite scroll), and SHALL use FreshRSS theme colours so it fits the dark themes and small screens.

#### Scenario: Add, edit and delete
- **WHEN** a user saves a note, reloads, edits it and then deletes it
- **THEN** each step is reflected immediately in the note block and persists after reloading

#### Scenario: Dynamically loaded article
- **WHEN** more articles are loaded into the stream
- **THEN** their "Note" action works without reloading the page

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

### Requirement: Footer Note action

The "Note" action in the article footer SHALL show an icon before its label, like FreshRSS's other footer items, keep at
least 8px between itself and the article date, be at least 44x44 px on touch screens and narrow viewports, and indicate
when the article already has a note (bold label, filled icon, an "Edit your note on this article" title instead of "Add a
note to this article"). The indicator SHALL follow saving and deleting the note without reloading the page.

#### Scenario: Spacing and tap target
- **WHEN** an article is shown at 1440x900 or 390x844
- **THEN** the Note action has an icon hidden from assistive technology, keeps the accessible name "Note", is at least 8px
  away from the date, and at 390x844 is at least 44x44 px

#### Scenario: Note indicator
- **WHEN** the user saves a note on an article, reloads, and then deletes the note
- **THEN** the Note action is marked as having a note after saving and after reloading, and is unmarked after deleting

### Requirement: Edit from the note block

Clicking or tapping an existing note block SHALL open the same editor as the "Note" action, both under the article and
inside the Annotations panel, unless the click ends a text selection inside the block.

#### Scenario: Open the editor from the note
- **WHEN** a user clicks or taps an existing note block, under the article or inside the Annotations panel, on a desktop or
  mobile screen
- **THEN** the editor opens prefilled with the note and focused, saving updates the block, and the "Note" action keeps
  opening and closing the editor as before

#### Scenario: Selecting text in the note
- **WHEN** a user selects text inside the note block
- **THEN** the editor does not open, so the text can be copied

### Requirement: Editor placement

The note editor SHALL open in place of the article's note block, which is hidden while the editor is open and shown
again (updated after Save) when it closes; without a note it SHALL open above the Annotations panel, or at the end of the
article text when there is no panel. It SHALL be scrolled into view when opened and its edges SHALL line up with the
article text column.

#### Scenario: Editing an existing note
- **WHEN** a user opens the editor on an article with a note and highlights, from the "Note" action or the note block, at
  1440x900 or 390x844
- **THEN** the editor is in the viewport where the note block was, the note text is shown once (in the textarea), and
  its edges line up with the article text

#### Scenario: Closing the editor
- **WHEN** the user presses Cancel or Escape, or saves
- **THEN** the note block is shown again in its place, with the saved text after Save

#### Scenario: Article without a note
- **WHEN** a user opens the editor on an article with highlights but no note
- **THEN** the editor opens above the Annotations panel, in the viewport

### Requirement: Editor accessibility

The editor SHALL be keyboard accessible, move focus into the textarea when opened, return focus to the "Note" action when
closed (Save, Cancel, Delete or Escape), and keep typing from triggering FreshRSS keyboard shortcuts. The note block SHALL
be focusable, carry a translated "Tap to edit note" label and title, and open the editor on Enter or Space.

#### Scenario: Cancel
- **WHEN** the user presses Escape or Cancel in the editor
- **THEN** the editor closes without saving and focus returns to the "Note" action

#### Scenario: Keyboard on the note block
- **WHEN** the user focuses the note block and presses Enter
- **THEN** the editor opens with focus in the textarea, and Escape closes it and moves focus to the "Note" action

### Requirement: Save and delete endpoints

The controller `FreshExtension_ArticleNotes_Controller` SHALL expose POST actions `save` (parameters `id`, `note`) and
`delete` (parameter `id`) at `?c=ArticleNotes&a=save|delete`. They SHALL refuse requests from signed-out users, non-POST
requests and requests without a valid FreshRSS CSRF token.

#### Scenario: Missing CSRF token
- **WHEN** a signed-in user posts without a valid token
- **THEN** the answer is 403 with the security-token message and nothing is stored

#### Scenario: Signed out
- **WHEN** a signed-out client posts
- **THEN** the answer is 403 and nothing is stored

### Requirement: Endpoint responses

The endpoints SHALL call `NoteService` and answer JSON `{"ok":true,"html":"..."}` (the rendered note block, empty when the
note is gone) or `{"ok":false,"error":"..."}` with a translated message and an HTTP status.

#### Scenario: Note too long
- **WHEN** the note exceeds 20,000 characters
- **THEN** the answer is 422 with an inline-displayable message and nothing is stored

#### Scenario: Unknown article
- **WHEN** the article id does not exist
- **THEN** the answer is 404 with a translated message

#### Scenario: Empty text
- **WHEN** the saved text is empty after trimming
- **THEN** the note is deleted and the answer carries an empty `html`
