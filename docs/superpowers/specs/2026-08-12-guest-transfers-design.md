# Guests in transfers (settle-up)

**Date:** 2026-08-12
**Status:** Approved design, ready for implementation plan

## Problem

Spending events support guests (account-less, named participants reached through a share link).
Guests already appear in balances and settle-up *suggestions*, and (as of the previous change) can be
selected as the payer of a spend. But **settlements/transfers are still user-only**: the
`spending_settlements` table stores `from_user_id` / `to_user_id`, so a payment involving a guest
cannot be recorded. Consequently:

- The owner's "Record a payment" form and one-click "Mark paid" only offer family members.
- A suggested payment that involves a guest is shown but not actionable (`canSettle = false`).
- Guests on their guest page can see suggestions but cannot record that they paid / were paid.

## Goal

Let a transfer involve a guest on either side, recordable from **both** surfaces:

- **Owner/member app:** guests appear in the transfer from/to selectors and in one-click "Mark paid".
- **Guest page:** a guest can record settle-ups that involve themselves, via one-click on relevant
  suggestions **and** a free-form "Record a payment" form.

## Key insight

The balance engine (`Balances`), `Spend::participantKey()`, and `SpendShare.participant_key` already
speak namespaced participant keys: `u{id}` for a member, `g{id}` for a guest. Settlements are the only
place still hardcoded to user ids. Bringing settlements onto keys unifies the module and deletes the
existing `'u'.$id` shims in `EventBalances`.

## Data model

Migrate `spending_settlements`:

- Replace `from_user_id` (bigint) and `to_user_id` (bigint) with `from_key` (string) and `to_key`
  (string), holding namespaced keys (`u{id}` / `g{id}`).
- Backfill existing rows: `from_key = 'u' || from_user_id`, `to_key = 'u' || to_user_id`.
- `created_by` stays a user id (owner attribution for guest-recorded rows, same as guest spends).

Rejected alternative: keep the user columns and add nullable `from_guest_id` / `to_guest_id` (4
columns + an "exactly one per side" invariant). Keys are 2 columns, and the engine already consumes
keys, so it is a net simplification. `SpendShare.participant_key` sets the precedent for storing a
namespaced key as a string.

Migration must be resumable/idempotent: guard each step on column existence so a re-run after a partial
apply is a no-op.

## Backend

### `SpendSettlement` model
- `fillable`: `from_key`, `to_key` (drop `from_user_id`, `to_user_id`).

### `EventBalances::of()`
- Map settlements to `['from' => $t->from_key, 'to' => $t->to_key, 'amount' => $t->amount]` (drop the
  `'u'.` prefix shim).
- Drop `canSettle`. Every suggestion is now recordable; frontends decide which affordance to show.
  (Removing the field is a small type change in both frontends; see below.)

### Owner `SpendingController::settle()`
- Validate against the event's participant keys:
  - `from_key`: required, string, `in:{participantKeys}`
  - `to_key`: required, string, `different:from_key`, `in:{participantKeys}`
  - `amount`: required, integer, `gt:0` (cents, as today)
- Create the settlement with `from_key` / `to_key`; `created_by = auth id`.

### Owner `SpendingController::show()` payload
- `transfers`: resolve `from` / `to` names through `participantNames()` (keys → names) instead of the
  member-only `$memberName` map. Fall back to `'?'` for a key no longer present.
- `mySummary`: `sent = settlements where from_key === 'u'.$userId`, `received = where to_key ===
  'u'.$userId`.

### Guest settle (new)
- Route: `POST m/spending/guest/{token}/settle`, name `spending.guest.settle`, in the existing
  unauthenticated guest group (`web`, `throttle:60,1`).
- `GuestSpendController::settle(Request, string $token)`:
  - `[$event, $guest] = $this->access->authorizeWrite($token, (int) $request->input('guest_id'))`
    (same token + guest-id + editor-role gate as guest spends).
  - Validate `from_key` / `to_key` / `amount` as above against `$event->participantNames()` keys.
  - **Own-entries rule:** `abort_unless($from_key === 'g'.$guest->id || $to_key === 'g'.$guest->id,
    403)` — a guest may only record a transfer they are part of.
  - Create with `created_by = $event->created_by` (no user session; attribute to owner), then broadcast
    `SpendingChanged` like the other guest writes.

### `SpendEvent::guestView()` payload
- Add `transfers`: recorded settlements mapped to `{ id, from: name, to: name, amount, date }` via
  `participantNames()`, so guests see history (parity with the owner Transfers tab).
- `suggestions` stays; `canSettle` removed from its shape.

## Frontend

### Owner `event.tsx`
- `transferForm`: `{ from_key: '', to_key: '', amount: '' }`.
- From/To selectors iterate `participants` (members + guests) keyed by `p.key`; validation uses keys.
- `markPaid(s)` posts `{ from_key: s.from.id, to_key: s.to.id, amount: s.amount }` (the suggestion ids
  are already keys). Remove the `canSettle` gate so guest suggestions get the button too.
- The Transfers "record" affordance gate `event.members.length >= 2` → `participants.length >= 2`.
- `Suggestion` type: drop `canSettle`.

### Guest `guest-event.tsx`
- **One-click:** on each suggestion where `me` is the `from` or `to` (`myKey`), show a "Mark as paid"
  button that posts to `spending.guest.settle` with `{ token, guest_id: me.id, from_key, to_key,
  amount }`. Suggestions between two other parties stay informational.
- **Free-form "Record a payment" form:** the guest is fixed as one side; a direction toggle ("I paid" /
  "I was paid") swaps whether they are `from` or `to`; a picker chooses the other participant from the
  balances/participants list; amount is typed (decimal → cents). Posts to the same guest settle route.
  Server re-validates the own-entries rule.
- **Transfers list:** render the recorded `transfers` from the payload (mirrors the owner tab).
- Reuse `selectAllOnFocus` for the amount input.

### i18n
- Add EN + RO keys to `lang/{locale}/spending.json` for the new guest strings: record-payment heading,
  direction toggle labels, "Mark as paid", amount label, empty/confirmation text. Reuse existing
  `spending.*` keys (`from`, `to`, `record_payment`, `pays`, etc.) where they already exist.

## Testing

- **Migration:** existing member-only settlement backfills to `u{id}` keys; balances unchanged.
- **Owner:** can record a member↔guest settlement; it shifts both balances; the guest suggestion is now
  one-click settleable.
- **Guest:** with a valid token + editor role, records a settlement involving themselves (both
  directions); balances update. Blocked (403) from recording a transfer that does **not** involve them.
  A view-only guest is blocked. Amount/participant validation enforced.
- Keep the existing `tests/Feature/Spending` suite green (update any that assert `from_user_id`).

## Known gaps (out of scope)

- **No settlement delete/undo** for anyone — it does not exist today for members either. A fat-fingered
  transfer can only be corrected by recording the reverse. Flagged, not built here.
- Guest free-form form records at the guest's typed amount; it does not clamp to the outstanding
  balance (same as the owner form today).
