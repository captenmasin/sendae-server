#!/usr/bin/env bash
set -euo pipefail

cd {SITE_DIRECTORY}
test -s .env
test -s database/database.sqlite

sendae_umask=$(umask)
umask 077
mkdir -p storage/app/private/deploy-backups
sendae_backup=$(mktemp -d "storage/app/private/deploy-backups/$(date -u +%Y%m%dT%H%M%SZ)-XXXXXX")
sqlite3 database/database.sqlite ".backup '$sendae_backup/database.sqlite'"
cp .env "$sendae_backup/.env"
for sendae_key in storage/oauth-private.key storage/oauth-public.key; do
    if [ -f "$sendae_key" ]; then
        cp "$sendae_key" "$sendae_backup/"
    fi
done
umask "$sendae_umask"

git pull --ff-only origin {BRANCH}
{SITE_COMPOSER} install --no-interaction --prefer-dist --optimize-autoloader --no-dev
{SITE_PHP} artisan migrate --force --no-interaction
{SITE_PHP} artisan optimize
{SITE_PHP} artisan queue:restart
{RELOAD_PHP_FPM}
