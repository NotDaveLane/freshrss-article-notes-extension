#!/bin/sh
# Development entrypoint: seeds the instance in the background once FreshRSS's own
# entrypoint (install + user creation, driven by FRESHRSS_INSTALL / FRESHRSS_USER) has
# finished and Apache is serving, then hands over to the stock FreshRSS entrypoint.
set -u

/opt/dev/seed.sh &

exec ./Docker/entrypoint.sh "$@"
