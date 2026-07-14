# Familywell — Architecture Design

**Date:** 2026-07-14
**Status:** Draft for review
**Scope:** Infrastructure and application architecture. UI/visual design is intentionally out of scope and comes later.

---

## 1. What Familywell is

An open-source family organization app: todo lists, shopping lists, calendars, multiple members, shared or private features, a family admin and members. Built on a **WordPress/Home Assistant-style core + modules** model — the core is small and stable, and features are self-contained modules (folders of code) that hook into it.

Two ways to run it:

- **Self-host (free):** one Docker image you run anywhere. You own the data and can install any module.
- **Hosted cloud (paid):** we run it for families who don't want to self-host.

Monetization: **GitHub Sponsors** funds the open-source core; a **cloud subscription** funds hosting; a **module marketplace** can add paid modules with revenue share later.

---

## 2. Goals and non-goals

**Goals**
- One codebase / one Docker image that runs identically self-hosted and in our cloud.
- A real plugin API: modules add backend logic, their own data, UI, or pure config — and our own first-party features use that same API.
- A cloud that is cheap to run at low scale and scales with active usage.
- Host-agnostic cloud: the compute host is a swappable decision, not lock-in.

**Non-goals (deferred, see §12)**
- Per-family runtime installation of *untrusted* code in the cloud (cloud = vetted modules only).
- Multi-region DB sharding, offline/mobile sync, i18n, billing internals. Revisit when a real requirement forces them.

---

## 3. High-level architecture

```
                 ┌──────────────────────────────────────┐
                 │            Cloudflare (edge)           │
                 │   DNS · CDN · WAF · DDoS · TLS · cache  │  (free tier, both tiers)
                 └───────────────────┬────────────────────┘
                                     │
        ┌────────────────────────────┴────────────────────────────┐
        │                                                          │
   SELF-HOST (free)                                        HOSTED CLOUD (paid)
   docker run one image                          same image on a container host
   SQLite or BYO Postgres                        stateless, multi-tenant by family_id
   install ANY module                            install VETTED marketplace modules only
        │                                                          │
        │                                          ┌───────────────┴───────────────┐
        │                                          │  Managed Postgres (one DB)      │
        │                                          │  Object storage (files)         │
        │                                          └─────────────────────────────────┘
        ▼
   Coolify one-click template
   for homelab self-hosters
```

The application is a **modular monolith**: a single Laravel app (the "core") that loads modules at boot/runtime. It is stateless — all state lives in Postgres and object storage — so any number of identical instances can serve any family.

---

## 4. Core vs modules

- **Core owns:** authentication, families and members, roles/permissions, the module registry, the hook bus, the marketplace client, and the base UI shell. Nothing family-feature-specific.
- **Everything else is a module:** todos, shopping, calendar are **first-party modules** built on the exact same public API third-party modules use. This is deliberate — dogfooding the plugin API is what keeps it honest (the lesson from WordPress and Home Assistant: the platform is only as good as its own apps prove it).

### Module anatomy

A module is a folder (e.g. `modules/shopping/`) with a manifest and up to four optional parts:

| Part | What it is |
|---|---|
| **Manifest** (`module.json`) | id, version, `requires`, declared permissions/abilities, hook registrations |
| **Backend** | A service provider that registers routes, event listeners (hooks/filters), and background jobs |
| **Data** | Its own migrations; core provides a per-module migration runner and a registry table tracking installed/enabled state per family |
| **UI** | A pre-built JS bundle that registers React components into **named slots** (dashboard widget, nav item, full screen) exposed by the core shell |
| **Config-only** | Some modules ship only manifest + config (custom list types, automations) — no server code, safe to toggle at runtime anywhere |

### Hook system

The core exposes an event/listener bus (Laravel events) as the hook API — the WordPress "actions/filters" equivalent. Modules react to core events and can expose their own events for other modules. The core defines the hook contract; a module can be tested in isolation by firing a hook and asserting its listener ran, without booting other modules.

### UI extension

The React (Inertia) shell exposes a **slot registry**. Modules call `register(slot, Component)` from their pre-built bundle. Because cloud modules are pre-built and vetted, and self-host modules can ship a pre-built bundle (or trigger an asset rebuild on install), the shell never compiles untrusted code at runtime.

---

## 5. Stack

- **Backend:** Laravel (PHP). Chosen because its building blocks map almost 1:1 to this design — service providers = module bootstrap, events/listeners = hooks, package auto-discovery = module discovery, per-module migrations, Composer as the module distribution rail. `nwidart/laravel-modules` is the base pattern for folder-based HMVC modules.
- **Frontend:** Inertia + React (TSX) + shadcn/ui + Tailwind (the official Laravel React starter shape).
- **Realtime:** Laravel Echo + Reverb (WebSockets) for live shared lists; polling fallback if a self-hoster doesn't run Reverb. **[decision to confirm: Reverb from v1 vs polling-only for v1]**
- **Auth:** Laravel auth + `spatie/laravel-permission`.

---

## 6. Data and multi-tenancy

- **Self-host:** SQLite by default (zero-config), or bring-your-own Postgres.
- **Cloud:** one managed **Postgres**, multi-tenant. Every row is scoped by `family_id`, enforced by a global tenant scope so a query cannot cross families. App instances are stateless; state is Postgres + object storage only.
- **Files:** object storage behind a single driver — R2 or DO Spaces in cloud (S3-compatible, cheap/no-egress), local disk or MinIO/S3 self-host.

