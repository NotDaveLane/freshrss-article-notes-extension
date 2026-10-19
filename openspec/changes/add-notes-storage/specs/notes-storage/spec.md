## ADDED Requirements

### Requirement: Per-user notes table

The extension SHALL store notes in a per-user table `articlenotes_note` (FreshRSS's per-user table prefix) created by
idempotent migration `0002`, portable across SQLite, MySQL/MariaDB and PostgreSQL, with a unique `entry_id` and indexes on
`updated_at` and `entry_id`.

#### Scenario: Columns
- **WHEN** the migration has run
- **THEN** the table holds `id`, `entry_id`, `note`, `created_at`, `updated_at`, nullable `deleted_at` (Unix-second integers),
  the snapshot `entry_guid`, `entry_link`, `entry_title`, `entry_author`, `entry_date`, `feed_id`, `feed_name`, `feed_url`,
  and `label_applied`

#### Scenario: Migration run twice
- **WHEN** migration `0002` runs a second time
- **THEN** it succeeds and leaves existing notes untouched

### Requirement: One note per article

A user SHALL have at most one note per article. Saving a note for an article that has one SHALL update it, and saving after
a deletion SHALL revive the same row with `deleted_at` cleared.

#### Scenario: Update
- **WHEN** a note is saved twice for the same article
- **THEN** one row exists with the second text, the original `created_at` and a refreshed `updated_at`

#### Scenario: Revive
- **WHEN** a deleted note is saved again
- **THEN** the tombstone is cleared and the text replaced

### Requirement: Validated saves

`NoteService::save` SHALL trim the text, reject text longer than 20,000 characters (counted as characters, not bytes) with a
`NoteException`, reject an article that does not exist for the current user, and treat empty text as a deletion.

#### Scenario: Multi-byte limit
- **WHEN** a note of 20,000 multi-byte characters is saved
- **THEN** it is stored intact, and 20,001 characters are rejected leaving the stored note unchanged

#### Scenario: Unknown article
- **WHEN** a note is saved for an entry id that does not exist for the user
- **THEN** a `NoteException` is thrown and nothing is stored

### Requirement: Tombstones

`NoteService::delete` SHALL keep the row and set `deleted_at` and `updated_at` to the deletion time. Reads SHALL exclude
tombstones unless asked; the changed-since listing SHALL include them.

#### Scenario: Delete
- **WHEN** a note is deleted
- **THEN** it is no longer returned by `get` and appears in `changedSince` with `deleted_at` set

### Requirement: Stable change listing

`changedSince` SHALL return notes (tombstones included) ordered by `updated_at` then `id`, limited to a requested count, and
SHALL be pageable by passing the last note's `updated_at` and `id`.

#### Scenario: Same second
- **WHEN** two notes share an `updated_at`
- **THEN** they are returned in `id` order and paging by `(updated_at, id)` skips none

### Requirement: Article snapshot

Each save SHALL refresh the note's snapshot of the article and its feed. The note SHALL stay readable from the snapshot after
FreshRSS deletes the article, and SHALL still be deletable.

#### Scenario: Purged article
- **WHEN** the entry row is deleted
- **THEN** the note is still returned with its guid, link, title, author, date and feed details

### Requirement: User isolation

Notes SHALL be stored in each user's own tables; a user SHALL NOT read, list, create or delete another user's notes.

#### Scenario: Other user
- **WHEN** user B queries or annotates user A's article
- **THEN** B sees no note and the save is rejected as an unknown article

### Requirement: Change notifications

`NoteService` SHALL call registered "saved" listeners after a note is created or updated and "deleted" listeners after a live
note is tombstoned, and SHALL run the schema migrator before first use.

#### Scenario: Delete twice
- **WHEN** a note is deleted twice
- **THEN** the deleted listeners are called once
