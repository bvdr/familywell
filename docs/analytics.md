# Analytics (hosted familywell.co only)

Self-hosted Umami at https://analytics.dragonpie.co, **version 3.3.0** (read from the instance's bundle,
`CURRENT_VERSION`). Self-hosted installs load no tracker and send nothing: everything is gated on
`UMAMI_WEBSITE_ID` (see `config/services.php`, `App\Support\Analytics`).

## One website for familywell.org + familywell.co (since 2026-10-16)

Both domains report into **one** Umami website, `b42f8cb0-3c6b-4e52-9e72-ba81ee80dd88` (it was the
familywell.org website). Split reports with Umami's **hostname** filter. Production sets
`UMAMI_WEBSITE_ID=b42f8cb0-3c6b-4e52-9e72-ba81ee80dd88` on the DigitalOcean app.

The old familywell.co website, `387d1b2c-f687-44d0-bd91-05b61defa5ec`, is **kept, read-only**: it holds
the app's history until the cutover on 2026-10-16 and gets no new data after that. Don't delete it.
(admin.familywell.org's per-user activity reads both, see familywell-site `admin/src/umami.ts`.)

### Why

With two websites, a visitor who clicked from familywell.org to the app counted as a **bounce** on .org
and as a **new visitor** on .co, so the funnel was invisible. What Umami 3.3.0 does (source,
`src/app/api/send/route.ts` and `src/queries/sql/getWebsiteStats.ts`, and checked against a throwaway
3.3.0 in Docker):

- **Session** = `uuid(websiteId, ip, userAgent, monthly salt)`. The hostname is NOT part of it, the
  website id is. So one website id across both domains = the same session; two ids = two sessions.
- **Visit** = the session + the current hour (or the visit carried in the tracker's in-memory cache
  token, renewed after 30 min idle). A fresh page on another domain has no cache token, so it gets
  `uuid(session, hour)`, the same visit as the .org page, unless the click crosses an hour boundary
  (then it's a second visit of the same session: a known, small gap; nothing can carry the token across).
- **Bounce** = a visit with exactly one page view and **no custom event**. So a .org page view + a .co
  page view in one visit is not a bounce, and neither is a visit with a `cta_click`.

Measured on the throwaway 3.3.0 (same browser, .org page -> CTA click -> .co page):

| | same session | same visit |
|---|---|---|
| one website id, two hostnames | yes | yes |
| two website ids (the old setup) | no | no |

and bounces: an .org-only visit = bounce; .org + `cta_click` = not a bounce; .org -> .co = not a bounce
overall, but **is** one with the report filtered to `hostname = familywell.org` (the filter drops the
.co page view). That's why the .org CTAs also send `cta_click`: the .org-only view stays honest too.

### Our own domains are not referrers

Umami 3.3.0 only drops a referrer that equals the page's own hostname (`src/app/api/send/route.ts`), and the
Channels and Attribution reports label a visit by its first event that has a source. So a .org -> .co click
was stored as a familywell.org referral, and the sign-in / payment round trips came back as
accounts.google.com (matches Umami's `google.` search list, so "organic search"), appleid.apple.com or
checkout.stripe.com ("referral"): a direct visit that signed up with Google was reported as search traffic.
Both sites set `data-before-send="fwUmamiBeforeSend"`, which blanks referrers from familywell.org/.co (any
subdomain) and those three hosts before sending (`resources/views/app.blade.php`, familywell-site
`public/visitor.js`). The visit's real first source still wins. Rows sent before 2026-10-17 still carry the
old referrers.

### The visitor id hand-off still matters

`umami.identify()` doesn't change the session (it only sets the distinct id), so joining a person
across devices or months still uses familywell.org's `visitor.js`: it adds `fw_vid` (+ `fw_src/med/cmp`)
to every link to familywell.co, `CaptureFirstTouch` keeps it for the guest, and sign-up adopts it as
`users.analytics_id` (`RegisteredUserController::adoptAnalytics`).

## Events

Browser events join the visitor's own session (same IP + user agent as their .org page views).
Server-side events (`Analytics::track`, sent from the server's IP) don't: they land on the person only
through the distinct id. So the funnel steps are sent from the browser, and the server-side counts stay
as the reliable totals.

**familywell.org** (data-umami-event attributes, see familywell-site `docs/analytics.md`):
`cta_click` with `location` (nav, mobile_nav, megamenu, hero, features, pricing, final, footer, ...) and
`label` (get_started, demo, github, copy_install, ...), `video_play`.

**familywell.co, browser** (`resources/js/lib/analytics.ts`):

| event | data | where |
|---|---|---|
| `signup_started` | method: password / google / apple | register form submit; Google/Apple buttons on the register page |
| `signup_completed` | method | queued by `RegisteredUserController::registered` |
| `demo_opened` | | queued by `/demo` |
| `checkout_started` | plan: monthly / yearly | the paywall's plan buttons |
| `checkout_completed` | | queued when Stripe's return shows the family paid |
| `added_<module>` (e.g. `added_shopping`) | module | queued by `Engagement::track`'s `created` hook: someone added an item (not a list or folder) in that module from the app or a guest link. Not sent for MCP (no session; `assistant_tool` covers it) |

"Queued" = `Analytics::queue()`: kept in the session, shared to the next rendered page as
`analyticsEvents`, sent by `app.tsx` (for steps that end in a redirect the browser never renders).

**familywell.co, server** (`Analytics::track`): `signed_up`, `family_created`, `member_invited`,
`invite_accepted`, `assistant_connected`, `assistant_tool`, `subscription_started`,
`subscription_canceled`.

The funnel in Umami: `cta_click` (hostname familywell.org) -> `signup_started` -> `signup_completed`
-> `checkout_started` -> `checkout_completed`, with `demo_opened` as the side branch.
