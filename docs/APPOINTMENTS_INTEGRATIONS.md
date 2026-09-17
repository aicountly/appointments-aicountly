# Integrations

Every integration below is made by the Appointments **backend**, over the other
product's live HTTP API, with this product's service key. Nothing is copied into
the Appointments database and no service credential is ever sent to the browser.

The table is the honest state of each one as of this commit. The Integrations
screen (`GET /v1/integrations`) computes the same states at runtime by asking
each client whether it is configured and, where it matters, whether the other
end actually answered.

## Status vocabulary

| Status | Means |
| --- | --- |
| `connected` | Configured **and** it answered. |
| `available` | Working, but switched off for this company in Settings. |
| `setup_required` | The other product exists; this deployment has not configured it. The card names the missing env key (to administrators only). |
| `coming_soon` | The other product is not live yet. The contract and client exist; the panel stays empty. |
| `unavailable` | Not offered on this plan or host. |
| `error` | Configured, but the last call failed. |

Nothing reports `connected` on the strength of configuration alone. A key in
`.env` proves an administrator typed something, not that the other end is up.

## State of each integration

| Product | Live now | Status when unconfigured | What it owns | What Appointments does without it |
| --- | --- | --- | --- | --- |
| **Calendar** | yes — required | `setup_required`, marked `required` | calendars, events, times, recurrence, free/busy, external sync, conflicts | nothing. No availability, no bookings. Degraded banner, actions disabled. |
| **Contacts** | yes | `setup_required` | client names, phones, emails, addresses | bookings still work against a stored contact reference; names render as "Client" until Contacts answers |
| **Manage** | yes — required | n/a (always required) | company and branch master | requests 503 rather than guessing a branch is permitted |
| **Console** | yes (for AI) | `setup_required` | LLM provider keys and governance | insights still appear, labelled rule-based |
| **CRM** | flagged | `setup_required` | Customer 360, relationship history | the relationship panel is hidden, not blank |
| **Billing** | flagged | `setup_required` | invoices, tax, numbering | "raise invoice" is hidden |
| **Messaging** | flagged | `setup_required` | provider relationships, templates, delivery receipts | reminders are still computed and scheduled, and marked **not sent** |
| **Pay** | **not live** | `coming_soon` | payments, refunds, gateways, settlement | deposit *policy* is configurable; services needing a deposit cannot be booked online, because confirming one would imply money nobody collected |
| **Receptionist** | **not live** | `coming_soon` | calls, recordings, transcripts | Appointments already accepts and attributes bookings from a Receptionist service key; the contribution panel stays empty |
| **Connect** | **not live** | `coming_soon` | meeting rooms and join links | in-person, phone and external-video are unaffected; a Connect appointment falls back to phone |

Pay, Receptionist and Connect are the three that are genuinely unfinished on the
other side. Each has a real typed client, a real request/response contract, a
flag, and a named message — `PayClient::unavailableMessage()` returns
"Aicountly Pay integration is not enabled yet." Development of Appointments was
not blocked on any of them, and none of them is reported as completed.

## Feature flags

A flag is on only when it has been switched on **and** the thing it gates is
configured (`src/Features.php`). `APPOINTMENTS_PAY_ENABLED=1` with no
`PAY_SERVICE_KEY` counts as off, and `Features::explain()` says which key is
missing. That turns a half-finished deployment into a message on the
Integrations screen instead of a failure in front of a client mid-booking.

| Flag | Requires | Default |
| --- | --- | --- |
| `APPOINTMENTS_AI_ENABLED` | `CONSOLE_API_URL`, `CONSOLE_SERVICE_KEY` | off |
| `APPOINTMENTS_WAITLIST_ENABLED` | — | **on** |
| `APPOINTMENTS_PUBLIC_BOOKING_ENABLED` | — | **on** |
| `APPOINTMENTS_CONTACTS_ENABLED` | — | **on** |
| `APPOINTMENTS_PAY_ENABLED` | `PAY_SERVICE_KEY` | off |
| `APPOINTMENTS_RECEPTIONIST_ENABLED` | `RECEPTIONIST_SERVICE_KEY` | off |
| `APPOINTMENTS_CONNECT_ENABLED` | `CONNECT_SERVICE_KEY` | off |
| `APPOINTMENTS_CRM_ENABLED` | `CRM_SERVICE_KEY` | off |
| `APPOINTMENTS_BILLING_ENABLED` | `BILLING_SERVICE_KEY` | off |
| `APPOINTMENTS_MESSAGING_ENABLED` | `MESSAGING_SERVICE_KEY` | off |

The three default-on flags gate features Appointments owns outright, so the only
reason to turn one off is that a particular business does not want it.
Everything that depends on another product defaults to off.

Calendar has no flag. An appointments product with no calendar has nothing to
book against, and a flag that could switch it off would only ever be used to
hide a misconfiguration.

On top of the deployment flags, `Settings::featureEnabled()` lets one company
turn AI or public booking off for itself. A company can narrow what the
deployment allows; it can never widen it.

## How a call is made

`src/Clients/ApiClient.php` is the base class and the only place that knows how
to speak to another AICOUNTLY product:

- **Auth** — `X-Service-Key` (this product's key for that product) plus
  `X-Actor-Uuid` (the human the call is for). Both are set server-side.
- **Re-entry guard** — `X-Saas-Origin`, carried by `CrossServiceCallContext`,
  so A→B→A cannot deadlock a worker pool.
- **Correlation ID** — one per inbound request, attached to every outbound call
  and to every audit row, so a booking can be traced across products.
- **Two timeout budgets** — a short one for anything a human is waiting on, a
  longer one for cron work. A slow downstream degrades a panel; it does not hang
  a booking screen.
- **Memoisation** — per request. Five panels asking for the same company's
  services make one call.
- **Retries** — only on idempotent reads and only on connection-level failures.
  A `POST` that may have been received is never retried blind; that is what
  `Idempotency-Key` is for on the way in.
- **Error translation** — each client maps the other product's failures onto our
  codes (`calendar_unavailable`, `contact_not_found`, `pay_disabled`) so domain
  code never branches on somebody else's HTTP status.

## Inbound: other products calling Appointments

Appointments is itself a live API for the rest of the fleet. A caller presenting
a valid `X-Service-Key` and `X-Actor-Uuid` gets the same endpoints a human gets,
subject to the same permissions.

`Auth::provenBookingSource()` derives the booking source from the *authenticated
caller*, not from a field in the request body:

```
public session          → STAFF_BOOKING
public booking page     → ONLINE_BOOKING
receptionist service key→ RECEPTIONIST
crm service key         → CRM
```

A caller cannot claim to be Receptionist by putting `"source": "RECEPTIONIST"`
in a payload. That is why the Booking Source figures on the dashboards mean
something.

## Ownership boundaries kept in code

- `MessagingClient` refuses a `voice` channel with
  `voice_belongs_to_receptionist`. Voice is Receptionist's, and the refusal is a
  code path rather than a comment.
- `CalendarClient::providerAccounts()` deliberately omits the service key: it is
  a human-session read only. Appointments does not integrate Google, Outlook or
  Apple — it reads what Calendar already knows.
- Appointments has no contact master, no employee master, no branch master and no
  invoice numbering. It holds a reference UUID for each and reads the rest live.

See `APPOINTMENTS_DATA_OWNERSHIP.md` for the full table and for the two apparent
exceptions (a booking's agreed `starts_at`, and slot holds) and why they are not
copies.