**Why shared multi-tenant is safe here:** the cloud runs only vetted marketplace modules (trusted code). There is no untrusted per-family code executing, so families can safely share app instances and one database. (This is also how Home Assistant Cloud sidesteps the problem — it never runs your plugins; they run on your box.)

DB sharding is **not** designed now. One managed Postgres with read replicas covers tens of thousands of families. Revisit only when metrics demand it (§12).

---

## 7. Permissions model

Two orthogonal axes:

- **Role:** family admin vs member (via `spatie/laravel-permission`).
- **Visibility:** an item/module is either **shared** to the whole family or **private** to one member.

Authorization policies live in the core. Modules declare the abilities they need in their manifest; the core grants/denies.

---

## 8. Cloud infrastructure — host-agnostic, DigitalOcean first

The cloud is **one stateless Docker image + one managed Postgres + object storage, with Cloudflare as the free edge in front.** Because the image is host-agnostic, the compute host is a reversible decision.

**Starting host (credit-funded phase): DigitalOcean.**

| Layer | Choice | Notes |
|---|---|---|
| Edge | Cloudflare | DNS, CDN, WAF, DDoS, TLS. Free tier. Works regardless of where compute runs. |
| Compute | **Coolify on a DO Droplet** (recommended start) | Turns a Droplet into a PaaS: git deploys, Traefik + auto-SSL, orchestration. Cheap, burns credit efficiently, more control. Caveat: single-box "pet," no autoscale, you own OS/DR. Fine at launch scale (YAGNI on autoscale). Alternative: **DO App Platform** (managed, pricier, zero server ops). |
| Database | DO Managed Postgres, single node (~$15/mo) | Automatic failover + daily backups. Cross-provider latency matters, so keep DB and compute co-located on DO. |
| Files | DO Spaces (S3-compatible, built-in CDN) or R2 | Either via the S3 driver. |

**Self-host distribution also targets Coolify:** publish a Coolify one-click template so homelab users deploy Familywell frictionlessly.

**Documented alternatives (same image, no rewrite to switch):**
- **Cloud Run** — mature autoscaling + scale-to-zero, per-100ms billing, generous free tier. The escape hatch when usage is bursty/idle and you want to stop paying for idle compute.
- **Cloudflare Containers** — all-in-Cloudflare option; scale-to-zero economics, but autoscaling/routing tooling is still maturing (as of 2026) and there is a beta concurrency cap. Attractive later if you want a single Cloudflare stack.

### Cost posture

At launch, cost is dominated by the **always-on database**, not compute. Rough monthly figures:

- **Credit phase (DO, Coolify on one Droplet + Managed Postgres + Spaces):** ~$25–35/mo of usage, **effectively $0 while credit lasts.**
- **Scale-to-zero hosts (Cloud Run / CF Containers) at light usage:** ~$8–15/mo compute, with a serverless Postgres (Neon/Supabase) instead of an always-on DB to keep the floor low.
- The main cost levers everywhere: smallest instance that runs the app, a minimal or zero warm floor (trading cold-start latency for cost), and keeping files + egress behind Cloudflare/R2.

### Scaling path (when it matters, not before)

1. **More load:** run more identical stateless instances behind the edge. Any instance serves any family.
2. **Bursty/idle usage becomes the cost:** move compute to a scale-to-zero host (Cloud Run / CF Containers) + serverless Postgres. Same image.
3. **One Postgres saturates (far future):** add read replicas first; then shard by `family_id` into clusters. Design this only when metrics force it.

---

## 9. Marketplace and monetization

- Modules are versioned packages in a registry we host (Composer-style).
- **Cloud marketplace = the reviewed/vetted subset.** Self-hosters can install anything from anywhere.
- The same marketplace client in core lists free and paid modules.
- Revenue: GitHub Sponsors (core) + cloud subscription (hosting) + paid modules with revenue share (later).

---

## 10. Security model

- **Cloud:** only vetted modules run. Shared multi-tenant instances + one DB are safe because there is no untrusted code execution and every row is `family_id`-scoped by a global tenant scope.
- **Self-host:** single-tenant on the user's own box — they can install anything; it's their machine and their risk.
- **Edge:** Cloudflare provides WAF, DDoS protection, and TLS termination.
- **Secrets:** never committed. Config via environment; deployment docs note every credential that must be set manually.

---

## 11. Testing and isolation

- Each module is independently testable: it declares its dependencies in the manifest, ships its own test suite, and the core can load it in isolation.
- The core defines and tests the hook contract; a module test fires a hook and asserts its listener ran without booting the rest of the system.
- Smaller, well-bounded modules keep each unit understandable and changeable without breaking consumers.

---

## 12. Deferred (YAGNI until a real requirement forces it)

- **HA-style relay tier** (self-host + our paid tunnel). Cloudflare Tunnel makes it cheap to add later; not in v1.
- **DB sharding / multi-region.** Read replicas first; shard by `family_id` only when metrics demand.
- **Offline / mobile sync.**
- **i18n.**
- **Billing/subscription internals.**
- **Cross-version module dependency resolution.**

---

## 13. Open decisions to confirm

1. **Realtime:** Reverb (WebSockets) from v1, or polling-only for v1 and add Reverb later?
2. **v1 first-party module scope:** todos + shopping + calendar all in v1, or ship todos + shopping first and calendar next?
3. **Cloud starting host:** Coolify-on-Droplet (recommended) vs DO App Platform.
