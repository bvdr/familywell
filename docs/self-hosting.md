# Self-hosting

FamilyWell ships as a single image (FrankenPHP running Laravel Octane, with the frontend built in). The same image runs locally, self-hosted, and in the cloud. The repo includes a `docker-compose.yml` that brings up everything you need: the app, PostgreSQL and Redis, plus an optional Reverb WebSocket server for realtime updates.

## Install

You only need Docker (with Compose 2.24 or newer) on an x86-64 server. In an empty folder:

```bash
curl -fsSL https://raw.githubusercontent.com/bvdr/familywell/main/docker-compose.yml -o docker-compose.yml && docker compose up -d
```

That's it. FamilyWell is on **http://localhost:8000** a few seconds later. On first start the container generates its encryption key (kept in the `storage` volume) and sets up the database, and it applies new migrations on every start after that.

**Create your account:** open the app and sign up with your email. Sign-in uses a 6-digit code sent by email. Until you [set up mail](configuration.md#mail), the email is written to the app's log instead, so read the code from there:

```bash
docker compose logs app | grep "FamilyWell code"
```

The first account creates a family. Then turn features on in **Settings → Modules**.

**Settings** go in a `.env` file next to `docker-compose.yml` (all optional, see the [configuration reference](configuration.md)): your domain in `APP_URL`, mail, S3-compatible storage, and so on. Run `docker compose up -d` again after changing it. Realtime updates are optional and off by default, see [Realtime updates](#realtime-updates).

### On ARM, or running your own changes

The prebuilt image is x86-64 only. On ARM (Raspberry Pi, Apple Silicon, Ampere), or to run a modified copy, clone the repo and build the image yourself. It does the same first-run setup:

```bash
git clone https://github.com/bvdr/familywell.git
cd familywell
docker compose up -d --build
```

### Before exposing it publicly

The compose file ships with defaults meant for trying it out. For a real deployment:

- **Change the database password.** Set `POSTGRES_PASSWORD` (the `db` service) and the matching `DB_PASSWORD` (the `app` and `reverb` services) to a strong value.
- Set `APP_URL` and the `REVERB_PUBLIC_*` values to your real domain and scheme (`https`).
- Put a reverse proxy (Caddy, nginx, Traefik) in front for TLS, and point it at the app (`8000`), plus Reverb (`8080`) if you turned on realtime.
- **Keep Postgres and Redis off the network.** The compose file binds them to `127.0.0.1` (since v1.1.0). If your copy still says `"5432:5432"` or `"6379:6379"`, change it: Docker's published ports bypass host firewalls like ufw, so those lines expose your database to the internet.
- Set up [mail](configuration.md#mail) so sign-in codes and invites reach people.
- **Back up two volumes:** `dbdata` (the database) and `storage` (uploaded photos and the encryption key). Losing the key makes encrypted data unreadable. To keep uploads somewhere else, configure [S3-compatible storage](configuration.md#file-storage-receipts-and-uploads).

## Image tags

The compose file uses `ghcr.io/bvdr/familywell:stable`. The image is public, so no login is needed.

| Tag | What it is |
|---|---|
| `stable` | The latest release. Recommended for self-hosting. |
| `v1.2.3` / `v1.2` | A specific release, if you want to pin and upgrade on your own schedule. Releases are listed on [GitHub Releases](https://github.com/bvdr/familywell/releases). |
| `latest` | Every merge to `main`, the same image familywell.co runs. Newest features, and also half-finished preview modules. |

A release is the exact image familywell.co already ran for that commit, not a separate build. To pin a release, change the `image:` lines in `docker-compose.yml`. You can also run the image directly against your own Postgres and Redis by passing the [environment variables](configuration.md) it needs.

## Upgrading

```bash
docker compose pull && docker compose up -d     # add --profile realtime if you run Reverb
```

Migrations run automatically when the container starts. With `:stable` that moves you to the newest release; with a `vX.Y.Z` tag, edit the tag first. If you build from a clone instead: `git pull && docker compose up -d --build`.

**Coming from v1.1 or older?** Download the new `docker-compose.yml` (the install command above) first. It adds the `storage` volume and the automatic setup. Keep your `.env`: an `APP_KEY` set there still wins over the generated one, so nobody gets logged out.

### Keeping PHP patched

PHP itself comes from the base image (`dunglas/frankenphp:1-php8.3`), not from this repo. Docker keeps a cached copy of it, so a PHP security release only reaches you when you build with `--pull` (the compose file now does this on every build) or pull a fresh prebuilt image. Check what you're running:

```bash
docker compose exec app php -v
```

**October 2026 (PHP2Shell, CVE-2026-109618 / CVE-2026-109619):** you need **PHP 8.3.36 or newer**. Older versions allow unauthenticated remote code execution. See the [php.net advisory](https://www.php.net/archive/2026.php). If `php -v` shows 8.3.35 or older after upgrading, the patched base image isn't out yet or wasn't pulled: run `docker compose build --pull` again.

## Realtime updates

Without realtime the app works fully; family members just refresh to see each other's changes. To have changes appear live, run the bundled Reverb WebSocket server:

1. Add to `.env` (and change `BROADCAST_CONNECTION=log` to `reverb`):

   ```bash
   BROADCAST_CONNECTION=reverb
   REVERB_APP_ID=<any short id, e.g. from: openssl rand -hex 4>
   REVERB_APP_KEY=<openssl rand -hex 16>
   REVERB_APP_SECRET=<openssl rand -hex 32>
   ```

2. Start it with the `realtime` profile:

   ```bash
   docker compose --profile realtime up -d
   ```

Reverb listens on port `8080`. Behind a reverse proxy, set the `REVERB_PUBLIC_HOST` / `REVERB_PUBLIC_PORT` / `REVERB_PUBLIC_SCHEME` values in `docker-compose.yml` to the address browsers use.

See **[configuration.md](configuration.md)** for the complete environment variable reference.

## Troubleshooting

**The app container keeps restarting with `worker public/frankenphp-worker.php has not reached frankenphp_handle_request()`.**
FrankenPHP's message never says why. From v1.2.1 the container checks the app boots before starting it, and prints the real error instead (`docker compose logs app`). The usual causes:
- `APP_KEY` is set but malformed. It must look like `base64:` followed by 44 characters. Remove the `APP_KEY` line from `.env` to let the container generate one.
- `APP_KEY` is empty on v1.1 or older. Download the current `docker-compose.yml` (see [Install](#install)), which generates the key for you.

If it still fails, open an issue with the output of `docker compose logs app`.

**`docker pull` fails with `denied` or `forbidden`.**
The image is public, so this almost always means Docker is sending an old, expired GitHub login. Run `docker logout ghcr.io` and pull again.

**`no matching manifest for linux/arm64`.**
The prebuilt image is x86-64 only. Build it yourself, see [On ARM](#on-arm-or-running-your-own-changes).

**No sign-in code arrives.**
Mail isn't set up yet, so the email went to the log: `docker compose logs app | grep "FamilyWell code"`.
