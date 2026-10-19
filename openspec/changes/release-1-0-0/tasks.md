## 1. Verification

- [x] 1.1 Full-journey end-to-end spec and the journey user in `bin/e2e`
- [x] 1.2 `bin/ci --compat`; PHPUnit (SQLite, MariaDB, PostgreSQL) and Playwright on FreshRSS 1.27.1 and 1.30.1
- [x] 1.3 Compatibility fixes for FreshRSS 1.27 (hooks, configuration, users, labels on PostgreSQL, migration probe on MySQL)
- [x] 1.4 Keep data on disable; `cli/delete-data.php` with integration tests

## 2. Release

- [x] 2.1 README (installation, upgrading, uninstalling, FAQ, screenshots), community listing draft
- [x] 2.2 `bin/verify-release`: the zip installs on a clean `freshrss/freshrss`
- [x] 2.3 Version 1.0.0, CHANGELOG section, tag `v1.0.0`
