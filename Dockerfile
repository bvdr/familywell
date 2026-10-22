# Single image: FrankenPHP (runs Octane) + built frontend assets. Same image self-host and cloud.
FROM node:22-alpine AS assets
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM dunglas/frankenphp:1-php8.3 AS app
# Refuse to build on a PHP with known critical holes. A stale cached base (docker build without --pull) would
# otherwise quietly ship one. 8.3.36 fixes PHP2Shell (CVE-2026-109618/-109619, unauthenticated RCE, Oct 2026).
RUN php -r 'if (version_compare(PHP_VERSION, "8.3.36", "<")) { fwrite(STDERR, "PHP ".PHP_VERSION." is vulnerable (PHP2Shell). Rebuild with --pull to get 8.3.36+.\n"); exit(1); }'
RUN install-php-extensions pdo_pgsql pdo_sqlite intl bcmath opcache zip pcntl
# PHP's 2M default drops real receipt photos (iPhone JPEGs are several MB) before the app sees them,
# surfacing as "the receipt failed to upload". Raise the upload/body limits well above the app's cap.
RUN printf "upload_max_filesize=16M\npost_max_size=20M\nmemory_limit=256M\n" > "$PHP_INI_DIR/conf.d/uploads.ini"
# Production hardening from php.net's PHP2Shell advisory (CVE-2026-109618/-109619, Oct 2026), kept permanently:
# upload-progress tracking is one of the attacker-controlled memory-pressure paths and the app never uses it,
# and PHP errors must never reach the browser (Laravel already turns display_errors off; this covers anything
# that fails before Laravel boots). Not a substitute for a patched PHP: that comes from the base image.
RUN printf "session.upload_progress.enabled=0\ndisplay_errors=0\n" > "$PHP_INI_DIR/conf.d/hardening.ini"
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction
COPY . .
COPY --from=assets /app/public/build ./public/build
RUN composer install --no-dev --optimize-autoloader --no-interaction
# One production-grade image: FrankenPHP serves Octane (multi-threaded), no nginx+php-fpm to orchestrate.
# Octane keeps the app in memory between requests, so tenant state must never live in a raw static (see tenancy).
EXPOSE 8000
# Refuse to start without APP_KEY. Octane warms the encrypter while booting its workers, so an empty key kills
# every worker and FrankenPHP only says "worker ... has not reached frankenphp_handle_request()" (issue #267,
# still hit by self-hosters after #275). The key comes from the environment (compose env_file) or a mounted .env.
CMD ["sh", "-c", "if [ -z \"$APP_KEY\" ] && ! grep -qs '^APP_KEY=..*' .env; then echo 'APP_KEY is empty. Generate one with: docker compose run --rm --no-deps -T app php artisan key:generate --show' >&2; echo 'then set APP_KEY=<that value> in .env and start again. See docs/self-hosting.md.' >&2; exit 1; fi; exec php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=8000"]
