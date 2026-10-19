ARG FRESHRSS_VERSION=1.30.1
FROM freshrss/freshrss:${FRESHRSS_VERSION}

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
