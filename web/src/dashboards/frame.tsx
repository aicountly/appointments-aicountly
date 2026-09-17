/**
 * What all five dashboards share: the tab strip, the period filter, the KPI
 * row, the insight banner, the drilldown resolver and the freshness footer.
 *
 * THE TAB AND THE FILTERS LIVE IN THE URL. A dashboard somebody cannot send to
 * a colleague is a dashboard they screenshot instead, and a screenshot of a
 * figure is how a stale number outlives the thing it described.
 *
 * ONLY THE ACTIVE VIEW LOADS. Each tab is its own endpoint and its own query;
 * switching tabs cancels the one you left. Five dashboards that all load at
 * once is five times the work for one screen of it.
 */

import { useCallback, useState, type ReactNode } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { api, ApiError } from '../services/api'
import { usePolledApi } from '../hooks/useApi'
import { useAppointments } from '../context/AppointmentsContext'
import { DASHBOARDS, resolveRoute, type DashboardId } from '../shell/navConfig'
import {
  Button,
  Drawer,
  FreshnessFooter,
  InsightBanner,
  InsightExplanation,
  MetricRow,
  Notice,
} from '../ui'
import type { DashboardResponse, Insight, Metric } from '../services/types'

// ---------------------------------------------------------------------------
// Loading a dashboard
// ---------------------------------------------------------------------------

export function useDashboard<TPanels>(
  view: DashboardId,
  options?: { pollSeconds?: number },
): {
  data: DashboardResponse<TPanels> | null
  loading: boolean
  error: ApiError | Error | null
  reload: () => void
  paused: boolean
  period: string
  setPeriod: (preset: string) => void
} {
  const [params, setParams] = useSearchParams()
  const config = DASHBOARDS.find((entry) => entry.id === view)
  const period = params.get('period') ?? ''

  const setPeriod = useCallback(
    (preset: string) => {
      const next = new URLSearchParams(params)
      if (preset === '') next.delete('period')
      else next.set('period', preset)
      setParams(next, { replace: true })
    },
    [params, setParams],
  )

  const fetcher = useCallback(
    (signal: AbortSignal) =>
      api.get<{ data: DashboardResponse<TPanels> }>(
        `v1/dashboards/${config?.api ?? view}`,
        period ? { period } : undefined,
        signal,
      ),
    [config?.api, view, period],
  )

  const polled = usePolledApi(fetcher, [view, period], options?.pollSeconds ?? 0, true)

  return {
    data: polled.data?.data ?? null,
    loading: polled.loading,
    error: polled.error,
    reload: polled.reload,
    paused: polled.paused,
    period,
    setPeriod,
  }
}

// ---------------------------------------------------------------------------
// Tabs
// ---------------------------------------------------------------------------

export function DashboardTabs({ current }: { current: DashboardId }) {
  const [params] = useSearchParams()
  const query = params.toString()

  return (
    <nav className="appt-dashboard-nav" aria-label="Appointment dashboards">
      {DASHBOARDS.map((view) => (
        <Link
          key={view.id}
          to={query ? `${view.path}?${query}` : view.path}
          aria-current={view.id === current ? 'page' : undefined}
        >
          {view.label}
        </Link>
      ))}
    </nav>
  )
}

// ---------------------------------------------------------------------------
// Period
// ---------------------------------------------------------------------------

const PERIODS = [
  { value: 'today', label: 'Today' },
  { value: '7d', label: 'Last 7 days' },
  { value: '30d', label: 'Last 30 days' },
  { value: 'last_4_weeks', label: 'Last 4 weeks' },
  { value: 'last_7_weeks', label: 'Last 7 weeks' },
  { value: 'quarter', label: 'Last quarter' },
  { value: 'year', label: 'Last 12 months' },
]

export function PeriodFilter({
  value,
  onChange,
  label,
}: {
  value: string
  onChange: (preset: string) => void
  label?: string
}) {
  return (
    <label className="appt-row-tight" style={{ color: 'var(--muted)', fontSize: 12 }}>
      <span className="appt-visually-hidden">Period</span>
      <select className="appt-select" style={{ minWidth: 168 }} value={value} onChange={(event) => onChange(event.target.value)}>
        <option value="">{label ?? 'Default period'}</option>
        {PERIODS.map((period) => (
          <option key={period.value} value={period.value}>
            {period.label}
          </option>
        ))}
      </select>
    </label>
  )
}

// ---------------------------------------------------------------------------
// Drilldowns
// ---------------------------------------------------------------------------

