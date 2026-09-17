/**
 * What Appointments is connected to, and honestly.
 *
 * NOTHING SAYS "CONNECTED" UNLESS IT ANSWERED. The backend asks each integration
 * before reporting on it; this screen renders what it said. A configured
 * service that is unreachable shows as an error, one whose flag is off shows
 * what an administrator needs to set, and one whose product is still being
 * built says so.
 *
 * Each card also lists what that product OWNS, which makes this screen double
 * as the data-ownership map — the thing somebody actually reads when they are
 * wondering why a number is not here.
 */

import { AlertTriangle, CheckCircle2, Circle, Clock, XCircle } from 'lucide-react'
import { api } from '../services/api'
import { useApi } from '../hooks/useApi'
import { Notice, PanelState } from '../ui'
import type { Integration } from '../services/types'

const STATUS_STYLE: Record<
  Integration['status'],
  { tone: string; icon: React.ReactNode; label: string }
> = {
  connected: { tone: 'success', icon: <CheckCircle2 size={14} />, label: 'Connected' },
  available: { tone: 'info', icon: <Circle size={14} />, label: 'Available' },
  setup_required: { tone: 'warning', icon: <AlertTriangle size={14} />, label: 'Setup required' },
  coming_soon: { tone: 'purple', icon: <Clock size={14} />, label: 'Coming soon' },
  unavailable: { tone: 'neutral', icon: <Circle size={14} />, label: 'Unavailable' },
  error: { tone: 'danger', icon: <XCircle size={14} />, label: 'Error' },
}

export function IntegrationsPage() {
  const list = useApi(
    (signal) =>
      api.get<{ data: { integrations: Integration[] } }>('v1/integrations', undefined, signal),
    [],
  )

  return (
    <div className="appt-ui">
      <header className="appt-page-header">
        <div>
          <h1>Integrations</h1>
          <p>What Appointments reads from, writes to, and what each of them owns.</p>
        </div>
      </header>

      <PanelState
        loading={list.loading}
        error={list.error}
        data={list.data}
        isEmpty={(data) => data.data.integrations.length === 0}
        emptyTitle="No integrations configured"
        onRetry={list.reload}
        skeletonRows={5}
      >
        {(data) => {
          const required = data.data.integrations.filter((integration) => integration.required)
          const optional = data.data.integrations.filter((integration) => !integration.required)

          return (
            <>
              {required.some((integration) => integration.status !== 'connected') && (
                <Notice tone="danger" title="Appointments cannot take bookings right now">
                  Aicountly Calendar owns every calendar and event in the fleet. Without it, availability
                  cannot be computed and a booking cannot be written, because this product deliberately keeps
                  no copy of anybody's diary.
                </Notice>
              )}

              <section style={{ marginBottom: 20 }}>
                <p className="appt-eyebrow">Required</p>
                <div className="appt-grid appt-grid-2">
                  {required.map((integration) => (
                    <IntegrationCard key={integration.key} integration={integration} />
                  ))}
                </div>
              </section>

              <section>
                <p className="appt-eyebrow">Everything else</p>
                <div className="appt-grid appt-grid-2">
                  {optional.map((integration) => (
                    <IntegrationCard key={integration.key} integration={integration} />
                  ))}
                </div>
              </section>

              <p style={{ color: 'var(--muted)', fontSize: 12.5, marginTop: 18, lineHeight: 1.7, maxWidth: '80ch' }}>
                AICOUNTLY products do not share databases. Everything above is read and written through that
                product's live API, on the request that needs it. Appointments stores a reference — a calendar
                event id, a payment request id — and never a copy of what the other product owns. That is why
                a panel here can say "not reported" instead of showing a number: the number is genuinely
                somewhere else.
              </p>
            </>
          )
        }}
      </PanelState>
    </div>
  )
}

function IntegrationCard({ integration }: { integration: Integration }) {
  const style = STATUS_STYLE[integration.status]

  return (
    <article className="appt-panel" style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
      <header className="appt-row" style={{ justifyContent: 'space-between', alignItems: 'start' }}>
        <div style={{ minWidth: 0 }}>
          <h3 style={{ margin: 0, fontSize: 15.5, fontWeight: 700 }}>{integration.name}</h3>
          <p style={{ margin: '4px 0 0', color: 'var(--muted)', fontSize: 13, lineHeight: 1.55 }}>
            {integration.summary}
          </p>
        </div>
        <span className={`appt-status appt-status-${style.tone}`} style={{ flex: 'none' }}>
          {style.icon}
          {style.label}
        </span>
      </header>

      {integration.detail && (
        <p style={{ margin: 0, fontSize: 12.5, color: 'var(--muted)', lineHeight: 1.6 }}>{integration.detail}</p>
      )}

      {integration.owns.length > 0 && (
        <div>
          <p style={{ margin: '0 0 6px', fontSize: 11.5, fontWeight: 700, color: 'var(--muted)', textTransform: 'uppercase' }}>
            {integration.name.replace(/^Aicountly /, '')} owns
          </p>
          <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'flex', flexWrap: 'wrap', gap: 6 }}>
            {integration.owns.map((item) => (
              <li
                key={item}
                style={{
                  fontSize: 11.5,
                  padding: '3px 9px',
                  borderRadius: 999,
                  background: 'var(--surface-soft)',
                  border: '1px solid var(--line)',
                  color: 'var(--muted)',
                }}
              >
                {item}
              </li>
            ))}
          </ul>
        </div>
      )}

      {integration.admin_hint && (
        <div
          style={{
            padding: '10px 12px',
            borderRadius: 10,
            background: 'var(--surface-soft)',
            border: '1px dashed var(--line-strong)',
            fontSize: 12,
            color: 'var(--muted)',
            lineHeight: 1.55,
          }}
        >
          <strong style={{ display: 'block', color: 'var(--ink)', marginBottom: 2 }}>To enable this</strong>
          {integration.admin_hint}
        </div>
      )}
    </article>
  )
}
