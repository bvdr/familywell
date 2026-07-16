# Familywell Cloud Deployment to DigitalOcean (P8)

> **For agentic workers:** Infra plan, not a TDD plan. The prove-it rule still holds, but the proof is a
> live HTTP response from the deployed app, not a unit test. Every step that touches DigitalOcean uses the
> `fw-do` wrapper, never `doctl` directly. Always wrap potentially-blocking shell commands in `timeout`.

**Goal:** Familywell runs on DigitalOcean App Platform at a public HTTPS URL, backed by managed Postgres,
with live sync (Reverb) working end to end, at the smallest sensible monthly cost for EU users.

**Architecture:** One App Platform app in `fra` (Frankfurt, EU, DO's default region) built from the existing
`Dockerfile`. The same image runs two services: `web` (FrankenPHP/Octane, port 8000) and `reverb` (the
websocket server, port 8080), plus a `migrate` PRE_DEPLOY job. A managed Postgres cluster backs the app.
Object storage is Cloudflare R2 via Laravel's `s3` disk (R2 is S3-compatible and already paid for, so no
DO Spaces).

**Tech Stack:** P1-P7 stack unchanged. New: `.do/app.yaml` app spec, `fw-do` for provisioning.

## Global Constraints

- **Secrets never enter the repo.** `.do/app.yaml` is committed and therefore contains no credential values.
  Every secret is set out-of-band via `fw-do apps update` with `type: SECRET`, or lives in the untracked
  `.env`. Secrets are never echoed to the terminal: pipe `op` output straight into the consumer.
- **R2, not Spaces.** Object storage credentials come from 1Password (`Cloudflare R2 / Service Token`,
  vault `folio-dev`).
- **Cost minimal.** Smallest instance and DB tiers that actually work. Report the real number.

## Key findings from reading the code (these shape the design)

1. **No queue worker needed.** `TodosChanged`, `ShoppingChanged` and `FamilyPinged` all implement
   `ShouldBroadcastNow`, so they broadcast synchronously in the request. Nothing implements `ShouldQueue`,
   `MustVerifyEmail` is commented out in `app/Models/User.php`, and no mail is dispatched. A worker service
   would be $5/month for zero jobs, so there is none. Add one when the first `ShouldQueue` job lands.
2. **No Redis needed.** `SESSION_DRIVER`, `CACHE_STORE` and `QUEUE_CONNECTION` are all `database`. Reverb
   only needs Redis when `REVERB_SCALING_ENABLED=true`, which is only required to run more than one Reverb
   instance. We run exactly one, so `REVERB_SCALING_ENABLED=false` and we skip a $15/month managed Valkey.
   **This is the constraint that pins `reverb` at `instance_count: 1`.**
3. **No Vite build args needed.** P4 already injects the Reverb connection at runtime via
   `window.__familywellReverb` (`app/Http/Middleware/ShareRealtime.php` -> `resources/views/app.blade.php`),
   not at bundle build time. The image is host-agnostic; the same build runs anywhere.
4. **`/up` health endpoint already exists** (`bootstrap/app.php`, `health: '/up'`). Use it for the health check.
5. **`ShareRealtime` calls `env()` at request time**, outside a config file. This works only while the values
   are real OS environment variables (App Platform sets them as such) *and* `config:cache` has not orphaned
   the `.env` load. The Dockerfile deliberately does not run `config:cache`. **Verify this live** rather than
   assuming: the browser must actually receive a non-null `window.__familywellReverb`.
6. **No route conflict on `/app`.** `routes/web.php` has no `/app` or `/apps` route, so both can be routed to
   the Reverb service at the ingress.

## Cost model (to be confirmed against the real bill)

| Component | Tier | $/month |
|---|---|---|
| `web` service | `basic-xxs` (1 shared vCPU, 512 MiB) | 5 |
| `reverb` service | `basic-xxs` (1 shared vCPU, 512 MiB) | 5 |
| `migrate` job | PRE_DEPLOY, billed per second, runs seconds per deploy | ~0 |
| Managed Postgres | smallest single node | ~15 |
| **Total** | | **~25** |

The two-service split is the honest shape: Reverb is a long-lived websocket process and cannot share
FrankenPHP's request lifecycle. Cramming both into one container to save $5/month is the kind of thing
that pages you at 3am.

## Deferred (honest)

- **SMTP is deliberately unset.** Bogdan has not decided between reusing the Folio Resend key and minting a
  FamilyWell-scoped one. `MAIL_MAILER` stays `log`. Nothing in the app sends mail today, so this blocks
  nothing. Wire the real key when it exists.
