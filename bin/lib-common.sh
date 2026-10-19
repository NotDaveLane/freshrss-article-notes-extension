# Shared helpers for the bin/ wrappers. Sourced, not executed.
# Everything runs in containers; nothing (PHP, Composer, Node) is needed on the host.

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1

# Run a command in the dev (PHP tooling) container as the host user, so files it writes are yours.
# Set TEST_DB (sqlite|mariadb|postgres) to pick the integration-test database.
dev() {
	docker compose run --rm --no-deps -T --user "$(id -u):$(id -g)" -e TEST_DB="${TEST_DB:-sqlite}" dev "$@"
}

# Install Composer dev dependencies once (or when composer.lock changed).
ensure_vendor() {
	if [ ! -f vendor/autoload.php ] || [ composer.lock -nt vendor/autoload.php ]; then
		dev composer install --no-interaction --no-progress >&2 || return 1
	fi
}

# Succeeds when the FreshRSS dev instance is up and healthy.
stack_is_up() {
	local id
	id="$(docker compose ps -q freshrss 2>/dev/null)" || return 1
	[ -n "$id" ] && [ "$(docker inspect -f '{{.State.Health.Status}}' "$id" 2>/dev/null)" = healthy ]
}

require_stack() {
	if ! stack_is_up; then
		echo "error: the dev stack is not running (or not healthy)." >&2
		echo "       start it with: docker compose up --build -d --wait" >&2
		exit 2
	fi
}
