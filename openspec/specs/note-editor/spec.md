# note-editor

## Purpose

Add, view, edit and delete the note of an article from the reading views.

## Requirements

### Requirement: Note display

The extension SHALL append the signed-in user's note to an article's content when the article has a live note, as a block
titled "Your note" containing the note as escaped text with line breaks preserved (no HTML or Markdown rendering) and the
last-updated time. Articles without a note, or whose note was deleted, SHALL be left untouched.

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

### Requirement: Editor accessibility

The editor SHALL be keyboard accessible, move focus into the textarea when opened, return focus to the "Note" action when
closed (Save, Cancel, Delete or Escape), and keep typing from triggering FreshRSS keyboard shortcuts.

#### Scenario: Cancel
- **WHEN** the user presses Escape or Cancel in the editor
- **THEN** the editor closes without saving and focus returns to the "Note" action

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
