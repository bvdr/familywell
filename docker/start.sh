#!/bin/sh
# The image's start command, so a self-hosted install is just `docker compose up -d`: no key to generate and no
# migrations to remember. familywell.co doesn't use this (App Platform has its own run_command and migrate job).
set -e

# Without APP_KEY every Octane worker dies at boot with FrankenPHP's vague "has not reached
# frankenphp_handle_request()" (issue #267). Make one on first start and keep it in the storage volume;
# config/app.php reads it from there. Never regenerated: a new key would log everyone out and make
# encrypted data unreadable.
if [ -z "$APP_KEY" ] && [ ! -s storage/app.key ]; then
    php artisan key:generate --show > storage/app.key
    echo "Generated an APP_KEY in storage/app.key (kept in the storage volume)."
fi

# Fresh installs get their tables and upgrades get new ones. Core and module schemas migrate separately.
php artisan migrate --force
php artisan modules:migrate

exec php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=8000
