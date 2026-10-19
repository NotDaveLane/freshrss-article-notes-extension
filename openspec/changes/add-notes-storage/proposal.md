# Add notes storage

## Why

Every note feature (UI, labels, API) needs durable per-user storage and a single write path.

## What changes

- Migration `0002` creates the per-user `articlenotes_note` table.
- `NoteDAO` (prepared statements) and `NoteService` (validation, snapshot, tombstones, listeners).
- New `notes-storage` capability.
