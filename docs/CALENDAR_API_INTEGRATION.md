# Calendar API integration

Aicountly Calendar (`calendar.aicountly.com`) is the canonical owner of
calendars, events, times, recurrence, free/busy, blocked time, external provider
sync and conflict detection. Appointments holds no copy of any of it.

All calls below are made by `server-php/src/Clients/CalendarClient.php`. Nothing
in `web/` talks to Calendar.

## Authentication

Two headers, set by `ApiClient`:

| Header | Value |
| --- | --- |
| `X-Service-Key` | `APPOINTMENTS_CALENDAR_SERVICE_KEY` — the key Calendar issued to this product |
| `X-Actor-Uuid` | the subscriber whose diary is being read or written |

Calendar resolves the key to a calling app in
`app/Helpers/ServiceKeys.php` (`CalendarServiceKeys::resolveApp()`), comparing
with `hash_equals`, rejecting anything shorter than 16 characters or still
carrying the `CHANGE_ME` placeholder. With no `CALENDAR_SERVICE_KEYS` set,
service auth is off — the feature is unset by default, not open by default.

`X-Actor-Uuid` is not a shortcut around permissions. Appointments has already
established, before the call, that the human (or the calling product's actor)
may act for that subscriber in that company. Calendar's job is to trust *this
product*; deciding *who* it is acting for stays here.

A third header, `X-Saas-Origin`, carries the re-entry guard
(`CrossServiceCallContext`) so a chain of product-to-product calls cannot loop
back and deadlock a worker pool.

## Endpoints Appointments uses

### Bulk free/busy — `GET /calendar/free-busy` *(added for Appointments)*

```
GET /calendar/free-busy?subscribers=<csv>&from=<iso8601>&to=<iso8601>
```

- `subscribers` — up to 60 subscriber UUIDs in one call.
- Window capped at 120 days.
- Returns `{ data: { subscribers: [ { subscriber_uuid, available, error, busy: [ {starts_at, ends_at} ] } ] } }`.

Two properties matter more than the shape:

1. **`available: false` is not the same as `busy: []`.** If one subscriber's
   calendar cannot be read, that subscriber comes back `available: false` with an
   `error` code. Appointments treats that as "unknown", which means no slots for
   that person — never "free".
2. **No titles, ever.** The response carries intervals only. Appointments has no
   business knowing what a staff member's 14:00 is called, and free/busy is read
   for many people at once, so leaking subjects here would be a privacy problem
   at scale.

Cancelled events are excluded. Tentative events are included as busy — a
"maybe" is not a slot we may sell.

This is the single most important call in the product: `AvailabilityService`
makes exactly one of these per availability query, for every candidate staff
member at once, rather than one call per person per day.

### Conflict check — `POST /calendar/conflict-check` *(added for Appointments)*

```json
{ "subscribers": ["…"], "starts_at": "…", "ends_at": "…" }
→ { "data": { "free": true, "checked": true, "subscribers": [ … ] } }
```

Called immediately before a write, by `AvailabilityService::revalidate()`.
`checked: false` means Calendar could not establish an answer for at least one
subscriber; the response then carries `free: false`. Appointments turns that into
a 503 `calendar_unavailable` and refuses the booking. Unknown is never free — at
the Calendar end, in the client, in the service, and in the UI.

### Events CRUD — `POST/PATCH/DELETE /calendar/events`

`createEvent()`, `updateEvent()`, `cancelEvent()`. Appointments stores the
returned event UUID on the booking and nothing else about the event. A cancel
goes through Calendar's cancel so recurrence, attendees and provider sync behave
the way Calendar has decided they should.

These routes previously required a human session; they now accept either
(`BaseController::authAny()`), which is a widening of the auth path only — the
authorisation rules inside each handler are untouched.

### Provider accounts — `GET /calendar/provider-accounts`

Read-only, and **only** on a human session — `CalendarClient::providerAccounts()`
deliberately does not pass the service key. It backs the Integrations screen's
"this staff member's Google calendar is connected" line. Appointments does not
and must not integrate Google/Outlook/Apple directly; this is a read of
Calendar's own state.

## Changes made to Calendar

Everything is additive; nothing existing changed behaviour.

| File | Change |
| --- | --- |
| `app/Helpers/ServiceKeys.php` | **new** — `CalendarServiceKeys::resolveApp()` |
| `app/Controllers/BaseController.php` | **added** `authAny()`, `isServiceCaller()`, `callingApp()`. `auth()` is unchanged. |
| `app/Controllers/Calendar/CalendarFreeBusyController.php` | **new** — `freeBusy()`, `conflictCheck()` |
| `app/Controllers/Calendar/CalendarEventsController.php` | 5 × `auth()` → `authAny()` |
| `app/Config/Routes.php` | `calendar/free-busy` (GET), `calendar/conflict-check` (POST) |
| `server-php/.env.example` | documents `CALENDAR_SERVICE_KEYS` |
| `docs/CALENDAR_SCHEDULING_API.md` | **new** — Calendar-side contract |

Why free/busy had to be added: Calendar already computed free/busy arithmetic,
but only inside an AI-facing endpoint, and it had no service-key path at all.
Appointments therefore had no legitimate way to read a staff diary. The
alternative — copying events into the Appointments database — is the exact thing
the no-synchronisation rule forbids.

### What service keys deliberately cannot reach

The service-key path is scoped to events and free/busy. Provider OAuth flows,
stored provider tokens and sync settings remain human-session only. A service key
is a key to *scheduling*, not a key to a subscriber's connected Google account.

## Failure behaviour

| Calendar says | `CalendarClient` returns | API returns | UI does |
| --- | --- | --- | --- |
| 200, subscriber `available: true` | intervals | slots | offers them |
| 200, subscriber `available: false` | that subscriber unknown | slots omit that person | shows the rest |
| `conflict-check` `checked: false` | `calendar_unavailable` | 503 | banner + Book disabled |
| timeout / 5xx | `calendar_unavailable` | 503 | banner + Book disabled |
| 409 on create | `slot_taken` | 409 | "that time has just gone" + refresh |

On a create that succeeds in Appointments but fails at Calendar, the booking
persists with `calendar_sync_state = 'pending'`, the response says the calendar
event is not yet written, and `bin/calendar-retry.php` finishes the job. Customer
intent is never dropped because a downstream product blinked; it is also never
silently presented as confirmed in a diary it is not in.

## Local testing

`tests/stub/router.php` is a stateful Calendar stub: it persists created events
to a temp JSON file, honours PATCH and DELETE, serves free/busy from what it has
stored, and exposes `POST /stub/reset`. Stateful matters — with a stateless stub,
a booking's own event never shows up in a later free/busy call and buffer tests
pass for the wrong reason. `tests/run.sh` runs the HTTP suite twice, once with
the stub reachable and once with it down, so the degraded path is tested as a
first-class case rather than a comment.
