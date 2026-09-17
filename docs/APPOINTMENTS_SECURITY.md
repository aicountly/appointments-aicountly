# Security

## Authentication

Two ways in, and no third:

**A human session.** A portal `ses_key` presented as `Authorization: Bearer`.
`src/Portal.php` validates it against `my.aicountly.com`; `ses_key` is
short-lived and held in memory by the frontend only. The long-lived `auth_token`
never reaches this product's API.

**Another AICOUNTLY product.** `X-Service-Key` plus `X-Actor-Uuid`.
`src/ServiceKeys.php` resolves the key to a calling app with `hash_equals` — a
plain `===` on a secret leaks its length and, over enough requests, its content.
A key still carrying the `CHANGE_ME` placeholder authenticates nothing, so a
half-finished `.env` fails closed.

**Public booking** resolves no `Auth` at all. It gets its own section below.

`Auth::provenBookingSource()` derives the booking source from the authenticated
caller, never from the request body. A caller cannot claim to be Receptionist by
sending `"source": "RECEPTIONIST"`.

## Tenant isolation

Every tenant-scoped table carries `cmp_id`, and every query filters on it.
`Context::fromRequest()` resolves `cmp_id` / `bo_id` and asks Manage whether this
actor may use them. **If Manage cannot answer, the request returns 503** — it
never falls through to allow. An availability lookup that quietly ran against the
wrong company would be worse than an outage.

`Context::trustForTesting()` exists and is CLI-only.

## Authorisation

`src/Permissions.php` holds the named permissions; `Controller::enter()` requires
one for every `/v1/*` route:

```
appointments.dashboard.view        appointments.reports.view
appointments.booking.view          appointments.booking.create
appointments.booking.edit          appointments.booking.cancel
appointments.booking.override      appointments.clients.view
appointments.clients.manage        appointments.services.manage
appointments.team.manage           appointments.resources.manage
appointments.waitlist.manage       appointments.forms.manage
appointments.reminders.manage      appointments.settings.manage
appointments.integrations.manage   appointments.access.manage
```

**The frontend never decides authorisation.** It receives the permission set to
decide what to *render*, and every route it could call is checked again on the
server. Hiding a button is a courtesy; the check is the control.

`appointments.booking.override` is separate from `.edit` on purpose: booking over
a conflict, outside lead time, or past a capacity limit is a different
privilege from moving an appointment by ten minutes.

Administrator hints on the Integrations screen — "set `PAY_SERVICE_KEY`" — are
gated on `appointments.integrations.manage`. A receptionist learns nothing from
deployment internals and should not be shown them.

## Credentials

- No service key, provider key, or database credential appears anywhere under
  `web/`. The browser talks only to this backend.
- LLM keys come from Console at call time and live in process memory and APCu
  only — never a file, never the database, never a response body. See
  `APPOINTMENTS_AI.md`.
- `ConsoleCredentials::status()` reports availability, the model name and a hint
  naming a missing env key. It never reports a key value, prefix or length.
- `.env.example` contains no real values.

## Public booking endpoints

The only unauthenticated surface in the product. `PublicBookingController`
carries its own protections:

**Rate limiting.** Fixed-window, per scope, against a hashed client fingerprint
(`sha256` of salt + IP + user agent, truncated — never stored raw):

| Scope | Limit |
| --- | --- |
| page view | 60 / 5 min |
| slot search | 90 / 5 min |
| slot hold | 12 / 5 min |
| **booking** | **6 / 15 min** |
| manage or cancel an existing booking | 20 / 15 min |

Reads are generous; writes are not. A rate-limit *write* failure is logged and
allowed through — a limiter that cannot write must not take the booking page
down — but the limits themselves are enforced on every request that can be
counted.

**No company id from a stranger.** The company is resolved from the page slug.
There is no parameter a public caller could set to point the endpoint at another
tenant.

**Links are unguessable, and only what they need.** A booking is addressed by its
UUID v4; `Uuid::isValid()` rejects anything that is not one before a query runs.
No sequential ids, no `cmp_id`, no staff email, no internal identifiers in any
public response.

