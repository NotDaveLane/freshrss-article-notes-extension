# settings-and-label

## Purpose

Per-user settings and the automatic label that keeps annotated articles browsable and protected from archiving.

## Requirements

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

### Requirement: Extension pages render without PHP diagnostics

Every page the extension renders inside FreshRSS's layout SHALL be rendered with a `FreshRSS_View` (its controllers extend
`FreshRSS_ActionController`) and SHALL produce no PHP warning, notice, deprecation or error, so that FreshRSS's layout
renders normally and nothing is printed above its header or logged.

#### Scenario: Settings page on mobile and desktop
- **WHEN** a signed-in user opens the settings page at 390x844 or 1440x900 on the dev instance (which displays PHP
  diagnostics)
- **THEN** the response contains no PHP diagnostic and FreshRSS's header, Configuration menu and the settings form render

### Requirement: Settings page information

The settings page SHALL show the user's API endpoint URL (`<base>/api/misc.php/ArticleNotes/`) in a read-only field that
takes the available width (up to a readable maximum), and SHALL state that changes apply to future notes only.

#### Scenario: Endpoint shown
- **WHEN** a user opens the page
- **THEN** the URL ending in `/api/misc.php/ArticleNotes/` is displayed

#### Scenario: Full URL visible and no overflow
- **WHEN** the page is shown at 1440x900
- **THEN** the whole endpoint URL is visible in the field
- **WHEN** the page is shown at 390x844
- **THEN** the field and its Copy button fit the viewport without horizontal scrolling and the URL can be selected

### Requirement: API credentials explained

The API section SHALL state that clients sign in with the user name and the FreshRSS API password (not the login
password), link to FreshRSS's Profile page (`c=user&a=profile`, "External access via API") where it is set, and link to
the API's OpenAPI document (`<endpoint>v1/openapi.json`) as documentation.

#### Scenario: Credentials explained
- **WHEN** a user opens the page
- **THEN** the API section says an API password is needed and links to the Profile page and to `v1/openapi.json`

### Requirement: API setup warnings

The API section SHALL show a FreshRSS warning (`alert alert-warn`) when the instance's API access is disabled
(`api_enabled` false: an administrator can allow it under Configuration → Authentication) and another when the current
user has no API password, and no warning when the API can be used.

#### Scenario: No API password
- **WHEN** a user who never set an API password opens the page
- **THEN** a warning says they have no API password and where to set it

#### Scenario: API access disabled
- **WHEN** the administrator has not allowed API access
- **THEN** a warning says API access is disabled on the instance

#### Scenario: Ready to use
- **WHEN** API access is allowed and the user has an API password
- **THEN** no warning is shown

### Requirement: Copy the endpoint

A **Copy** button next to the endpoint field SHALL copy the URL to the clipboard (with `navigator.clipboard`, or by
copying the selected field where the Clipboard API is unavailable, e.g. over plain HTTP) and report the outcome in a
polite live region: "Copied", or a message asking to copy the selected URL by hand when copying failed.

#### Scenario: Copied
- **WHEN** the user presses Copy
- **THEN** the clipboard holds the endpoint URL and "Copied" is announced

#### Scenario: Copy refused
- **WHEN** the browser refuses the clipboard write
- **THEN** the URL stays selected and the failure message is shown

### Requirement: Settings page introduction

The settings page SHALL start with a paragraph explaining that the Note action adds a note to an article and that
selecting text in an article highlights it. The extension description SHALL mention highlights.

#### Scenario: Introduction shown
- **WHEN** a user opens the settings page
- **THEN** the paragraph about the Note action and selecting text to highlight is displayed under the title

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
