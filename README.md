# appointments-aicountly

Appointments for Aicountly — a React single-page app built with Vite and TypeScript,
with a small PHP API alongside it. Both halves deploy to cPanel.

| Environment | App | API |
| --- | --- | --- |
| Production | https://appointments.aicountly.com | https://appointments.aicountly.com/api |
| Sandbox | https://appointments.gh.aicountly.com | https://appointments.gh.aicountly.com/api |

## What this app does

Appointments books people into staff diaries: services, staff availability,
resources, waitlist, public booking pages, forms, reminders and the full booking
lifecycle, with five purpose-built dashboards over the top.

It owns the **booking**. It does not own the diary and it does not own the
person:

- **Aicountly Calendar** owns every calendar, event, time, recurrence, free/busy
  answer and external provider sync. Appointments reads availability and writes
  events through Calendar's live API and stores only the event reference.
- **Aicountly Contacts** owns client identity.
- **Manage** owns companies and branches, **Pay** owns money, **Receptionist**
  owns voice, **Connect** owns rooms, **CRM** owns Customer 360, **Billing** owns
  invoices, **Console** owns AI provider keys.

**There is no cross-application database synchronisation anywhere in this
product.** No mirrored tables, no periodic imports, no cross-database queries, no
shadow copies. Every cross-product read is a live API call made by this backend.
If a product cannot answer, the screen says so — it never falls back to a stale
local copy. That rule is not negotiable, and
[docs/APPOINTMENTS_DATA_OWNERSHIP.md](docs/APPOINTMENTS_DATA_OWNERSHIP.md)
explains what it costs and what to do instead when it feels expensive.

Signing in is the AICOUNTLY portal's job, the same as every other AICOUNTLY
SaaS: the app redirects to the portal, the portal returns an `auth_token`, and
the app exchanges it for a short-lived session key. A user who is already signed
in to another AICOUNTLY product lands straight on the dashboard.

See [docs/auth/AICOUNTLY_AUTH_WORKFLOW.md](docs/auth/AICOUNTLY_AUTH_WORKFLOW.md).

### The five dashboards

Five distinct dashboards, not one dashboard with the widgets rearranged. Each
answers a different question:

| Route | Question it answers |
| --- | --- |
| `/dashboard/overview` | What is happening today and what needs a decision? |
| `/dashboard/live` | What is happening *right now* — who has arrived, who is late, who is waiting? |
| `/dashboard/capacity` | Is sellable time being used, and by whom? |
| `/dashboard/client-experience` | How does being a client of this business feel? |
| `/dashboard/intelligence` | What is changing over time, and what should we do about it? |

Schedule Health is a decomposed score, not a magic number: it starts at 100 and
subtracts named components, and the response carries every penalty that produced
the figure. Same for no-show risk — ten named indicators with printed weights, and
every badge can say which of them fired. See
[docs/APPOINTMENTS_AI.md](docs/APPOINTMENTS_AI.md).

## Documentation

| Document | What it covers |
| --- | --- |
| [docs/APPOINTMENTS_ARCHITECTURE.md](docs/APPOINTMENTS_ARCHITECTURE.md) | layers, request lifecycle, storage, background work, degraded mode |
| [docs/APPOINTMENTS_DATA_OWNERSHIP.md](docs/APPOINTMENTS_DATA_OWNERSHIP.md) | who owns what, the no-synchronisation rule, the two apparent exceptions |
| [docs/APPOINTMENTS_INTEGRATIONS.md](docs/APPOINTMENTS_INTEGRATIONS.md) | every integration, its honest state, the feature flags |
| [docs/CALENDAR_API_INTEGRATION.md](docs/CALENDAR_API_INTEGRATION.md) | the Calendar contract, the endpoints added for this, failure behaviour |
| [docs/APPOINTMENTS_AI.md](docs/APPOINTMENTS_AI.md) | the insight engine, Console-governed keys, the explainable scores |
| [docs/APPOINTMENTS_SECURITY.md](docs/APPOINTMENTS_SECURITY.md) | auth, tenant isolation, RBAC, public booking protections, audit |
| [docs/ui/appointments-dashboard-reference.html](docs/ui/appointments-dashboard-reference.html) | the visual reference — **not** production code |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | cPanel deployment |

