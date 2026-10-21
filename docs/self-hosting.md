# Self-hosting

FamilyWell ships as a single image (FrankenPHP running Laravel Octane, with the frontend built in). The same image runs locally, self-hosted, and in the cloud. The repo includes a `docker-compose.yml` that brings up everything you need: the app, a Reverb WebSocket server, PostgreSQL, and Redis.

## With Docker Compose (recommended)

```bash
git clone https://github.com/bvdr/familywell.git
cd familywell

cp .env.example .env
php artisan key:generate     # or generate an APP_KEY yourself and paste it into .env

docker compose up -d --build

# one-time database setup
docker compose exec app php artisan migrate --force
docker compose exec app php artisan modules:migrate
```

The app is now on **http://localhost:8000** and the Reverb realtime server on port `8080`. Register the first account (it creates a family), then enable modules from **Settings → Modules**.

The compose file wires the app to its Postgres, Redis, and Reverb services for you, so the only file you edit is `.env` (mainly `APP_KEY`, `APP_URL`, and mail/storage if you want them).

### Before exposing it publicly

The compose file ships with development-grade defaults. For a real deployment:

- **Change the database password.** Set `POSTGRES_PASSWORD` (the `db` service) and the matching `DB_PASSWORD` (the `app` and `reverb` services) to a strong value.
- Set `APP_ENV=production` and `APP_DEBUG=false` in `.env`.
- Set `APP_URL` and the `REVERB_PUBLIC_*` values to your real domain and scheme (`https`).
- Put a reverse proxy (Caddy, nginx, Traefik) in front for TLS, and point it at the app (`8000`) and Reverb (`8080`).
- Configure real [file storage](configuration.md#file-storage-receipts-and-uploads) so receipt photos survive container restarts, and [mail](configuration.md#mail) for invites.

## With the prebuilt image

A prebuilt image is published to the GitHub Container Registry:

```bash
docker pull ghcr.io/bvdr/familywell:latest
```

You can swap `build: .` for `image: ghcr.io/bvdr/familywell:latest` in `docker-compose.yml` to skip building locally, or run the image directly against your own Postgres/Redis by passing the [environment variables](configuration.md) it needs.

## Upgrading

```bash
git pull
docker compose build --pull
docker compose up -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan modules:migrate
```

Run `migrate` and `modules:migrate` after every upgrade, core and module schema changes are applied separately.

On the prebuilt image, `docker compose pull && docker compose up -d` does the same job.

### Keeping PHP patched

PHP itself comes from the base image (`dunglas/frankenphp:1-php8.3`), not from this repo. Docker keeps a cached copy of it, so a PHP security release only reaches you when you build with `--pull` (the compose file now does this on every build) or pull a fresh prebuilt image. Check what you're running:

```bash
docker compose exec app php -v
```

**October 2026 (PHP2Shell, CVE-2026-109618 / CVE-2026-109619):** you need **PHP 8.3.36 or newer**. Older versions allow unauthenticated remote code execution. See the [php.net advisory](https://www.php.net/archive/2026.php). If `php -v` shows 8.3.35 or older after upgrading, the patched base image isn't out yet or wasn't pulled: run `docker compose build --pull` again.

## Realtime without Reverb

Realtime is optional. If you'd rather not run the Reverb service, set `BROADCAST_CONNECTION=log` in `.env` and drop the `reverb` service, the app works fully; members simply refresh to see each other's changes.

See **[configuration.md](configuration.md)** for the complete environment variable reference.