- **No custom domain.** Ships on the DO-assigned `*.ondigitalocean.app` hostname with DO's automatic TLS.
- **No horizontal scaling.** Both services are pinned at 1 instance. Scaling `reverb` past 1 requires Redis
  and `REVERB_SCALING_ENABLED=true` (see finding 2). Scaling `web` past 1 is safe today.
- **No CDN/asset offload.** `public/build` is served by the web container. Fine at this traffic.
- **No staging environment.** One production app.

---

### Task 1: Trust the App Platform proxy

**Why:** Behind DO's load balancer the app sees plain HTTP on port 8000. Without trusted proxies Laravel
generates `http://` URLs, `$request->secure()` is false, and secure cookies and redirects break under HTTPS.

**Files:** Modify `bootstrap/app.php`

- [ ] **Step 1:** Add `$middleware->trustProxies(at: '*')` in the `withMiddleware` closure. `'*'` is correct
      here because the container is only reachable through DO's LB, never directly.
- [ ] **Step 2:** `./vendor/bin/pest` stays green. Commit.

### Task 2: Commit the App Platform spec

**Files:** Create `.do/app.yaml`

**Shape:**
- `region: fra`
- `services[0]` `web`: `dockerfile_path: Dockerfile`, `http_port: 8000`, `instance_size_slug: basic-xxs`,
  `instance_count: 1`, health check on `/up`, ingress route `/`.
- `services[1]` `reverb`: same source, `run_command` overriding to `php artisan reverb:start`,
  `http_port: 8080`, `basic-xxs`, `instance_count: 1`, ingress routes `/app` and `/apps` with
  `preserve_path_prefix: true` (DO strips the matched prefix otherwise, and Reverb needs the full path).
- `jobs[0]` `migrate`: `kind: PRE_DEPLOY`, `run_command: php artisan migrate --force`.
- `databases[0]`: managed Postgres, engine `PG`.
- Non-secret env vars inline. Secret env vars **omitted entirely** from the committed file and set in Task 4.

- [ ] **Step 1:** Write the spec. `fw-do apps spec validate .do/app.yaml` must pass.
- [ ] **Step 2:** Grep the file for anything credential-shaped before committing. Commit.

### Task 3: Provision Postgres and the app

- [ ] **Step 1:** Create the smallest managed Postgres in `fra`. Record the real slug and price.
- [ ] **Step 2:** `fw-do apps create --spec .do/app.yaml`. If DO rejects the GitHub source because the DO
      GitHub app is not installed on the private `bvdr/familywell` repo, **stop and report** rather than
      guessing: that authorization is an interactive browser flow only Bogdan can complete. Fallback if
      needed: build locally and push to `ghcr.io`, switching the spec's `github:` source to `image:`.
- [ ] **Step 3:** Attach the database to the app and confirm `${db.DATABASE_URL}` resolves.

### Task 4: Set secrets (never echoed)

Each of these is `type: SECRET` in the app spec, set via `fw-do apps update`:

- `APP_KEY` — freshly generated for production, not the local one.
- `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` — freshly generated. `REVERB_APP_KEY` is public by
  nature (it ships to the browser) but travels with the secret, so keep the trio together.
- `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_ENDPOINT`, `AWS_BUCKET` — from 1Password R2 item.
  `AWS_DEFAULT_REGION=auto` and `AWS_USE_PATH_STYLE_ENDPOINT=false` for R2.
- `DB_*` — derived from the attached database's `${db.*}` bindings, not hardcoded.

- [ ] **Step 1:** Generate secrets and pipe them into `fw-do` without printing. Verify by reading back only
      the env var *names* and types, never values.

### Task 5: Prove it live

- [ ] **Step 1:** `GET /up` returns 200 over HTTPS.
- [ ] **Step 2:** `GET /` returns 200 and the HTML contains a non-null `window.__familywellReverb` with the
      public host and `"scheme":"https"` (this is the finding-5 check).
- [ ] **Step 3:** Migrations actually ran: register a user through the real UI and reach the dashboard.
- [ ] **Step 4:** Websocket upgrade against `wss://<host>/app/<key>` succeeds (proves the `/app` route and
      `preserve_path_prefix`).
- [ ] **Step 5:** Playwright screenshot of the live dashboard into `~/familywell-proof/`, print `PROOF-SHOT: <path>`.

### Task 6: Ship

- [ ] **Step 1:** Push `p8-deploy`, open the PR once CI is green.
- [ ] **Step 2:** Report live URL, the actual monthly cost from DO, and everything still stubbed.
