# Single image: FrankenPHP (runs Octane) + built frontend assets. Same image self-host and cloud.
FROM node:22-alpine AS assets
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM dunglas/frankenphp:1-php8.3 AS app
RUN install-php-extensions pdo_pgsql pdo_sqlite intl bcmath opcache zip
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
