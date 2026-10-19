#!/bin/sh
# Healthy once FreshRSS answers, the dev user exists and seeding has completed.
[ -f "/var/www/FreshRSS/data/users/${DEV_USER:-admin}/config.php" ] || exit 1
[ -f /var/www/FreshRSS/data/.dev-seeded ] || exit 1
curl -sf -o /dev/null http://localhost/i/
