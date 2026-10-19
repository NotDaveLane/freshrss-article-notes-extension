## ADDED Requirements

### Requirement: Extension pages render without PHP diagnostics

Every page the extension renders inside FreshRSS's layout SHALL be rendered with a `FreshRSS_View` (its controllers extend
`FreshRSS_ActionController`) and SHALL produce no PHP warning, notice, deprecation or error, so that FreshRSS's layout
renders normally and nothing is printed above its header or logged.

#### Scenario: Settings page on mobile and desktop
- **WHEN** a signed-in user opens the settings page at 390x844 or 1440x900 on the dev instance (which displays PHP
  diagnostics)
- **THEN** the response contains no PHP diagnostic and FreshRSS's header, Configuration menu and the settings form render
