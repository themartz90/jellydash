#!/bin/sh
set -eu

# A MariaDB-to-SQLite migration runs from a one-off container after the normal
# app has stopped. Do not seed auth or start any background workers first: the
# migration must only read from the MariaDB source.
if [ "${1:-}" = "php" ] \
    && { [ "${2:-}" = "bin/console.php" ] || [ "${2:-}" = "/var/www/html/bin/console.php" ]; } \
    && [ "${3:-}" = "database:migrate-to-sqlite" ]; then
    exec "$@"
fi

mkdir -p /var/www/html/cache \
    /var/www/html/var/cache \
    /var/www/html/var/data \
    /var/www/html/var/log \
    /var/www/html/var/sessions \
    /var/www/html/public/uploads \
    /var/www/html/public/uploads/images

chown -R www-data:www-data \
    /var/www/html/cache \
    /var/www/html/var/cache \
    /var/www/html/var/data \
    /var/www/html/var/log \
    /var/www/html/var/sessions \
    /var/www/html/public/uploads

# Clear compiled Twig templates on start so a deploy's template changes actually
# take effect. Production runs Twig with auto_reload off and the cache dir is a
# persistent volume, so stale compiled templates would otherwise survive the
# rebuild. Only the hashed subdirectories are removed (keeps .htaccess/.gitkeep).
find /var/www/html/cache -mindepth 1 -maxdepth 1 -type d -exec rm -rf {} + 2>/dev/null || true

# Optional auth: seed the initial admin when configured. Idempotent: an
# existing user is never touched, so in-app password changes survive restarts.
if [ -n "${AUTH_ADMIN_USER:-}" ] && [ -n "${AUTH_ADMIN_PASSWORD:-}" ]; then
    gosu www-data php /var/www/html/bin/console.php user:ensure || true
fi

# Resolve the same boolean and interval rules used by the status page. This
# also prevents invalid or zero sleep values from stopping or spinning workers.
worker_config="$(php /var/www/html/bin/worker-config.php)"
read -r workers_enabled POLL_INTERVAL LIBRARIES_CACHE_TTL SEERR_POLL_INTERVAL DOWNLOADS_POLL_INTERVAL <<EOF
$worker_config
EOF

# Each worker runs detached alongside the web server. A failed run never stops
# its loop.
# Expiring external data is local maintenance and also runs when polling is off.
(
    while true; do
        gosu www-data php /var/www/html/bin/console.php artwork:expire || true
        sleep 60
    done
) &
if [ "$workers_enabled" = "1" ]; then
    echo "[entrypoint] starting background workers (history every ${POLL_INTERVAL}s, libraries every ${LIBRARIES_CACHE_TTL}s, jellyseerr every ${SEERR_POLL_INTERVAL}s)"

    # Records currently-playing sessions so history logs even when nobody has
    # the dashboard open.
    (
        while true; do
            gosu www-data php /var/www/html/bin/console.php history:poll || true
            sleep "${POLL_INTERVAL}"
        done
    ) &

    # Retain historical posters separately so image requests never delay samples.
    (
        while true; do
            gosu www-data php /var/www/html/bin/console.php artwork:poll || true
            sleep 30
        done
    ) &

    # Keeps the library overview cache warm so the Libraries page never triggers
    # a cold scan inside a visitor's request.
    (
        while true; do
            gosu www-data php /var/www/html/bin/console.php libraries:warm || true
            sleep "${LIBRARIES_CACHE_TTL}"
        done
    ) &

    # Mirrors Jellyseerr requests and pushes an alert when a new one appears.
    # Requests trickle in far slower than playback, so this runs on its own,
    # longer interval. No-op unless JELLYSEER_URL / JELLYSEER_API_TOKEN are set.
    (
        while true; do
            gosu www-data php /var/www/html/bin/console.php seerr:poll || true
            sleep "${SEERR_POLL_INTERVAL}"
        done
    ) &
    # Reads enabled download clients into the local monitor cache.
    (
        while true; do
            gosu www-data php /var/www/html/bin/console.php downloads:poll || true
            sleep "${DOWNLOADS_POLL_INTERVAL}"
        done
    ) &
else
    echo "[entrypoint] network polling disabled (POLLER_ENABLED is false or invalid); local poster expiry remains active"
fi

exec "$@"
