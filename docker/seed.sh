#!/bin/sh
# Idempotent dev seeding: enable ArticleNotes, subscribe to the seed feeds, fetch them.
# Runs on every boot; each step is safe to repeat. Failures (e.g. no internet) never
# prevent the instance from becoming healthy.
set -u

cd /var/www/FreshRSS || exit 1
DEV_USER="${DEV_USER:-admin}"
MARKER=/var/www/FreshRSS/data/.dev-seeded
run() { runuser -u www-data -- "$@"; }

rm -f "$MARKER"

# Wait until FreshRSS is installed, the user exists and Apache answers.
i=0
until [ -f "data/users/$DEV_USER/config.php" ] && curl -sf -o /dev/null http://localhost/i/; do
	i=$((i + 1))
	[ "$i" -gt 300 ] && { echo 'seed: FreshRSS never became ready' >&2; exit 1; }
	sleep 1
done

echo 'seed: enabling ArticleNotes'
run php /opt/dev/enable-extension.php ArticleNotes || echo 'seed: could not enable the extension' >&2

echo 'seed: importing feeds (existing subscriptions are not duplicated)'
run php -f cli/import-for-user.php -- --user "$DEV_USER" --filename /opt/dev/seed-feeds.opml \
	|| echo 'seed: OPML import failed' >&2

echo 'seed: fetching feeds'
run php -f cli/actualize-user.php -- --user "$DEV_USER" \
	|| echo 'seed: feed fetch failed (offline?); feeds stay empty' >&2

touch "$MARKER"
chown www-data:www-data "$MARKER"
echo 'seed: done'
