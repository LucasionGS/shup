#!/bin/sh
set -e

cd /var/www/html

# The storage subtree lives on a volume, so the directories Laravel expects may
# not exist on a fresh install.
mkdir -p \
    storage/app/private/files \
    storage/app/private/directories \
    storage/app/private/thumbnails \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# Publish static assets to the volume nginx serves. Done on every start so a
# redeploy refreshes them rather than leaving the first build's assets in place.
if [ -d /srv/public ]; then
    cp -a public/. /srv/public/
fi

if [ -z "${APP_KEY}" ] && ! grep -q '^APP_KEY=.\+' .env 2>/dev/null; then
    echo "APP_KEY is not set. Generate one with:"
    echo "  docker compose run --rm app php artisan key:generate --show"
    echo "and put it in .env.docker before starting the stack."
    exit 1
fi

# Only the main web container should run migrations; the queue and scheduler
# containers share this image but must not race it.
if [ "${SHUP_RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "Waiting for the database..."
    until php artisan db:monitor >/dev/null 2>&1; do
        sleep 2
    done

    php artisan migrate --force
fi

# Cache configuration, routes and views for production. Skipped when debugging
# so a developer shell sees live changes.
if [ "${APP_DEBUG:-false}" != "true" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

exec "$@"
