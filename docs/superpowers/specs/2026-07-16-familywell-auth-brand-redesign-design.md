# Auth Brand Redesign (Onboarding: Login / Register / Password) Design

**Goal:** Replace the Laravel starter-kit auth screens with the FamilyWell brand-split design from
`docs/design/familywell.pen` (frame "Web · Auth Flow"): a forest brand panel + cream form card.

**Approved:** design presented and approved in-session; user supplied the three panel illustrations
and chose "all auth pages" scope.

## Layout (from the design)

Two columns, full viewport:

- **Brand panel** (left, `surface-dark` forest, `hidden lg:flex`): leaf wordmark "FamilyWell" (Playfair)
  at top; illustration image (`radius-lg`, `object-cover`); tagline block at bottom = Playfair headline
  (34px) + body sub (15px, `accent-light`).
- **Form side** (right, page `bg` cream): centered 420px card — Playfair title (32px, `text-primary`) +
  body subtitle (15px, `text-secondary`); fields; full-width `accent` button; footer link row.

Below `lg` the brand panel is hidden and the form is full-width centered (matches the mobile auth frames).

## Per-screen content (exact copy from the design)

| Route | Form title | Form subtitle | Panel headline | Panel sub | Image |
|---|---|---|---|---|---|
| `login` | Welcome back | Sign in to keep your family in sync. | Where your family finds its rhythm. | Calm, shared organization for the people who matter most. | login.png |
| `register` | Create your account | A few details and your family space is ready. | Start something calm together. | Create a shared home for schedules, lists, and the little moments in between. | register.png |
| `password.request` | Reset your password | Enter the email linked to your account and we'll send you a secure link to choose a new password. | We'll help you back in. | Resetting your password only takes a moment. | reset.png |

`reset-password`, `verify-email`, `confirm-password` are not in the design; they inherit the layout with
the login panel content (calm default), so the whole auth surface is visually consistent.

## Implementation

- **`resources/js/layouts/auth/auth-split-layout.tsx`** — rewrite as the branded split. Panel content is
  keyed by the current route name in one small map (login/register/password.request + a default), so pages
  stay simple and the panel logic lives in one place.
- **`resources/js/layouts/auth-layout.tsx`** — repoint from `auth-simple-layout` to the split layout.
- **Pages** login / register / forgot-password — update `title`/`description` to the design copy and the
  footer link copy ("New to FamilyWell? Create an account", etc). Fields/buttons keep the existing shadcn
  `Input`/`Button` (already themed to the brand: default Button = `accent`, Input = white/border/radius),
  tweaked only where the design differs.
- **`resources/css/app.css`** — expose the three brand colors the panel needs as Tailwind utilities
  (`--color-surface-dark`, `--color-accent-light`, `--color-text-inverse`) off the runtime theme vars.
- **Images** at `public/images/auth/{login,register,reset}.png`. Panel shows solid forest behind the image,
  so a missing file degrades cleanly rather than breaking.

## Deviations (flagged)

- **Remember-me kept.** The design's login omits it, but removing a working persistent-login toggle is a
  functional downgrade, so it stays as a small checkbox above the button. Trivial to drop if not wanted.

## Verification

- `pest` stays green (existing SmokeTest asserts `/login` 200; auth flow tests unaffected).
- Live proof: Playwright screenshots of login + register at desktop and mobile widths into
  `~/familywell-proof/`, confirming panel, form, and responsive collapse.
