#!/bin/sh

set -eu

render_port="${PORT:-10000}"

case "${render_port}" in
    ''|*[!0-9]*)
        echo "PORT must be a number." >&2
        exit 1
        ;;
esac

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is required. Generate one with: php artisan key:generate --show" >&2
    exit 1
fi

# Render provides the public URL at runtime. Prefer it over a stale APP_URL
# from an earlier deployment so Filament's lazy-loaded Alpine components are
# requested from the public site instead of localhost.
export APP_URL="${RENDER_EXTERNAL_URL:-${APP_URL:-http://localhost}}"
unset ASSET_URL

sed -ri "s/^Listen [0-9]+$/Listen ${render_port}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${render_port}>/" /etc/apache2/sites-available/000-default.conf

php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan migrate --force
php artisan optimize

exec apache2-foreground
