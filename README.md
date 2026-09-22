# FamilyWell

**Open-source family organization app.** Shared spending, to-dos, shopping lists, and more, for the people you live with. A small, stable core with self-contained **modules** that hook into it (think WordPress or Home Assistant), so features can be added without touching the core.

Run it yourself for free, or use the hosted version at **[familywell.co](https://familywell.co)**.

[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

---

## What you get

- **Families, not just accounts.** Invite the people you live with; everyone shares the same data with roles (admin / member).
- **Modules you enable per family.** The core stays small; each feature is a folder of code you can turn on or off from **Settings → Modules**.
- **Shared spending** ([spending module](modules/spending)): track expenses on an event (a trip, a month, a project), see who owes whom with automatic balances and settle-up suggestions, split by category and payment method, multi-currency with frozen conversion rates, attach receipt photos, and **scan a receipt to auto-fill a spend** (optional, see [AI receipt scanning](#ai-receipt-scanning-optional)).
- **To-dos and shopping lists** ([todos](modules/todos), [shopping](modules/shopping)): simple shared lists for the household.
- **Granular sharing**: any shareable thing can be *family-wide*, *invited-only*, or *private*, with per-person roles (owner / editor / viewer) and read-only public links.
- **Realtime**: changes made by one member appear for the others live (Laravel Reverb / WebSockets).
- **Installable PWA**: add it to your home screen; it behaves like a native app, including offline-friendly navigation.

## Tech stack

| Layer | Choice |
|---|---|
| Backend | PHP 8.2+, Laravel 12, Laravel Octane (FrankenPHP) |
| Frontend | Inertia.js 2, React 19, TypeScript, Tailwind CSS v4 |
| Realtime | Laravel Reverb (WebSockets) |
| Database | PostgreSQL (production) · SQLite (local, zero-config) |
| Storage | Any S3-compatible bucket (Cloudflare R2 in the cloud build) |
| Build | Vite |

One Docker image runs the same way self-hosted and in the cloud.

---

## Quick start (local development)

**Prerequisites:** PHP 8.2+, [Composer](https://getcomposer.org), Node 22+, and npm. No database server needed, local defaults to SQLite.

```bash
git clone https://github.com/bvdr/familywell.git
cd familywell

composer install
npm install

cp .env.example .env
php artisan key:generate
touch database/database.sqlite

php artisan migrate          # core tables
php artisan modules:migrate  # module tables

composer dev                 # serves the app + queue + logs + Vite together
```

Open http://localhost:8000, register an account (the first user creates a family), then enable a module from **Settings → Modules**.

`composer dev` runs the PHP server, the queue worker, the log tailer, and the Vite dev server concurrently. Run `npm run build` for a production asset build.

---

## Self-hosting

The whole app is a single image with a `docker compose` stack (app + Reverb + Postgres + Redis):

```bash
cp .env.example .env
php artisan key:generate            # or generate an APP_KEY and paste it into .env
docker compose up -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan modules:migrate
```

A prebuilt image is published at `ghcr.io/bvdr/familywell`. See **[docs/self-hosting.md](docs/self-hosting.md)** for the full guide, including storage and Reverb configuration.

---

## Configuration

Everything is configured through environment variables in `.env`. See **[docs/configuration.md](docs/configuration.md)** for the complete reference. Highlights:

### AI receipt scanning (optional)

When a Google Gemini API key is present, spends gain a **Scan receipt** action: snap a photo and the amount, currency, date, category, shop, and a note are read from it and pre-filled for you to review and save. It costs roughly **$0.001 per scan** and reads rotated, low-quality, multilingual receipts.

The feature is **entirely gated on the key**, without it there is no button and the endpoint returns 404. To enable it, get a free key at [aistudio.google.com/apikey](https://aistudio.google.com/apikey) and set:

```dotenv
GEMINI_API_KEY=your-key-here
```

### AI categorisation (optional)

With a [TypeSafe](https://typesafe.ai) key, shopping items added into the catch-all **Other** aisle are sorted into the shop's best-fit aisle (Dairy, Produce, Plumbing, ...) right after they're saved, expenses saved into "Other" get a category the same way, and Spending settings has a one-tap **Sort "Other" with AI** for the backlog, with a progress bar:

```dotenv
TYPESAFE_API_KEY=your-key-here
```

No secrets are ever committed; `.env` and every credential file are git-ignored.

---

## Modules

FamilyWell's features live in `modules/`. Each module is a self-contained folder with its own routes, controllers, models, migrations, and (optionally) a frontend bundle, registered through a service provider and a `module.json` manifest. The bundled modules are `spending`, `todos`, `shopping`, and `hello` (a minimal example).

To learn how the module system works and how to build your own, see **[docs/modules.md](docs/modules.md)**.

## Project structure

```
app/            Core Laravel application (families, auth, sharing, modules)
modules/        Self-contained feature modules (spending, todos, shopping, hello)
resources/js/   React + Inertia frontend (pages, components, layouts)
routes/         Core web routes
docs/           Documentation and design notes
.do/            DigitalOcean App Platform deploy config (non-secret)
```

## Contributing

Contributions are welcome, bug fixes, new modules, docs. Start with **[CONTRIBUTING.md](CONTRIBUTING.md)** for the development workflow and coding conventions.

## License

[MIT](LICENSE).
