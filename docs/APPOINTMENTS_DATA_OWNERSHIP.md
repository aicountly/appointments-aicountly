# Data ownership

This is the shortest document in the repository and the most important one.
Everything else in `docs/` explains *how* Appointments works; this says what it
is allowed to know.

## The rule

**AICOUNTLY does not synchronise databases across products.** When one product
needs something another one owns, it calls that product's live API on the
request that needs it.

No copied tables. No mirror tables. No periodic imports. No cross-database
queries, triggers, CDC or shadow records. Not "for performance", not "just the
fields we need", not "only until the API is ready".

## Who owns what

| Owned by | What | What Appointments keeps |
| --- | --- | --- |
| **Aicountly Calendar** | Calendars, calendar events, start/end times, recurrence, free/busy, blocked time, conflicts, Google/Outlook/Apple sync | `calendar_event_uuid`, `calendar_subscriber_uuid` |
| **Aicountly Contacts** | Client names, phone numbers, emails, addresses | `contact_uuid` + appointment-specific preferences |
| **Aicountly Manage** | Companies, branches, people | `cmp_id`, `bo_id`, `user_uuid` |
| **Aicountly Pay** | Payments, gateways, transactions, refunds, settlement, payment status | `payment_request_uuid` + the last status Pay reported |
| **Aicountly Receptionist** | Calls, recordings, transcripts, voice conversations, telephony | `receptionist_session_uuid` |
| **Aicountly Connect** | Meeting rooms and what happens inside them | `connect_room_uuid`, the join URL |
| **Aicountly CRM** | Customer 360, relationship history, health | `crm_account_uuid` |
| **Aicountly Billing** | Invoices, tax, numbering | `billing_document_uuid` |
| **Aicountly Console** | LLM provider keys and provider governance | nothing — keys are fetched per request, held in memory |
| **Messaging provider** | Templates, sender identity, delivery receipts | a message reference per reminder |

## What Appointments owns

Services and their durations, buffers, notice and horizon. Staff eligibility and
working hours. Location and resource configuration. Booking policies —
cancellation, reschedule, no-show, confirmation. Booking forms and the answers
to them. The appointment record itself and its lifecycle. Slot holds. The
waitlist and its matching. Reminder rules, the reminder schedule and the timers
behind it. Client appointment preferences. Public booking pages. Its own AI
prompts, rules and insights. Its own audit log.

## The two that look like exceptions and are not

### An appointment stores `starts_at`

It does, and it is **not** a copy of the Calendar event. It is the booking's
*intent*: what was agreed with the client, what the reminder timer counts down
to, what the cancellation window is measured against.

The distinction earns its keep the moment a practitioner drags the event half an
hour later in Google Calendar. The event moves. The agreed time did not — nobody
told the client — and the product needs to be able to say so rather than quietly
agreeing with whichever copy it read last. `GET /v1/bookings/{id}` returns both,
and the UI shows the disagreement.

### Slot holds live in Appointments

Two clients open the same 3pm; one starts typing. Calendar has no concept of a
tentative claim that expires, and it should not grow one — an expiring
almost-event is booking-flow state, and putting it in the product that owns
diaries would mean Calendar acquiring a timer, a cleanup job and an opinion
about checkout length.

So the hold is Appointments' own, because booking coordination *is* this
product's domain. A hold never becomes a calendar event and never appears on
anybody's diary. Calendar stays canonical for the event, which is written once
the booking is real.

## What this costs, and why it is worth it

Live reads cost latency and a dependency. The alternative costs correctness: two
products holding two answers to the same question, and something — usually
nobody — having to reconcile them.

The place this is most visible is availability. Appointments will not offer a
slot it could not confirm with Calendar. Not rule-derived slots with a warning:
somebody would book one, and two people would arrive at three o'clock. A brief
"availability is temporarily unavailable" is a smaller failure than a double
booking, and it is recoverable.

## If you are about to break this rule

You are probably looking at a slow dashboard and a tempting `CREATE TABLE`. The
answers that have worked so far, in order:

1. **Ask for more in one call.** Free/busy takes a list of subscribers. A week
   of capacity across eight practitioners is one request.
2. **Memoise within the request.** `ApiClient` already does, for GETs. It dies
   with the process, on purpose.
3. **Aggregate on our side.** Every dashboard figure that can come from this
   product's own tables does.
4. **Degrade honestly.** A panel that says "not reported" is better than a
   cached number that is wrong.

None of those is a copy of somebody else's data, and none of them can go stale.
