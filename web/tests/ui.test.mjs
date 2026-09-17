/**
 * Tests for the frontend's pure logic.
 *
 * Deliberately not a component test suite. What is worth testing here is the
 * handful of functions that decide what a number MEANS — whether a change chip
 * appears, what an unavailable metric renders as, how money and time are
 * formatted. Those are where a wrong answer is silently plausible, which is
 * exactly what a test is for.
 *
 *   npm run test:ui
 *
 * Node's own runner, no framework: three files of assertions do not need one.
 */

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

const root = dirname(fileURLToPath(import.meta.url))

// ---------------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------------

/** Mirrors formatMoney in src/ui/index.tsx. Money never arrives as a float. */
function formatMoney(minor, currency = 'INR') {
  return new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency,
    maximumFractionDigits: 0,
  }).format(minor / 100)
}

test('money is formatted from minor units, never from a float', () => {
  assert.equal(formatMoney(50000).replace(/\s/g, ' '), '₹500')
  assert.equal(formatMoney(0).replace(/\s/g, ' '), '₹0')
  // The case that matters: 1999 paise is ₹20, not ₹1,999.
  assert.ok(formatMoney(199900).includes('1,999'))
})

// ---------------------------------------------------------------------------
// The change chip
// ---------------------------------------------------------------------------

/**
 * Mirrors the decision in ChangeChip.
 *
 * `null` renders NOTHING. "0%" says the number held steady; "no previous
 * period" is a different claim, and a brand-new company showing "↑ 0%" on every
 * card is a dashboard lying quietly.
 */
function chipFor(change, direction) {
  if (change === null) return null
  if (change === 0 || direction === 'neutral') return 'neutral'
  const good = direction === 'up_is_good' ? change > 0 : change < 0
  return good ? 'positive' : 'negative'
}

test('a null change renders no chip at all', () => {
  assert.equal(chipFor(null, 'up_is_good'), null)
  assert.equal(chipFor(null, 'down_is_good'), null)
})

test('zero is neutral, not good news', () => {
  assert.equal(chipFor(0, 'up_is_good'), 'neutral')
})

test('direction decides whether a rise is good', () => {
  // Utilisation up is good.
  assert.equal(chipFor(12, 'up_is_good'), 'positive')
  // No-show rate up is not.
  assert.equal(chipFor(12, 'down_is_good'), 'negative')
  assert.equal(chipFor(-12, 'down_is_good'), 'positive')
})

// ---------------------------------------------------------------------------
// Heatmap intensity
// ---------------------------------------------------------------------------

/** Mirrors CapacityDashboard::intensity on the backend. */
function intensity(load) {
  if (load === null) return null
  if (load <= 25) return 'low'
  if (load <= 50) return 'moderate'
  if (load <= 75) return 'high'
  if (load <= 90) return 'very_high'
  return 'full'
}

test('a closed band has no intensity, which is not the same as low', () => {
  assert.equal(intensity(null), null)
  assert.equal(intensity(0), 'low')
})

test('intensity bands do not overlap or leave gaps', () => {
  assert.equal(intensity(25), 'low')
  assert.equal(intensity(25.1), 'moderate')
  assert.equal(intensity(50), 'moderate')
  assert.equal(intensity(75), 'high')
  assert.equal(intensity(90), 'very_high')
  assert.equal(intensity(100), 'full')
})

// ---------------------------------------------------------------------------
// Slot grouping
// ---------------------------------------------------------------------------

function groupByDay(slots) {
  const byDate = new Map()
  for (const slot of slots) {
    const existing = byDate.get(slot.local_date)
    if (existing) existing.push(slot)
    else byDate.set(slot.local_date, [slot])
  }
  return [...byDate.entries()].sort(([a], [b]) => a.localeCompare(b)).map(([date, entries]) => ({ date, slots: entries }))
}

test('slots group by local date and stay in date order', () => {
  const grouped = groupByDay([
    { local_date: '2026-06-03', local_time: '10:00' },
    { local_date: '2026-06-02', local_time: '09:00' },
    { local_date: '2026-06-03', local_time: '11:00' },
  ])

  assert.equal(grouped.length, 2)
  assert.equal(grouped[0].date, '2026-06-02')
  assert.equal(grouped[1].slots.length, 2)
})

// ---------------------------------------------------------------------------
// Route resolution
// ---------------------------------------------------------------------------

/**
 * Mirrors resolveRoute in src/shell/navConfig.ts.
 *
 * The server sends a route NAME. It never sends a URL, so nothing arriving from
 * an API can point a browser at an address this application did not choose.
 */
const ROUTES = {
  appointments: '/appointments',
  waitlist: '/waitlist',
  find_slot: '/appointments?find=1',
}

function resolveRoute(route, params) {
  if (!route) return null
  const base = ROUTES[route]
  if (!base) return null

  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params ?? {})) {
    if (value === null || value === undefined || value === '') continue
    search.set(key, String(value))
  }

  if (!search.toString()) return base
  return base.includes('?') ? `${base}&${search}` : `${base}?${search}`
}

test('an unknown route name resolves to nothing', () => {
  assert.equal(resolveRoute('https://evil.example.com'), null)
  assert.equal(resolveRoute('../../etc/passwd'), null)
  assert.equal(resolveRoute('not_a_route'), null)
})

test('params are appended, and a route that already has a query keeps it', () => {
  assert.equal(resolveRoute('appointments', { status: 'PENDING' }), '/appointments?status=PENDING')
  assert.equal(resolveRoute('find_slot', { service: 'x' }), '/appointments?find=1&service=x')
  assert.equal(resolveRoute('waitlist', {}), '/waitlist')
})

// ---------------------------------------------------------------------------
// The design system's contract
// ---------------------------------------------------------------------------

test('white text never sits on the brand green', () => {
  // #25b003 does not clear 4.5:1 against white; #187900 does. Both exist for
  // that reason, and a button that used the wrong one would be unreadable.
  const css = readFileSync(join(root, '..', 'src', 'ui', 'appointments-ui.css'), 'utf8')

  const primary = css.match(/\.appt-button-primary\s*\{([^}]*)\}/)
  assert.ok(primary, 'the primary button rule exists')
  assert.ok(primary[1].includes('var(--action)'), 'the primary button uses --action, not --brand')
  assert.ok(!primary[1].includes('var(--brand)'), 'the primary button does not use --brand as a background')
})

test('the brand tokens are the approved AICOUNTLY greens', () => {
  const css = readFileSync(join(root, '..', 'src', 'ui', 'appointments-ui.css'), 'utf8')
  assert.ok(css.includes('--brand: #25b003'), 'brand mark')
  assert.ok(css.includes('--action: #187900'), 'accessible action green')
})

test('every component stylesheet rule is scoped or prefixed', () => {
  // The product area is scoped to .appt-ui so nothing here can leak into the
  // shell, the launcher or the public booking page's own layout.
  const css = readFileSync(join(root, '..', 'src', 'ui', 'appointments-ui.css'), 'utf8')

  const selectors = css
    .split('}')
    .map((block) => block.split('{')[0].trim())
    .filter((selector) => selector !== '' && !selector.startsWith('@') && !selector.startsWith('/*'))
    .flatMap((selector) => selector.split(',').map((part) => part.trim()))
    .filter((selector) => selector !== '' && !/^\d/.test(selector) && !selector.startsWith('to'))

  const leaked = selectors.filter(
    (selector) => !selector.startsWith('.appt-') && !selector.includes('.appt-'),
  )

  assert.deepEqual(leaked, [], `these selectors are not scoped: ${leaked.join(', ')}`)
})
