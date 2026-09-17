/**
 * DASHBOARD 4 — Client Experience & Engagement.
 *
 * The funnel screen. Whether the people who wanted an appointment got through,
 * and whether they came back.
 *
 * AN UNMEASURED FUNNEL STAGE IS HATCHED, NOT ZERO. The top two stages are
 * browser-side events; where nothing reports them the bar says so rather than
 * being back-filled from the stage below it. A funnel whose first bar silently
 * equals its second is a funnel with no top.
 *
 * NO-SHOW INDICATORS SAY "PRESENT IN", NOT "CAUSES". The caveat is server-side
 * copy, rendered verbatim, because it is the kind of sentence that gets
 * softened in a redesign.
 */

import {
  CalendarCheck,
  CalendarX,
  MessageSquare,
  RefreshCw,
  UserCheck,
  UserPlus,
  Users,
} from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { DashboardFrame, useDashboard } from './frame'
import { useAppointments } from '../context/AppointmentsContext'
import { Bar, Button, EmptyState, Notice, Panel } from '../ui'
import { Sparkline } from '../ui/charts'
import type { ClientExperiencePanels, FunnelStage } from '../services/types'

const METRIC_ICONS = {
  new_clients: <UserPlus size={16} />,
  returning_clients: <Users size={16} />,
  confirmation_rate: <CalendarCheck size={16} />,
  attendance_rate: <UserCheck size={16} />,
  rebook_rate: <RefreshCw size={16} />,
  no_show_rate: <CalendarX size={16} />,
}

const CHANNEL_TONE: Record<string, 'success' | 'info' | 'warning' | 'purple'> = {
  whatsapp: 'success',
  sms: 'info',
  email: 'purple',
  voice: 'warning',
}

