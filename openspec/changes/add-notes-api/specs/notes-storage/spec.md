## MODIFIED Requirements

### Requirement: Stable change listing

`changedSince` SHALL return notes (tombstones included unless the caller excludes them) ordered by `updated_at` then
`id`, limited to a requested count, and SHALL be pageable by passing the last note's `updated_at` and `id`.

#### Scenario: Same second
- **WHEN** two notes share an `updated_at`
- **THEN** they are returned in `id` order and paging by `(updated_at, id)` skips none

#### Scenario: Live notes only
- **WHEN** the caller excludes tombstones
- **THEN** deleted notes are skipped and the page is still filled up to the requested count with live notes
