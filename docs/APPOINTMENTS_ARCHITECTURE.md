# Appointments architecture

## What this product is

`appointments.aicountly.com` books people into staff diaries. It owns the
*booking*: who is coming, for which service, with which member of staff, under
which policy, and what happened at the end of it. It does not own the diary —
Aicountly Calendar does — and it does not own the person — Contacts does.

Everything below follows from that sentence. If you are looking for the rules
about who owns what, read `APPOINTMENTS_DATA_OWNERSHIP.md` first; this document
describes the shape of the code.

```
                browser (React 19 / Vite)
                        │  Bearer ses_key  (+ cmp_id / bo_id)
                        ▼
        ┌───────────────────────────────────────────┐
        │  server-php  (PHP 8.4, no composer)        │
        │                                            │
        │  Controllers ──▶ Domain services ──▶ Db     │
        │       │                 │                  │
        │       │                 ▼                  │
        │       │           Clients/*  (typed HTTP)  │
        │       ▼                 │                  │
        │  Dashboards/*           │                  │
        └─────────────────────────┼──────────────────┘
                                  │  X-Service-Key + X-Actor-Uuid
        ┌─────────────┬───────────┼───────────┬──────────────┐
        ▼             ▼           ▼           ▼              ▼
    Calendar      Contacts     Manage      Console      Pay / Receptionist /
  (diaries,      (people)    (branches)   (LLM keys)    Connect / CRM /
 free-busy,                                             Billing / Messaging
 events)                                                 — feature-flagged
```

The browser never talks to another product. Every cross-product call is made by
this backend, with this product's service key, on behalf of a named actor. That
is what keeps service credentials out of frontend JavaScript and gives every
outbound call an audit trail.

## Request lifecycle

A typical authenticated call, `POST /v1/bookings`:

1. `public/index.php` boots `Autoload`, loads `.env` through `Env`, and hands the
   path to `Router`.
2. `Router` matches the declared route. Routes are matched by segment count and
   literal-before-placeholder, so `/v1/bookings/{booking}/form` is declared
   above `/v1/bookings/{booking}/{action}` on purpose — see `Routes.php`.
3. `Controller::enter()` runs the four gates in order:
   - `Auth::fromRequest()` — a portal `ses_key` (human) or `X-Service-Key` +
     `X-Actor-Uuid` (another AICOUNTLY product). No third option.
   - `Context::fromRequest()` — resolves `cmp_id` and `bo_id` and asks Manage
     whether this actor may use them. If Manage cannot answer, the request 503s;
     it never falls through to "allow".
   - `Permissions::require()` — the named permission for this route.
   - `Idempotency::begin()` — on unsafe verbs carrying `Idempotency-Key`.
4. The controller validates input and calls one domain service. Controllers hold
   no business rules; they translate HTTP to a service call and a service result
   back to an envelope.
5. `Http::json()` writes `{data}`, `{data, meta}` or `{error: {code, message,
   details}}` and throws `ResponseSent`. Under CLI that exception is caught by
   the test harness, which is how the HTTP suite asserts on real responses
   without a web server.

## Layers

### `src/` (framework-ish)

`Autoload` (PSR-4 by hand), `Router`, `Http`, `Db` (PDO, PostgreSQL),
`Env`, `Portal` (SSO validation), `ResponseSent`, `ServiceKeys`,
`CrossServiceCallContext` (the `X-Saas-Origin` re-entry guard), `Auth`,
`Context`, `Permissions`, `Audit`, `Health`, `Features`, `Idempotency`.

These are deliberately small and have no knowledge of appointments.

### `src/Clients/` (the only place that knows another product exists)

`ApiClient` is the base: one place for base URLs, the service-key headers, the
correlation ID, the two timeout budgets (a short one for anything on a user's
critical path, a longer one for background work), per-request memoisation so one
HTTP request never asks the same question twice, and the re-entry guard.

Each product gets one subclass with typed methods and *no* leakage of raw
response shapes upward: `CalendarClient`, `ContactsClient`, `CrmClient`,
`PayClient`, `ReceptionistClient`, `ConnectClient`, `BillingClient`,
`MessagingClient`, `ManageClient`. A client's job is to translate the other
product's errors into ours — `calendar_unavailable`, `contact_not_found` — so
domain code never branches on somebody else's HTTP status.

`MessagingClient` refuses a `voice` channel with `voice_belongs_to_receptionist`.
That is an ownership rule expressed in code rather than in a comment.

### `src/Domain/` (the business)

