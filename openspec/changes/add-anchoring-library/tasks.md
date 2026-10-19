## 1. Implementation

- [x] 1.1 Text model (`textOf`): ignored elements, block and `<br>` separators, cached per root, invalidated by mutations
- [x] 1.2 `describe()`: collapsed quote, 64-character context, positions, typed errors (`empty`, `too-long`, `outside-root`)
- [x] 1.3 `anchor()`: verified position, context-scored quote search, whitespace-insensitive search, `null` when orphaned
- [x] 1.4 `highlight()`: `<mark>` wrapping by DOM operations, overlapping highlights, `remove()` restoring the DOM
- [x] 1.5 Browser unit tests and coverage report; ESLint in `bin/lint` / `bin/ci`
- [x] 1.6 README, CHANGELOG, AGENTS.md, specs
