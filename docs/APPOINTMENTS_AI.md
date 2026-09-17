# Appointment intelligence

Appointments has its own AI engine. There is no centralised AICOUNTLY brain and
no shared automation service: the prompts, the rules, the thresholds, the timers,
the reminders and the waitlist matching all live in this repository, because they
are all *appointment* logic and nobody else should have an opinion about them.

What is central is the **provider key**, governed by Aicountly Console. This
product does not have its own AI credentials system, and it never writes a key to
disk.

## Where the key lives

`src/Ai/ConsoleCredentials.php`:

```
GET {CONSOLE_API_URL}/ai/credentials/resolve?domain=appointments.aicountly.com&module=appointments
Authorization: Bearer {CONSOLE_SERVICE_KEY}
```

- The response is held in **process memory**, and in **APCu** when the extension
  is present. Never a file, never the database, never a response body.
- Short TTL, honoured from Console's own `ttl_seconds`, so a rotation in Console
  takes effect without a deploy.
- `status()` reports `available`, the model name, and an `admin_hint` naming the
  missing env key — for the Integrations screen. It never reports a key value,
  a prefix, or a length.
- `ConsoleCredentials::overrideForTesting()` is a CLI-only seam so the suite can
  run the AI paths with no Console and no network.

`GET /v1/integrations` shows the model name to administrators. No endpoint, in
any role, returns the key. `web/` has no code path that could receive one.

## What the AI actually does, and what it does not

**Every number on every dashboard is this product's own SQL.** The model is
never asked to compute, count, rank, score or estimate anything. This is not a
style preference — a figure a model produced cannot be audited, and somebody
will plan staffing around these panels.

`InsightEngine` runs in two steps:

1. **Rules fire.** Eleven named rules evaluate real signals and decide whether
   there is anything worth saying. Each returns a `rule_key`, a weight, a title,
   a body, `evidence`, `rule_detail` (the thresholds it used), and an `action`
   linking to the screen where the thing can be dealt with.
2. **Optionally, the model rewrites the prose.** If AI is enabled, `AiClient::narrate()`
   is given the rule's `task` and its `grounding` — the same figures the rule
   used — and asked for two sentences. If it is disabled, unreachable, or the
   company has AI switched off, the rule's own wording is used.

Each insight carries `origin: 'rules' | 'ai'`, and it refers to **the wording
only**. The evidence and the numbers are identical either way. The UI labels a
rules-origin insight "rule-based" so nobody reads an absent model as a quiet
downgrade in accuracy.

Insights are cached per company, surface and period (`ON CONFLICT … DO UPDATE`),
so a dashboard refresh does not cost a model call.

### The eleven rules

| Surface | `rule_key` | Fires when |
| --- | --- | --- |
| Overview | `peak_demand_window` | a busiest hour has ≥ 3 appointments |
| Overview | `unconfirmed_backlog` | unconfirmed appointments are a material share of today |
| Overview | `waitlist_meets_gap` | a waitlisted client matches a slot that has come free |
| Live | `clinic_running_late` | appointments are running materially behind |
| Live | `calendar_write_failed` | a booking's Calendar event did not write |
| Capacity | `unused_capacity` | sellable time went unused |
| Capacity | `team_utilisation_spread` | the busiest and quietest staff member are far apart |
| Client experience | `reminder_channel_performance` | one reminder channel performs differently from the rest |
| Client experience | `no_show_leading_factor` | one no-show indicator dominates |
| Intelligence | `appointment_volume_trend` | volume moved against the previous period |
| Intelligence | `fastest_growing_service` | one service grew faster than the others |

`rulesFor()` in `InsightEngine.php` is the list that matters if this table ever
drifts from the code.

## Natural-language slot search

`AiClient::interpretSlotSearch()` turns "next Tuesday afternoon with Priya" into
structured filters. Two constraints make it safe:

- It is given a **closed vocabulary** — this company's actual services, staff and
  locations — and may only return identifiers from it. A hallucinated
  practitioner cannot come back, because anything not in the vocabulary is
  dropped.
- It produces **filters, not slots.** The filters go into
  `AvailabilityService`, which computes availability from Appointments' rules and
  a live Calendar free/busy call as it always does. The model never decides that
  a time is free.

With AI disabled, the same box still works on a keyword parser (weekday names,
`morning`/`afternoon`/`evening`, staff and service names). The feature degrades
in fluency, not in function.

## Explainable, not magic

Two scores are shown to users. Neither is a model output, and both can say why.

### No-show indicators — `src/Domain/NoShowRiskService.php`

Ten named indicators with fixed weights, summed; `AT_RISK_THRESHOLD = 5`.

| Indicator | Weight |
| --- | --- |
| `repeat_no_show` | 5 |
| `previous_no_show` | 4 |
| `not_confirmed` | 3 |
| `no_contact_channel` | 3 |
| `never_booked_before` | 2 |
| `form_incomplete` | 2 |
| `recent_reschedule` | 2 |
| `late_cancel_history` | 2 |
| `reminders_undelivered` | 2 |
| `long_lead_time` | 1 |

Every badge returns the indicators that fired, with their human labels. A
receptionist can see that a flag means "has missed an appointment before and has
not confirmed" — a thing they can act on — rather than "risk: high".

### Schedule Health — `src/Domain/ScheduleHealthService.php`

Starts at 100 and subtracts named components, each with a printed maximum:

| Component | Max penalty |
| --- | --- |
| No-shows | 25 |
| Appointments running more than 15 minutes late | 20 |
| Appointments nobody has confirmed | 15 |
| Gaps in the day nobody is in | 15 |
| Bookings whose calendar entry failed to write | 15 |
| Appointments running a few minutes late | 10 |

The response carries `components`, so 78 decomposes into the penalties that
produced it. A day with nothing in it scores 100 rather than 0 — an empty diary
is not an unhealthy one, and a capacity problem belongs on the Capacity
dashboard where it can be read correctly.

### Waitlist matching — `src/Domain/WaitlistService.php`

Weighted named `REASONS` (service match 40, preferred practitioner 25, daypart
15, priority 15, weekday 10, location 10, any-practitioner 10, waiting longest
10), a bounded `OFFER_WINDOW_MINUTES = 120`, and every match returns the reasons
it scored on. Whoever rings the client can tell them why they were called.

## Other AI-assisted surfaces

- **Smart slot suggestions** — availability is computed, then ordered by rules
  (keeps the day compact, respects the client's stated preference, avoids
  stranding an unsellable gap). The AI explains the ordering; it does not
  produce it.
- **Gap detection** — SQL over real bookings against working patterns.
- **Demand summaries** — SQL aggregates; optional narration.
- **Dashboard insights** — as above.

## Governance and cost

`ConsoleCredentials::reportUsage()` posts each call back to Console
fire-and-forget with a 2-second timeout, so usage telemetry can never delay or
fail a user-facing response. Console remains the single place to see where a key
is used, rotate it, or cut it off.

## If AI is off

Nothing breaks and nothing empties. Dashboards show rule-based insights, slot
search uses the keyword parser, risk badges and Schedule Health are unchanged
(they were never AI in the first place), and the Integrations card says
`setup_required` with the env key to set. That is the intended steady state for
a deployment that has not loaded a key into Console yet.