- `Settings` — company defaults, booking reference allocation (`UPDATE … RETURNING`
  under a row lock, so two concurrent bookings cannot take `AP-1042` twice).
- `AvailabilityService` — the heart of the product. Generates candidate slots
  from Appointments' own rules (service duration, buffers, lead time, horizon,
  staff working patterns, resource requirements), then makes **one** bulk
  free/busy call to Calendar for every staff member at once, then subtracts
  internal blocks (bookings not yet synced to Calendar, and live slot holds).
  `revalidate()` runs the same arithmetic again immediately before any write.
- `SlotHoldService` — short-lived holds so two people in the booking flow cannot
  land on the same minute. Expiring coordination state for *this* product's
  flow, which is why it lives here and not in Calendar.
- `BookingService` — the lifecycle. A `TRANSITIONS` table is the only place a
  state change is allowed from. The booking row is written *before* the Calendar
  event, and `calendar_sync_state` records how that went, so a Calendar outage
  loses no customer intent. A reschedule creates a new booking chained to the
  old one rather than mutating history.
- `ClientProfileService` — per-client behaviour derived from *our* bookings
  only; the person's identity comes from Contacts at read time.
- `NoShowRiskService` — named `INDICATORS` with fixed weights and a printed
  threshold. Every risk badge can say which indicators fired.
- `ReminderService`, `WaitlistService` (weighted `REASONS`, a bounded offer
  window), `ScheduleHealthService` (named `COMPONENTS`, each with a maximum
  penalty, so a score of 78 decomposes into "-14 gaps, -8 overruns").

### `src/Ai/`

`ConsoleCredentials` resolves this product's provider key from Console at call
time and holds it in memory (and APCu when present) — never on disk, never in a
response. `AiClient` wraps the provider. `InsightEngine` computes eleven named
rules over real data and, when AI is enabled, asks the model to *narrate* what
the rules already found. `origin: 'rules' | 'ai'` on an insight refers to the
prose only — the numbers are always ours.

### `src/Dashboards/`

`Period` (range plus the comparable previous range), `Metric` (`change_pct` is
null rather than 0 when there is no previous period), and one class per
dashboard. Each dashboard assembles its own panels; they share `Metric` and
`Period`, not widgets.

### `src/Controllers/`

Thin. `Controller::fail()` is the single map from an error code to an HTTP
status, so `calendar_unavailable` is a 503 everywhere and `slot_taken` is a 409
everywhere.

### `web/src/` (frontend)

- `services/api.ts` — the only `fetch` in the app. Attaches the `ses_key`,
  refreshes it once on a 401, adds company/branch context, and normalises error
  envelopes.
- `services/types.ts` — types mirroring the backend envelopes.
- `hooks/useApi.ts` — loading / empty / error / partial states as a single
  shape, so every screen can render all four without inventing its own.
- `context/AppointmentsContext.tsx` — session, company/branch, feature flags,
  permissions. Flags and permissions are *rendered* from here and *enforced*
  by the backend; the frontend never decides authorisation.
- `ui/` — the design system (`appointments-ui.css`, scoped `.appt-ui`) and
  `charts.tsx` (inline SVG; there was no chart library in the fleet to reuse).
- `shell/`, `dashboards/`, `components/`, `pages/` — see
  `docs/ui/appointments-dashboard-reference.html` for the visual reference.

## Storage

PostgreSQL, five migrations under `database/migrations/`, applied by
`bin/migrate.php`. Times are `TIMESTAMPTZ` stored in UTC and rendered in the
company's timezone (`Clock` builds local wall-clock times from the local date
string so DST boundaries are correct rather than 24-hour arithmetic). Flexible
shapes — form definitions, reminder payloads, insight evidence — are `jsonb`.
Every tenant-scoped table carries `cmp_id`, and every query filters on it.

## Background work

Two CLI entry points, both safe to run repeatedly:

- `bin/reminders.php` — due reminders, one pass. Cron every 5 minutes.
- `bin/calendar-retry.php` — bookings whose `calendar_sync_state` is not
  `synced`. Cron every 5 minutes.

Neither invents data when a downstream product is unavailable; they leave the
row in a retryable state and say so.

## Degraded mode

If Calendar cannot answer, availability returns no slots and the API returns 503
`calendar_unavailable`; the UI shows "Calendar availability is temporarily
unavailable." and disables actions that would need a conflict check. There is no
stale local copy to fall back on, by design. A feature-flagged product that is
not configured renders an explicit placeholder naming the missing configuration
rather than an empty panel that looks like zero.
