## MODIFIED Requirements

### Requirement: Change notifications

`NoteService` SHALL call registered "saved" listeners after a note is created or updated, passing whether the note was
created or revived (as opposed to edited while live), and "deleted" listeners after a live note is tombstoned, and SHALL run
the schema migrator before first use.

#### Scenario: Delete twice
- **WHEN** a note is deleted twice
- **THEN** the deleted listeners are called once

#### Scenario: Created flag
- **WHEN** a note is created, edited, deleted and saved again
- **THEN** the saved listeners are told "created" for the first and last save only
