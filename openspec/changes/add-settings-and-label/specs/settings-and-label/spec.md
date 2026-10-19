## ADDED Requirements

### Requirement: Per-user settings page

The extension SHALL provide a settings page, available to every signed-in user (not only administrators) and linked from
FreshRSS's Configuration menu, using FreshRSS's configuration layout. It SHALL offer a checkbox "Add a label to articles I
annotate" (on when no value is stored) and a text field "Label name" (default "Annotated"), stored in the user's extension
configuration.

#### Scenario: Defaults
- **WHEN** a user who never saved settings opens the page
- **THEN** the checkbox is checked and the label name is "Annotated"

#### Scenario: Settings are per user
- **WHEN** user A turns the label off and renames it
- **THEN** user B still has the label on and named "Annotated"

### Requirement: Settings page information

The settings page SHALL show the user's API endpoint URL (`<base>/api/misc.php/ArticleNotes/`) with a pointer to the
README's API section, and SHALL state that changes apply to future notes only.

#### Scenario: Endpoint shown
- **WHEN** a user opens the page
- **THEN** the URL ending in `/api/misc.php/ArticleNotes/` is displayed

### Requirement: Validated and protected save

Saving the settings SHALL require a valid CSRF token and a signed-in user, trim the label name, and reject names that are
empty or longer than 63 characters without storing anything. A successful save SHALL be confirmed with FreshRSS's flash
message and a rejection SHALL be reported with an error message.

#### Scenario: Invalid names
- **WHEN** the label name is empty, only whitespace, or 64 characters long
- **THEN** the save is refused and the previous settings remain

#### Scenario: Missing token
- **WHEN** the form is posted without the CSRF token
- **THEN** nothing is stored

### Requirement: Automatic label

When a note is created or revived and the setting is on, the extension SHALL find the user's label with the configured name,
creating it if missing, and apply it to the article. It SHALL record on the note that it applied the label (and which one)
only when the article did not already carry that label. Editing an existing note SHALL NOT apply the label again.

#### Scenario: First note
- **WHEN** a user with default settings annotates an article and has no "Annotated" label
- **THEN** the label is created and the article carries it

#### Scenario: Existing label
- **WHEN** the label already exists
- **THEN** it is reused and no second label is created

#### Scenario: Setting off
- **WHEN** the setting is off and the user annotates an article
- **THEN** the article gets no label

#### Scenario: Custom name
- **WHEN** the label name is "Reading list"
- **THEN** new notes apply the label "Reading list"

### Requirement: Label removal on delete

When a note is deleted, the extension SHALL remove from the article only the label it applied itself, never one the user had
added. Turning the setting off or renaming the label SHALL affect future notes only.

#### Scenario: Extension-applied label
- **WHEN** a note whose label was applied by the extension is deleted
- **THEN** the article no longer carries the label

#### Scenario: User's own label
- **WHEN** the article already carried the label before the note was added, and the note is deleted
- **THEN** the label stays

#### Scenario: Renamed label
- **WHEN** the label is renamed after a note was added, and that note is deleted
- **THEN** the label applied at the time is removed