## Layout

```
web/          React app (Vite). Builds to web/dist, deployed to the document root.
server-php/   PHP API. Deployed to the api/ folder inside the document root.
  src/          framework, Clients/ (cross-product), Domain/, Ai/, Dashboards/, Controllers/
  database/     migrations
  bin/          migrate.php, reminders.php, calendar-retry.php
  tests/        integration + HTTP suites and the Calendar stub
docs/         architecture, ownership, integrations, security, deployment, auth
```

## Getting started

Requires Node.js 22 or newer.

```bash
cd web
npm install
cp ../.env.example ../.env
npm run dev
```

The dev server runs on http://localhost:5173 and signs in through the **sandbox**
portal. Point `VITE_API_BASE_URL` at the deployed sandbox API
(`https://appointments.gh.aicountly.com/api`) so the token exchange has somewhere to
go — and add `http://localhost:5173` to `CORS_ALLOWED_ORIGINS` in that server's
`api/.env`, since localhost is the one case where the app and API are not
same-origin.

| Script | Purpose |
| --- | --- |
| `npm run dev` | Vite dev server on http://localhost:5173 |
| `npm run build` | Type-check, then build to `web/dist/` |
| `npm run typecheck` | Type-check only |
| `npm run test:ui` | Frontend tests (`node --test`) |
| `npm run preview` | Serve the production build locally |

The PHP API has no build step and no dependencies. To run it locally:

```bash
cd server-php
cp .env.example .env      # set APP_ENV=local
php -S localhost:8000
```

### Database

Appointments has its **own** PostgreSQL database, holding appointment-owned data
only. It is never pointed at Calendar's, Contacts' or anybody else's.

```bash
php server-php/bin/migrate.php     # applies database/migrations/ in order
curl https://<host>/api/health     # reports usable:false until Calendar is configured
```

Migrations are idempotent and safe to re-run. Times are stored in UTC as
`TIMESTAMPTZ` and rendered in the company's timezone.

### Background jobs

Two CLI entry points, both safe to run repeatedly. Add them to cron on the
server:

```
*/5 * * * *  php <document root>/api/bin/reminders.php
*/5 * * * *  php <document root>/api/bin/calendar-retry.php
```

`reminders.php` sends what is due. `calendar-retry.php` finishes writing Calendar
events for bookings that were taken while Calendar was unreachable — customer
intent is never dropped because a downstream product blinked, and never shown as
confirmed in a diary it is not in.

### Tests

```bash
server-php/tests/run.sh    # 76 integration + 31 HTTP tests, the HTTP suite run twice
cd web && npm run test:ui  # frontend tests
cd web && npm run build    # type-check, then build
```

`run.sh` runs the HTTP suite twice — once with a stateful Calendar stub
reachable, once with it down — so the degraded path is a tested case rather than
a comment. It needs a PostgreSQL database it may write to; see the script for the
variables.

## Environment variables

`.env` is git-ignored and is never deployed — `.env.example` is the tracked
template. There are two of them, and they work in opposite ways:

| File | Read | Used by |
| --- | --- | --- |
| `.env.example` | **Build time**, inlined into the bundle | `web/` |
| `server-php/.env.example` | **Runtime**, on every request | `server-php/` |

| Variable | Description |
| --- | --- |
| `VITE_API_BASE_URL` | API base URL. Empty = this app's own origin + `/api` |
| `VITE_APP_NAME` | Display name shown in the UI |
| `VITE_APP_ENV` | `local`, `sandbox`, or `production` |
| `VITE_PRODUCT_KEY` | Portal product key. Derived from the hostname when unset |
| `VITE_PORTAL_LOGIN_URL` | Login portal override. Local development only |

Only `VITE_`-prefixed variables reach the browser bundle, and Vite inlines them
at build time, so **treat every one of them as public**. Never put a secret,
token, or password in a `VITE_` variable.

### These are build-time values, not runtime values

This matters for how you change an endpoint in production.

Vite substitutes each `VITE_*` value into the JavaScript bundle when the app is
compiled. The deployed result is plain static files — **the app never reads a
`.env` from disk at runtime**, so placing a `.env` next to it in the cPanel
document root has no effect. Changing an endpoint means rebuilding and
redeploying.

