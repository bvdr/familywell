# Single image: FrankenPHP (runs Octane) + built frontend assets. Same image self-host and cloud.
FROM node:22-alpine AS assets
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM dunglas/frankenphp:1-php8.3 AS app
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
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000"]