export function ClientExperienceDashboard() {
  const navigate = useNavigate()
  const { timezone } = useAppointments()
  const state = useDashboard<ClientExperiencePanels>('client-experience')

  return (
    <DashboardFrame view="client-experience" state={state} periodLabel="Last 30 days" metricIcons={METRIC_ICONS}>
      {(data) => (
        <>
          <div className="appt-split">
            <Panel
              title="Appointment journey funnel"
              subtitle="From first interest to a long-term client."
              info="Measured by the date each appointment fell on, so attendance and rebooking share one denominator."
            >
              <div className="appt-funnel">
                {data.panels.funnel.stages.map((stage) => (
                  <FunnelRow key={stage.key} stage={stage} />
                ))}
              </div>
            </Panel>

            <Panel
              title="Reminder performance"
              subtitle="Delivery rates and client actions after reminders."
              info={data.panels.reminders.attribution_note}
            >
              {!data.panels.reminders.configured && (
                <Notice tone="warning" title="No messaging provider is connected">
                  {data.panels.reminders.unavailable_reason} Reminders are still computed and scheduled by
                  Appointments — they are counted below as not sent, never as delivered.
                </Notice>
              )}

              {data.panels.reminders.channels.length === 0 ? (
                <EmptyState title="No reminders in this period">
                  Reminder rules are set under Reminders. Once appointments are booked, each one is scheduled
                  here.
                </EmptyState>
              ) : (
                <div className="appt-stack" style={{ gap: 14 }}>
                  {data.panels.reminders.channels.map((channel) => (
                    <div key={channel.channel}>
                      <div className="appt-row" style={{ justifyContent: 'space-between', marginBottom: 6 }}>
                        <span className={`appt-status appt-status-${CHANNEL_TONE[channel.channel] ?? 'neutral'}`}>
                          {channel.label}
                        </span>
                        <span className="num" style={{ fontWeight: 650 }}>
                          {channel.delivered_rate}%
                        </span>
                      </div>
                      <Bar value={channel.delivered_rate} tone="info" />
                      <small style={{ color: 'var(--muted)' }}>
                        {channel.delivered} delivered of {channel.total}
                        {channel.not_sent > 0 && ` · ${channel.not_sent} not sent`}
                        {channel.failed > 0 && ` · ${channel.failed} failed`}
                      </small>
                    </div>
                  ))}

                  {data.panels.reminders.outcomes.length > 0 && (
                    <div style={{ borderTop: '1px solid var(--line)', paddingTop: 12 }}>
                      <p style={{ margin: '0 0 8px', fontSize: 12, fontWeight: 650, color: 'var(--muted)' }}>
                        What clients did afterwards
                      </p>
                      {data.panels.reminders.outcomes.map((outcome) => (
                        <div
                          key={outcome.outcome}
                          className="appt-row"
                          style={{ justifyContent: 'space-between', padding: '6px 0' }}
                        >
                          <span>{outcome.label}</span>
                          <span className="num" style={{ color: 'var(--muted)' }}>
                            {outcome.total} · {outcome.share}%
                          </span>
                        </div>
                      ))}
                      <small style={{ color: 'var(--muted)' }}>{data.panels.reminders.attribution_note}</small>
                    </div>
                  )}
                </div>
              )}
            </Panel>
          </div>

          <div className="appt-split">
            <Panel
              title="No-show intelligence"
              subtitle="Spot risks early. Take action. Keep chairs full."
              action={
                <Button small onClick={() => navigate('/appointments?risk=1')}>
                  View at-risk
                </Button>
              }
            >
              <div className="appt-grid appt-grid-2" style={{ marginBottom: 0 }}>
                <div>
                  <p style={{ margin: '0 0 4px', fontSize: 12, fontWeight: 650, color: 'var(--muted)' }}>
                    Current no-show rate
                  </p>
                  <div style={{ fontSize: 34, fontWeight: 700, letterSpacing: '-0.04em' }}>
                    {data.panels.no_show.rate}%
                  </div>
                  <small style={{ color: 'var(--muted)' }}>
                    {data.panels.no_show.no_shows} missed appointment
                    {data.panels.no_show.no_shows === 1 ? '' : 's'} in this period
                  </small>

                  <div style={{ marginTop: 14 }}>
                    <Sparkline
                      values={data.panels.no_show.trend.map((point) => point.rate)}
                      label={`No-show rate over ${data.panels.no_show.trend.length} weeks`}
                      colour={data.panels.no_show.rate > data.panels.no_show.previous ? '#b42318' : '#25b003'}
                    />
                  </div>
                </div>

                <div>
                  <p style={{ margin: '0 0 8px', fontSize: 12, fontWeight: 650, color: 'var(--muted)' }}>
                    Top risk factors
                  </p>

                  {data.panels.no_show.factors.length === 0 ? (
                    <p style={{ color: 'var(--muted)', fontSize: 13, margin: 0 }}>
                      Too few missed appointments in this period to say anything useful. This panel stays quiet
                      below five rather than reporting shares of a handful of rows.
                    </p>
                  ) : (
                    <>
                      <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: 8 }}>
                        {data.panels.no_show.factors.map((factor) => (
                          <li key={factor.key}>
                            <div className="appt-row" style={{ justifyContent: 'space-between', marginBottom: 4 }}>
                              <span style={{ fontSize: 13 }}>{factor.label}</span>
                              <span className="num" style={{ fontSize: 12.5, fontWeight: 650 }}>
                                {factor.share}%
                              </span>
                            </div>
                            <Bar value={factor.share} tone="full" />
                          </li>
                        ))}
                      </ul>

                      {/* Server-side copy, verbatim. It is the kind of sentence
                          that quietly disappears in a redesign. */}
                      <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 12, lineHeight: 1.5 }}>
                        {data.panels.no_show.caveat}
                      </p>
                    </>
                  )}
                </div>
              </div>
            </Panel>

            <Panel title="Today's preparation" subtitle="Clients with appointments today.">
              {data.panels.preparation.total === 0 ? (
                <EmptyState title="Nothing booked today" />
              ) : (
                <div className="appt-grid appt-grid-2" style={{ marginBottom: 0, gap: 10 }}>
                  {data.panels.preparation.items.map((item) => (
                    <div
                      key={item.key}
                      style={{
                        padding: '14px 16px',
                        borderRadius: 12,
                        border: '1px solid var(--line)',
                        background:
                          item.tone === 'danger'
                            ? 'var(--danger-soft)'
                            : item.tone === 'warning'
                              ? 'var(--warning-soft)'
                              : 'var(--soft)',
                      }}
                    >
                      <div className="num" style={{ fontSize: 24, fontWeight: 700, textAlign: 'left' }}>
                        {item.count}
                      </div>
                      <div style={{ fontSize: 12.5, fontWeight: 650 }}>{item.label}</div>
                      <small style={{ color: 'var(--muted)' }}>{item.share}% of today</small>
                    </div>
                  ))}
                </div>
              )}
            </Panel>
          </div>

          <Panel
            title="Client appointment profiles"
            subtitle="What Appointments knows about your clients."
            info="Identity — names, numbers, addresses — belongs to Aicountly Contacts. These are the appointment-shaped facts."
            action={
              <Button small onClick={() => navigate('/clients')}>
                All clients
              </Button>
            }
          >
            {data.panels.top_clients.length === 0 ? (
              <EmptyState title="No clients yet">
                Clients appear here once they have booked an appointment.
              </EmptyState>
            ) : (
              <div className="appt-table-wrap">
                <table className="appt-table">
                  <thead>
                    <tr>
                      <th>Client</th>
                      <th>Usual service</th>
                      <th>Preferred slot</th>
                      <th className="num">Appointments</th>
                      <th className="num">No-shows</th>
                      <th>Last seen</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.panels.top_clients.map((client) => (
                      <tr key={client.contact_uuid}>
                        <td>
                          <button
                            type="button"
                            onClick={() => navigate(`/clients/${client.contact_uuid}`)}
                            style={{ border: 0, background: 'transparent', padding: 0, cursor: 'pointer', textAlign: 'left' }}
                          >
                            <strong>{client.label}</strong>
                            <div style={{ color: 'var(--muted)', fontSize: 11.5 }}>
                              {client.segment === 'returning' ? 'Returning client' : 'New client'}
                            </div>
                          </button>
                        </td>
                        <td>{client.usual_service ?? '—'}</td>
                        <td style={{ color: 'var(--muted)' }}>{client.preferred_slot}</td>
                        <td className="num">{client.appointments}</td>
                        <td className="num" style={client.no_shows > 0 ? { color: 'var(--danger)' } : undefined}>
                          {client.no_shows}
                        </td>
                        <td style={{ color: 'var(--muted)' }}>
                          {client.last_appointment
                            ? new Intl.DateTimeFormat('en-IN', {
                                day: 'numeric',
                                month: 'short',
                                timeZone: timezone,
                              }).format(new Date(client.last_appointment))
                            : '—'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Panel>
        </>
      )}
    </DashboardFrame>
  )
}

function FunnelRow({ stage }: { stage: FunnelStage }) {
  return (
    <div className="appt-funnel-row" data-measured={stage.measured ? 'true' : 'false'}>
      <span className="appt-metric-icon" aria-hidden style={{ margin: 0, width: 30, height: 30 }}>
        <MessageSquare size={14} />
      </span>

      <div style={{ minWidth: 0 }}>
        <div className="appt-row" style={{ justifyContent: 'space-between', marginBottom: 4 }}>
          <span style={{ fontWeight: 650, fontSize: 13 }}>{stage.label}</span>
          <span className="num" style={{ fontSize: 12.5, color: 'var(--muted)' }}>
            {stage.measured ? `${stage.count} · ${stage.share ?? 0}%` : 'Not measured'}
          </span>
        </div>
        <div className="appt-funnel-track">
          {stage.measured && <span style={{ width: `${stage.share ?? 0}%` }} />}
        </div>
        <small style={{ color: 'var(--muted)' }}>
          {stage.measured ? stage.detail : stage.unmeasured_reason}
        </small>
      </div>

      <span />
    </div>
  )
}