This is the opposite of `server-php`, which is PHP and does read its own `.env`
on every request.

### Server variables

`server-php/.env.example` is the documented list, and it is the one to read — it
explains what each variable does and what happens when it is missing. The short
version:

| Variable | Required | Notes |
| --- | --- | --- |
| `DB_HOST` `DB_PORT` `DB_NAME` `DB_USER` `DB_PASS` | **yes** | Appointments' own database |
| `CALENDAR_SERVICE_KEY` | **yes** | without it there is no availability and no booking; `/api/health` reports `usable:false` |
| `APP_ENV`, `APP_PRODUCT_KEY` | yes | `APP_PRODUCT_KEY=appointments` is the cross-product re-entry guard |
| `SERVICE_KEYS` | for inbound | `product:key` pairs for products that call Appointments — Receptionist, CRM |
| `CONSOLE_API_URL`, `CONSOLE_SERVICE_KEY` | for AI | the LLM provider key lives in Console and is never written here |
| `APPOINTMENTS_*_ENABLED` | no | feature flags; a flag without its key counts as **off** |
| `*_SERVICE_KEY`, `*_API_BASE` | no | per-product keys and base URLs; base URLs are derived from this host's name when unset, so sandbox talks to sandbox |

There is deliberately **no provider key and no LLM key** in this file. Generate
service keys with `openssl rand -hex 32`.

## Deployment

Deployment is **manual only**. Nothing deploys on push or merge — both
workflows trigger exclusively via `workflow_dispatch`.

To deploy: **Actions** → pick a workflow → **Run workflow** → pick a branch →
**Run**.

| Workflow | Deploys | To |
| --- | --- | --- |
| Deploy to cPanel Production | `web/dist/` then `server-php/` | document root, then `api/` inside it |
| Deploy to cPanel Sandbox | `web/dist/` then `server-php/` | document root, then `api/` inside it |

Production and sandbox deploy separately, so releasing to one cannot disturb
the other. Within one environment, web and API deploy together in the same
run — they always change in step, so there is no separate "API only" workflow
to remember to run. Source, `node_modules`, and `.env` never reach the server.

Before deploying, each workflow checks that every required SSH secret is set and
that the remote root is a safe path, so a misconfigured repository fails in
seconds instead of part-way through a deploy.

### Configuration

These repository **secrets** must be set (Settings → Secrets and variables →
Actions → Secrets):

`PROD_SSH_HOST`, `PROD_SSH_PORT`, `PROD_SSH_USER`, `PROD_SSH_PRIVATE_KEY`,
`PROD_SSH_REMOTE_ROOT` — and the same five with a `SANDBOX_` prefix.

`*_SSH_REMOTE_ROOT` is the document root to deploy into. It may be relative,
which is the usual cPanel form — `public_html` resolves against the SSH user's
home directory, giving `/home/<user>/public_html`. An absolute path works too.
Because the deploy runs with `--delete`, the workflow refuses a value that would
resolve to the home directory itself (`.`, `~`, empty), a system directory, or
anything containing `..`.

The repository **variables** `PROD_API_BASE_URL` and `SANDBOX_API_BASE_URL` are
optional. Unset, the app calls its own origin + `/api` — which is where the same
workflow puts the API. Set one only to point the app at a different API domain.

### Notes on the rsync steps

Each workflow runs two `rsync --delete` steps, one after the other, and the
excludes are what make that safe.

The **web** step syncs the document root and excludes:

- `api/` — the PHP backend lives inside the document root and is deployed by the
  next step in the same run. **Without this exclude the web step would delete
  the entire API.**
- `.well-known/` — Let's Encrypt / AutoSSL validation; removing it breaks
  certificate renewal
- `cgi-bin/` — cPanel-managed, present in every document root
- `.env`, `.env.*`, `.git*` — never published

The **API** step syncs `api/` and excludes `.env`, `.env.*` and `.git*`: the
API's `.env` is created once on the server and read at runtime, so it must
survive every deploy. See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

`web/public/.htaccess` ships with the build and provides the SPA history
fallback — which is also what serves the portal's `/auth/callback` landing — plus
cache headers (`index.html` uncached, hashed assets cached for a year).
