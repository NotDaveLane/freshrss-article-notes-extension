## ADDED Requirements

### Requirement: Script start independent of load order
The extension's scripts SHALL wait until FreshRSS has provided the extension configuration before decorating articles, so every article in the list gets its Note action regardless of the order in which the scripts finish loading.

#### Scenario: FreshRSS main script loads late
- **WHEN** `main.js` finishes after `article-notes.js` has executed
- **THEN** every `.flux` article has a Note action once the page has loaded

#### Scenario: Extension script loads late
- **WHEN** `article-notes.js` finishes after `main.js`
- **THEN** every `.flux` article has a Note action once the page has loaded
