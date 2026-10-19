# Fix the PHP warning on extension pages and fail the suite on PHP diagnostics

## Why

Release 1.0.0 rendered "Warning: Undefined property: Minz_View::$rss_title" above FreshRSS's header on the settings page:
the extension's controller extended `Minz_ActionController`, so FreshRSS's layout got a plain `Minz_View` instead of a
`FreshRSS_View`. The controller was fixed in FAN-8, but nothing in the suite failed on PHP diagnostics, so this class of
bug could come back unnoticed.

## What changes

- The extension's controller extends `FreshRSS_ActionController` (it is the only controller; its JSON actions keep
  disabling the layout).
- End-to-end guard: every spec imports `test` from `tests/e2e/support/test.ts`, whose automatic fixture fails a test when
  any response of its browser context or `request` fixture contains a PHP diagnostic (HTML or plain-text form).
- `bin/check-php-log`, run by `bin/ci` after the end-to-end suite, fails on PHP diagnostics in the FreshRSS container log
  that come from `xExtension-ArticleNotes/` or from requests to the extension's pages or API. The dev image tags error-log
  lines with the request URI to make that attribution possible; unrelated diagnostics are listed as ignored.
- PHPUnit fails on notices and deprecations as well as warnings.
- Settings page test at 390x844 and 1440x900; CHANGELOG `### Fixed`; AGENTS.md rule.