**Revalidation before confirmation.** Every public confirmation re-runs
`AvailabilityService::revalidate()`, which makes a live Calendar conflict check.
If Calendar cannot establish an answer, the booking is refused with 503 — unknown
is never free. A hold reserves the minute during the flow; the hold is not
trusted as proof at the end of it.

**Idempotency, always.** A public `POST` without an `Idempotency-Key` gets one
derived from the fingerprint and the request body, so a double-tap on a bad
connection cannot produce two appointments. `Idempotency::remember()` stores the
status and payload and replays them.

**Holds are owned.** A hold belongs to the hashed fingerprint that created it —
not a cookie, not a session — so one browser cannot confirm against another
browser's hold.

**Anti-spam.** Rate limits per scope, required fields validated server-side,
services must be explicitly marked online-bookable, bookings must fall inside the
page's own rules (lead time, horizon, capacity), and holds expire. CAPTCHA is not
wired in: there is no CAPTCHA provider configured in the fleet, so adding one
would have meant inventing a credential. It is listed in the known items below
as the one anti-abuse control left to add, and the limits above are what stands
in for it today.

**What a public caller may never do.** Choose a company, book a service that is
not online-bookable, book outside the booking rules, override anything, name a
booking source, or read another client's booking.

## Input handling

All SQL goes through `Db` with bound parameters. Where a jsonb operator collides
with PDO's placeholder parsing — `payload ? :stage` — the function form
`jsonb_exists(payload, :stage)` is used instead, with a comment saying why, so
nobody "fixes" it back into a mixed-parameter error.

Identifiers are validated as UUIDs before use. Enum-like inputs (status, mode,
channel) are checked against the central constants rather than passed through.
Lifecycle changes go through `BookingService::TRANSITIONS`, so an invalid state
change is impossible to express, not merely unlikely.

## Audit

`src/Audit.php` records booking creation, reschedule, cancellation, no-show,
override, settings and service changes, access changes, and public bookings
(`recordPublic()`, with the hashed fingerprint rather than an identity). Each row
carries the actor, the company, the correlation ID that ties it to the outbound
cross-product calls made on the same request, and the before/after where it
matters.

## Degraded mode is a security property

If Calendar is unavailable, Appointments shows "Calendar availability is
temporarily unavailable.", returns no slots, and disables the actions that would
need a conflict check. There is **no stale local copy to fall back on** — not as
a policy choice at the edge, but because the no-synchronisation rule means one
does not exist. `checked: false` from Calendar propagates to `free: false`, to a
503, to a disabled Book button.

## Transport and headers

HTTPS throughout. `X-Saas-Origin` (`CrossServiceCallContext`) prevents a
cross-product call chain from re-entering this product and exhausting its worker
pool — a self-inflicted denial of service that has bitten this fleet before.
Outbound calls have bounded timeouts: a short budget for anything a human is
waiting on, a longer one for cron.

## What the tests cover

`tests/integration.php` (76) and `tests/http.php` (31, run twice — once with
Calendar reachable, once with it down) assert, among the rest:

- tenant isolation — one company cannot read another's bookings
- permission checks per route, including `override`
- the Calendar-unavailable path: 503, no slots, no booking written
- Calendar conflict (409) handling and `slot_taken`
- double-booking prevention through holds and revalidation
- idempotent replay of the same key
- Pay-disabled and Receptionist-disabled behaviour
- feature-flag resolution, including flag-on-but-unconfigured counting as off
- lifecycle transitions, including every refused one
- route ordering, so `/bookings/{id}/form` cannot be swallowed by
  `/bookings/{id}/{action}`

## Known security items still open

1. **CAPTCHA / bot scoring on public booking** — not wired, no provider
   configured in the fleet. Rate limits and server-side validation stand in.
2. **`CALENDAR_SERVICE_KEYS` must be generated per environment.** Calendar ships
   with service auth unset; nothing works until a real key is issued, which is
   the intended failure direction.
3. **Key rotation for product-to-product keys** is manual, one `.env` per host.
   Console already governs LLM keys this way; service keys have not moved yet.