/**
 * Resolve a drilldown against this app's OWN route table.
 *
 * The server sends a route NAME and a set of filters. It never sends a URL, so
 * nothing arriving from an API — or from anything that influenced one — can
 * point a browser at an address this application did not choose.
 */
export function useDrilldown(): (metric: Metric) => void {
  const navigate = useNavigate()

  return useCallback(
    (metric: Metric) => {
      if (!metric.drilldown) return
      const path = resolveRoute(metric.drilldown.route, metric.drilldown.params)
      if (path) navigate(path)
    },
    [navigate],
  )
}

export function useInsightAction(): (insight: Insight) => void {
  const navigate = useNavigate()

  return useCallback(
    (insight: Insight) => {
      if (!insight.action) return
      const path = resolveRoute(insight.action.route, insight.action.params)
      if (path) navigate(path)
    },
    [navigate],
  )
}

// ---------------------------------------------------------------------------
// Insights
// ---------------------------------------------------------------------------

export function Insights({ insights, onDismissed }: { insights: Insight[]; onDismissed: () => void }) {
  const act = useInsightAction()
  const [explaining, setExplaining] = useState<Insight | null>(null)

  if (insights.length === 0) return null

  async function dismiss(insight: Insight): Promise<void> {
    try {
      await api.del(`v1/insights/${insight.insight_uuid}`)
    } catch {
      // A dismissal that did not save is not worth an error banner over a
      // suggestion — the card comes back on the next load, which is a
      // recoverable annoyance rather than a failure.
    }
    onDismissed()
  }

  return (
    <>
      {insights.map((insight) => (
        <InsightBanner
          key={insight.insight_uuid}
          insight={insight}
          onAction={insight.action ? act : undefined}
          onExplain={setExplaining}
          onDismiss={dismiss}
        />
      ))}

      {explaining !== null && (
        <Drawer
          title="Why this suggestion"
          subtitle={explaining.origin === 'ai' ? 'Wording by the configured AI provider' : 'Produced entirely by rules'}
          onClose={() => setExplaining(null)}
        >
          <InsightExplanation insight={explaining} />
        </Drawer>
      )}
    </>
  )
}

// ---------------------------------------------------------------------------
// The frame
// ---------------------------------------------------------------------------

export function DashboardFrame<TPanels>({
  view,
  state,
  filters,
  primaryAction,
  showPeriod = true,
  periodLabel,
  metricIcons,
  children,
}: {
  view: DashboardId
  state: ReturnType<typeof useDashboard<TPanels>>
  filters?: ReactNode
  primaryAction?: ReactNode
  showPeriod?: boolean
  periodLabel?: string
  metricIcons?: Record<string, ReactNode>
  children: (data: DashboardResponse<TPanels>) => ReactNode
}) {
  const config = DASHBOARDS.find((entry) => entry.id === view)
  const { currency } = useAppointments()
  const drilldown = useDrilldown()
  const { data, loading, error, reload, paused, period, setPeriod } = state

  return (
    <div className="appt-ui">
      <DashboardTabs current={view} />

      <header className="appt-page-header">
        <div>
          <h1>{config?.title}</h1>
          <p>{config?.subtitle}</p>
        </div>
        <div className="appt-actions">
          {data && (
            <span style={{ color: 'var(--muted)', fontSize: 12.5, alignSelf: 'center' }}>{data.period.label}</span>
          )}
          {showPeriod && <PeriodFilter value={period} onChange={setPeriod} label={periodLabel} />}
          {primaryAction}
        </div>
      </header>

      {filters && (
        <div className="appt-filters" role="group" aria-label="Dashboard filters">
          {filters}
        </div>
      )}

      {error !== null && (
        <Notice
          tone="danger"
          title="This dashboard could not be loaded"
          action={
            <Button small onClick={reload}>
              Retry
            </Button>
          }
        >
          {error.message}
        </Notice>
      )}

      <MetricRow
        metrics={data?.metrics}
        currency={currency}
        loading={loading && data === null}
        icons={metricIcons}
        onOpen={drilldown}
      />

      {data && <Insights insights={data.insights} onDismissed={reload} />}

      {loading && data === null ? (
        <div className="appt-skeleton" style={{ height: 380, borderRadius: 16 }} aria-label="Loading" />
      ) : data ? (
        children(data)
      ) : null}

      <FreshnessFooter freshness={data?.freshness} onRefresh={reload} paused={paused} />
    </div>
  )
}
