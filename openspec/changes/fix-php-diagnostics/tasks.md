## 1. Implementation

- [x] 1.1 Controller extends `FreshRSS_ActionController` (FAN-8); no other view rendering in the extension
- [x] 1.2 Playwright guard fixture (`tests/e2e/support/test.ts`) used by every spec, with its own tests
- [x] 1.3 `bin/check-php-log` (PHPUnit-tested) and its `bin/ci` step; request URI in the dev image's error log
- [x] 1.4 Settings page renders without PHP diagnostics at 390x844 and 1440x900
- [x] 1.5 Reverting the controller fix makes both guards fail (checked)
- [x] 1.6 CHANGELOG, AGENTS.md, OpenSpec
- [x] 1.7 PHPUnit check that every controller extends `FreshRSS_ActionController`
