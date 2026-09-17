/**
 * The Appointments component library.
 *
 * Every screen is built from these, which is what stops a dashboard drifting
 * away from the list it links to. The rules they encode are the ones this
 * product would otherwise get wrong in a dozen places:
 *
 *  - A metric with nothing to compare against shows NO change, not 0%.
 *  - A number this deployment cannot obtain shows why, not a zero.
 *  - Loading, empty and error are three different screens and each has one.
 *  - A drilldown is a route NAME resolved against our own table, never a URL
 *    from an API response.
 */

import { useEffect, useId, useRef, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import {
  AlertTriangle,
  ArrowDownRight,
  ArrowUpRight,
  ChevronRight,
  Info,
  Loader2,
  Minus,
  Sparkles,
  X,
} from 'lucide-react'
import type { Metric, Insight, Freshness } from '../services/types'
import './appointments-ui.css'

// ---------------------------------------------------------------------------
// Buttons
// ---------------------------------------------------------------------------

export function Button({
  tone = 'default',
  small,
  busy,
  children,
  ...rest
}: {
  tone?: 'default' | 'primary' | 'danger' | 'ghost'
  small?: boolean
  busy?: boolean
  children: ReactNode
} & React.ButtonHTMLAttributes<HTMLButtonElement>) {
  const className = [
    'appt-button',
    tone === 'primary' ? 'appt-button-primary' : '',
    tone === 'danger' ? 'appt-button-danger' : '',
    tone === 'ghost' ? 'appt-button-ghost' : '',
    small ? 'appt-button-small' : '',
  ]
    .filter(Boolean)
    .join(' ')

  return (
    <button type="button" className={className} disabled={busy || rest.disabled} {...rest}>
      {busy && <Loader2 size={14} className="appt-spin" aria-hidden />}
      {children}
    </button>
  )
}

// ---------------------------------------------------------------------------
// Metrics
// ---------------------------------------------------------------------------

export function formatMetric(value: number | null, unit: Metric['unit'], currency = 'INR'): string {
  if (value === null) return '—'

  switch (unit) {
    case 'percent':
      return `${roundTo(value, 1)}%`
    case 'hours':
      return `${roundTo(value, 1)}`
    case 'score':
      return `${Math.round(value)}`
    case 'currency':
      return formatMoney(value, currency)
    default:
      return new Intl.NumberFormat('en-IN').format(value)
  }
}

/** Minor units in, a readable amount out. Money never arrives as a float. */
export function formatMoney(minor: number, currency = 'INR'): string {
  try {
    return new Intl.NumberFormat('en-IN', {
      style: 'currency',
      currency,
      maximumFractionDigits: 0,
    }).format(minor / 100)
  } catch {
    return `${currency} ${(minor / 100).toFixed(0)}`
  }
}

function roundTo(value: number, places: number): string {
  const rounded = Number(value.toFixed(places))
  return String(rounded)
}

export function MetricCard({
  metric,
  currency,
  loading,
  icon,
  onOpen,
}: {
  metric: Metric
  currency: string
  loading?: boolean
  icon?: ReactNode
  onOpen?: (metric: Metric) => void
}) {
  const clickable = !loading && metric.drilldown !== null && onOpen !== undefined
  const unavailable = metric.status === 'unavailable'

  const body = (
    <>
      {icon && <span className="appt-metric-icon" aria-hidden>{icon}</span>}
      <span className="appt-metric-label">{metric.label}</span>

      {loading ? (
        <span className="appt-skeleton" style={{ height: 30, width: '60%' }} aria-hidden />
      ) : (
        <span className="appt-metric-value" data-loading={unavailable ? 'true' : undefined}>
          {unavailable ? '—' : formatMetric(metric.value, metric.unit, currency)}
          {metric.unit === 'hours' && !unavailable && (
            <span style={{ fontSize: 15, fontWeight: 600, marginLeft: 3 }}>hrs</span>
          )}
        </span>
      )}

      <span className="appt-metric-foot">
        {unavailable ? (
          <span className="appt-tone-neutral">{metric.unavailable_reason}</span>
        ) : (
          <>
            <ChangeChip change={metric.change_pct} direction={metric.direction} />
            {metric.note && <span>{metric.note}</span>}
          </>
        )}
      </span>
    </>
  )

  if (clickable) {
    return (
      <button
        type="button"
        className="appt-metric"
        data-clickable="true"
        onClick={() => onOpen?.(metric)}
        title={`Open ${metric.label.toLowerCase()}`}
      >
        {body}
      </button>
    )
  }

  return <div className="appt-metric">{body}</div>
}

/**
 * The ± chip.
 *
 * `null` renders NOTHING, not "0%". Those are different claims: one says the
 * number held steady, the other says there is no previous period to compare
 * against. A brand-new company showing "↑ 0%" on every card is a dashboard
 * lying quietly.
 */
export function ChangeChip({
  change,
  direction,
}: {
  change: number | null
  direction: Metric['direction']
}) {
  if (change === null) return null

  const good = direction === 'neutral' ? null : direction === 'up_is_good' ? change > 0 : change < 0
  const tone = change === 0 || good === null ? 'appt-tone-neutral' : good ? 'appt-tone-positive' : 'appt-tone-negative'
  const Icon = change === 0 ? Minus : change > 0 ? ArrowUpRight : ArrowDownRight

  return (
    <span className={tone} style={{ display: 'inline-flex', alignItems: 'center', gap: 2 }}>
      <Icon size={13} aria-hidden />
      {Math.abs(change)}%
    </span>
  )
}

export function MetricRow({
  metrics,
  currency,
  loading,
  icons,
  onOpen,
}: {
  metrics: Metric[] | undefined
  currency: string
  loading: boolean
  icons?: Record<string, ReactNode>
  onOpen?: (metric: Metric) => void
}) {
  // A finished load with no metrics means the load FAILED, and the frame is
  // already showing why. Falling back to placeholders here would leave cards
  // reading "loading" for as long as the tab stayed open — a spinner that never
  // resolves tells somebody the app is slow when it is actually refusing.
  if (!loading && !metrics) return null

  const rows: Metric[] = loading
    ? Array.from({ length: 6 }, (_, index) => ({
        id: `placeholder-${index}`,
        label: '—',
        value: null,
        unit: 'count' as const,
        previous: null,
        change_pct: null,
        direction: 'neutral' as const,
        note: null,
        status: null,
        unavailable_reason: null,
        drilldown: null,
      }))
    : (metrics as Metric[])

  return (
    <div className="appt-metric-row">
      {rows.map((metric) => (
        <MetricCard
          key={metric.id}
          metric={metric}
          currency={currency}
          loading={loading}
          icon={icons?.[metric.id]}
          onOpen={onOpen}
        />
      ))}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Panels and states
// ---------------------------------------------------------------------------

export function Panel({
  title,
  subtitle,
  action,
  info,
  children,
}: {
  title: string
  subtitle?: string
  action?: ReactNode
  info?: string
  children: ReactNode
}) {
  return (
    <section className="appt-panel">
      <header className="appt-panel-header">
        <div>
          <h3>
            {title}
            {info && (
              <span title={info} style={{ marginLeft: 6, color: 'var(--muted)', verticalAlign: 'middle' }}>
                <Info size={13} aria-hidden />
                <span className="appt-visually-hidden">{info}</span>
              </span>
            )}
          </h3>
          {subtitle && <p>{subtitle}</p>}
        </div>
        {action}
      </header>
      {children}
    </section>
  )
}

export function Notice({
  tone = 'info',
  title,
  action,
  children,
}: {
  tone?: 'info' | 'warning' | 'danger' | 'success'
  title: string
  action?: ReactNode
  children?: ReactNode
}) {
  const Icon = tone === 'danger' || tone === 'warning' ? AlertTriangle : Info

  return (
    <div className="appt-notice" data-tone={tone} role={tone === 'danger' ? 'alert' : 'status'}>
      <Icon size={17} aria-hidden style={{ marginTop: 1 }} />
      <div>
        <strong>{title}</strong>
        {children && <p>{children}</p>}
      </div>
      {action ?? <span />}
    </div>
  )
}

export function EmptyState({
  title,
  children,
  action,
}: {
  title: string
  children?: ReactNode
  action?: ReactNode
}) {
  return (
    <div className="appt-state" role="status">
      <strong>{title}</strong>
      {children && <p>{children}</p>}
      {action}
    </div>
  )
}

export function LoadingRows({ rows = 4, height = 56 }: { rows?: number; height?: number }) {
  return (
    <div className="appt-stack" aria-busy="true" aria-label="Loading">
      {Array.from({ length: rows }, (_, index) => (
        <div key={index} className="appt-skeleton" style={{ height }} />
      ))}
    </div>
  )
}

/**
 * One place that decides what to render for a panel.
 *
 * Every major widget needs loading, empty, error and — the one people forget —
 * partial: the panel loaded but the product it depends on did not. Routing all
 * four through here is how they stay consistent and how none of them ends up
 * as a blank rectangle.
 */
export function PanelState<T>({
  loading,
  error,
  data,
  isEmpty,
  emptyTitle,
  emptyBody,
  onRetry,
  skeletonRows,
  children,
}: {
  loading: boolean
  error: Error | null
  data: T | null | undefined
  isEmpty?: (data: T) => boolean
  emptyTitle: string
  emptyBody?: string
  onRetry?: () => void
  skeletonRows?: number
  children: (data: T) => ReactNode
}) {
  if (loading) return <LoadingRows rows={skeletonRows ?? 4} />

  if (error) {
    const retryable = 'retryable' in error ? Boolean((error as { retryable: boolean }).retryable) : true

    return (
      <Notice
        tone="danger"
        title="This could not be loaded"
        action={
          onRetry && retryable ? (
            <Button small onClick={onRetry}>
              Retry
            </Button>
          ) : undefined
        }
      >
        {error.message}
      </Notice>
    )
  }

  if (data === null || data === undefined) {
    return <EmptyState title={emptyTitle}>{emptyBody}</EmptyState>
  }

  if (isEmpty?.(data)) {
    return <EmptyState title={emptyTitle}>{emptyBody}</EmptyState>
  }

  return <>{children(data)}</>
}

// ---------------------------------------------------------------------------
// Status
// ---------------------------------------------------------------------------

const STATUS_TONE: Record<string, string> = {
  DRAFT: 'neutral',
  PENDING: 'warning',
  CONFIRMED: 'success',
  ARRIVED: 'info',
  IN_PROGRESS: 'info',
  COMPLETED: 'success',
  RESCHEDULED: 'purple',
  CANCELLED: 'danger',
  NO_SHOW: 'danger',
  AVAILABLE: 'neutral',
}

const STATUS_LABEL: Record<string, string> = {
  DRAFT: 'Draft',
  PENDING: 'Awaiting confirmation',
  CONFIRMED: 'Confirmed',
  ARRIVED: 'Arrived',
  IN_PROGRESS: 'In progress',
  COMPLETED: 'Completed',
  RESCHEDULED: 'Rescheduled',
  CANCELLED: 'Cancelled',
  NO_SHOW: 'No-show',
  AVAILABLE: 'Available',
}

export function StatusPill({ status, children }: { status: string; children?: ReactNode }) {
  return (
    <span className={`appt-status appt-status-${STATUS_TONE[status] ?? 'neutral'}`}>
      {children ?? STATUS_LABEL[status] ?? status}
    </span>
  )
}

export function statusLabel(status: string): string {
  return STATUS_LABEL[status] ?? status
}

// ---------------------------------------------------------------------------
// Bars
// ---------------------------------------------------------------------------

export function Bar({ value, tone }: { value: number; tone?: 'high' | 'full' | 'info' }) {
  const clamped = Math.max(0, Math.min(100, value))
  const resolved = tone ?? (clamped > 90 ? 'full' : clamped > 75 ? 'high' : undefined)

  return (
    <div className="appt-bar" data-tone={resolved} role="img" aria-label={`${Math.round(clamped)} per cent`}>
      <span style={{ width: `${clamped}%` }} />
    </div>
  )
}

// ---------------------------------------------------------------------------
// Insight card
// ---------------------------------------------------------------------------

/**
 * A suggestion, and what produced it.
 *
 * The `origin` badge is not decoration. A reader is entitled to know whether a
 * sentence was written by arithmetic or by a language model, and "Why this?"
 * shows the rule, the thresholds and the records — all of which came from this
 * product regardless of who wrote the prose.
 */
export function InsightBanner({
  insight,
  onAction,
  onExplain,
  onDismiss,
}: {
  insight: Insight
  onAction?: (insight: Insight) => void
  onExplain?: (insight: Insight) => void
  onDismiss?: (insight: Insight) => void
}) {
  return (
    <section className="appt-insight" aria-label="Suggested action">
      <span className="appt-insight-icon" aria-hidden>
        <Sparkles size={22} />
      </span>

      <div style={{ minWidth: 0 }}>
        <span className="appt-eyebrow">
          {insight.origin === 'ai' ? 'Aicountly Insight' : 'Rule-based insight'}
        </span>
        <strong>{insight.title}</strong>
        {insight.body && <p>{insight.body}</p>}
      </div>

      <div className="appt-row-tight">
        {onExplain && (
          <Button small tone="ghost" onClick={() => onExplain(insight)}>
            Why this?
          </Button>
        )}
        {insight.action && onAction && (
          <Button tone="primary" small onClick={() => onAction(insight)}>
            {insight.action.label}
            <ChevronRight size={14} aria-hidden />
          </Button>
        )}
        {onDismiss && (
          <Button small tone="ghost" onClick={() => onDismiss(insight)} title="Dismiss this suggestion">
            <X size={14} aria-hidden />
            <span className="appt-visually-hidden">Dismiss</span>
          </Button>
        )}
      </div>
    </section>
  )
}

/** What "Why this?" opens. Everything on it is checkable against the records. */
export function InsightExplanation({ insight }: { insight: Insight }) {
  return (
    <dl className="appt-definition-list">
      <div>
        <dt>What it says</dt>
        <dd>{insight.title}</dd>
      </div>
      {insight.body && (
        <div>
          <dt>Why</dt>
          <dd>{insight.body}</dd>
        </div>
      )}
      {insight.evidence.length > 0 && (
        <div>
          <dt>Records it came from</dt>
          <dd>
            <ul className="appt-evidence">
              {insight.evidence.map((item, index) => (
                <li key={`${item.kind}-${index}`}>
                  <strong>{item.label}</strong>
                  {item.note && <span> · {item.note}</span>}
                </li>
              ))}
            </ul>
          </dd>
        </div>
      )}
      {Object.keys(insight.rule_detail).length > 0 && (
        <div>
          <dt>The rule that fired</dt>
          <dd>
            {Object.entries(insight.rule_detail)
              .map(([key, value]) => `${key.replace(/_/g, ' ')}: ${String(value)}`)
              .join(' · ')}
          </dd>
        </div>
      )}
      <div>
        <dt>How it was produced</dt>
        <dd>
          {insight.origin === 'ai'
            ? 'The wording was rephrased by the AI provider configured for this deployment. The figures, the '
              + 'thresholds and the ranking are this product’s own arithmetic — a model never supplies a number.'
            : 'Entirely by rules in this product. Every threshold and count above can be checked against the '
              + 'records listed, and no language model was involved.'}
        </dd>
      </div>
      <div>
        <dt>What it will not do</dt>
        <dd>
          Nothing here books, cancels, charges or messages anybody. Each action opens the screen where a
          person decides.
        </dd>
      </div>
    </dl>
  )
}

// ---------------------------------------------------------------------------
// Drawer
// ---------------------------------------------------------------------------

/**
 * A side panel, not a wizard.
 *
 * Booking an appointment is one decision with five fields, and walking somebody
 * through five screens to collect them is how a thirty-second task becomes a
 * two-minute one. Everything in this product that would otherwise be a wizard
 * is a drawer.
 *
 * Escape closes it, focus moves into it on open and back to the trigger on
 * close, and the page behind it does not scroll — the three things a dialog
 * has to get right and the three most often skipped.
 */
export function Drawer({
  title,
  subtitle,
  onClose,
  footer,
  children,
  wide,
}: {
  title: string
  subtitle?: string
  onClose: () => void
  footer?: ReactNode
  children: ReactNode
  wide?: boolean
}) {
  const labelId = useId()
  const panelRef = useRef<HTMLDivElement>(null)
  const returnFocusTo = useRef<Element | null>(null)

  useEffect(() => {
    returnFocusTo.current = document.activeElement

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
    }

    document.addEventListener('keydown', onKeyDown)

    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    // Focus the panel itself rather than guessing at the first field: a drawer
    // that steals focus into a text input scrolls a long form on open.
    panelRef.current?.focus()

    return () => {
      document.removeEventListener('keydown', onKeyDown)
      document.body.style.overflow = previousOverflow
      if (returnFocusTo.current instanceof HTMLElement) returnFocusTo.current.focus()
    }
  }, [onClose])

  return createPortal(
    <>
      <button type="button" className="appt-drawer-scrim" aria-label="Close" onClick={onClose} />
      <div
        ref={panelRef}
        className="appt-drawer appt-ui"
        role="dialog"
        aria-modal="true"
        aria-labelledby={labelId}
        tabIndex={-1}
        style={wide ? { width: 'min(100%, 720px)', padding: 0 } : { padding: 0 }}
      >
        <header className="appt-drawer-head">
          <div>
            <h2 id={labelId}>{title}</h2>
            {subtitle && <p>{subtitle}</p>}
          </div>
          <Button tone="ghost" small onClick={onClose} aria-label="Close">
            <X size={16} aria-hidden />
          </Button>
        </header>

        <div className="appt-drawer-body">{children}</div>

        {footer && <footer className="appt-drawer-foot">{footer}</footer>}
      </div>
    </>,
    document.body,
  )
}

// ---------------------------------------------------------------------------
// Fields
// ---------------------------------------------------------------------------

export function Field({
  label,
  hint,
  error,
  children,
}: {
  label: string
  hint?: string
  error?: string
  children: ReactNode
}) {
  return (
    <label className="appt-field">
      <span>{label}</span>
      {children}
      {error ? (
        <span style={{ color: 'var(--danger)', fontSize: 12 }}>{error}</span>
      ) : hint ? (
        <span style={{ color: 'var(--muted)', fontSize: 12, fontWeight: 400 }}>{hint}</span>
      ) : null}
    </label>
  )
}

// ---------------------------------------------------------------------------
// Freshness footer
// ---------------------------------------------------------------------------

/**
 * Who owns each number on the screen.
 *
 * Not decoration: a reader who can see that availability came from Calendar and
 * deposits came from Pay can tell which figure to distrust when one of those is
 * having a bad day.
 */
export function FreshnessFooter({
  freshness,
  onRefresh,
  paused,
}: {
  freshness?: Freshness
  onRefresh: () => void
  paused?: boolean
}) {
  return (
    <footer className="appt-freshness">
      <ul>
        {(freshness?.sources ?? []).map((source) => (
          <li key={source.what}>
            {source.what}: <strong>{source.owner}</strong>
          </li>
        ))}
      </ul>
      <div className="appt-row-tight">
        <span>
          {paused ? 'Paused while this tab is in the background' : `Updated ${timeAgo(freshness?.generated_at)}`}
        </span>
        <Button tone="ghost" small onClick={onRefresh} title="Reload">
          Refresh
        </Button>
      </div>
    </footer>
  )
}

export function timeAgo(iso: string | undefined): string {
  if (!iso) return '—'

  const then = new Date(iso).getTime()
  if (Number.isNaN(then)) return '—'

  const seconds = Math.max(0, Math.round((Date.now() - then) / 1000))
  if (seconds < 45) return 'just now'
  if (seconds < 90) return 'a minute ago'
  if (seconds < 3600) return `${Math.round(seconds / 60)} minutes ago`
  if (seconds < 7200) return 'an hour ago'
  if (seconds < 86400) return `${Math.round(seconds / 3600)} hours ago`
  return `${Math.round(seconds / 86400)} days ago`
}

/** A time in the company's own zone. Never the browser's, which may be elsewhere. */
export function formatTime(iso: string | null | undefined, timezone: string): string {
  if (!iso) return '—'
  try {
    return new Intl.DateTimeFormat('en-IN', {
      hour: 'numeric',
      minute: '2-digit',
      hour12: true,
      timeZone: timezone,
    }).format(new Date(iso))
  } catch {
    return '—'
  }
}

export function formatDate(iso: string | null | undefined, timezone: string): string {
  if (!iso) return '—'
  try {
    return new Intl.DateTimeFormat('en-IN', {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      timeZone: timezone,
    }).format(new Date(iso))
  } catch {
    return '—'
  }
}

export function formatDateTime(iso: string | null | undefined, timezone: string): string {
  if (!iso) return '—'
  return `${formatDate(iso, timezone)}, ${formatTime(iso, timezone)}`
}
