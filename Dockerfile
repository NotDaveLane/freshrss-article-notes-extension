ARG FRESHRSS_VERSION=1.30.1
FROM freshrss/freshrss:${FRESHRSS_VERSION} AS base

FROM base AS runtime

# curl: used by the healthcheck and handy for debugging (e.g. reaching the fixtures service).
# hadolint ignore=DL3008
RUN apt-get update \
	&& apt-get install -y --no-install-recommends curl \
	&& rm -rf /var/lib/apt/lists/*

COPY docker/ /opt/dev/
RUN chmod +x /opt/dev/*.sh

ENTRYPOINT ["/opt/dev/entrypoint.sh"]
# Setting ENTRYPOINT resets the inherited CMD, so restate FreshRSS's (cron + Apache).
CMD ([ -z "$CRON_MIN" ] || cron) && \
	. /etc/apache2/envvars && \
	exec apache2 -D FOREGROUND
HEALTHCHECK --interval=5s --timeout=10s --start-period=30s --retries=60 \
	CMD ["/opt/dev/healthcheck.sh"]

# Tooling image: the same pinned FreshRSS plus Composer, PCOV and the PDO drivers, used by
# the `dev` compose service for PHPUnit, PHPStan and PHP_CodeSniffer. FreshRSS's sources stay at
# /var/www/FreshRSS; the repository is mounted at /workspace.
FROM base AS dev
# hadolint ignore=DL3008
RUN apt-get update \
	&& apt-get install -y --no-install-recommends git unzip php-pcov php-mysql php-pgsql php-sqlite3 php-xml php-zip \
	&& rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_HOME=/tmp/composer HOME=/tmp FRESHRSS_PATH=/var/www/FreshRSS
HEALTHCHECK NONE
WORKDIR /workspace
ENTRYPOINT []
CMD ["bash"]
