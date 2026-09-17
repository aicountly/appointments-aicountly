/**
 * DASHBOARD 3 — Availability & Capacity Intelligence.
 *
 * The owner's Monday-morning screen. Hours open against hours booked, a heatmap
 * of where demand actually falls, and the slots worth doing something about.
 *
 * THE HEATMAP DISTINGUISHES CLOSED FROM QUIET. A band nobody works is hatched,
 * not coloured pale green — a cell that reads "0% busy" on a day the clinic is
 * shut invites somebody to try to fill it.
 *
 * CALENDAR'S TILES SAY WHEN THEY CANNOT ANSWER. "0 conflicts · all clear" from
 * a calendar nobody could read is the one lie that would matter on this screen.
 */

import { useNavigate } from 'react-router-dom'
import { CalendarCheck, CalendarClock, CalendarX, Gauge, Link2, Timer, TrendingUp, Zap } from 'lucide-react'
import { DashboardFrame, useDashboard } from './frame'
import { useAppointments } from '../context/AppointmentsContext'
import { Bar, Button, EmptyState, Notice, Panel } from '../ui'
import { UtilisationMeter } from '../ui/charts'
import type { CapacityPanels } from '../services/types'

const METRIC_ICONS = {
  available_hours: <CalendarClock size={16} />,
  booked_hours: <CalendarCheck size={16} />,
  utilisation: <Gauge size={16} />,
  unused_capacity: <CalendarX size={16} />,
  peak_load: <TrendingUp size={16} />,
  next_open_slot: <Timer size={16} />,
  calendar_conflicts: <CalendarX size={16} />,
  external_calendars: <Link2 size={16} />,
}

const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

const LEGEND_COLOURS: Record<string, string> = {
  low: '#e9f7e4',
  moderate: '#c9edbf',
  high: '#fde68a',
  very_high: '#fdba74',
  full: '#fca5a5',
}

export function CapacityDashboard() {
  const navigate = useNavigate()
  const { can } = useAppointments()
  const state = useDashboard<CapacityPanels>('capacity')

  return (
    <DashboardFrame view="capacity" state={state} periodLabel="Last 4 weeks" metricIcons={METRIC_ICONS}>
      {(data) => (
        <>
          {!data.panels.calendar_integration.available && (
            <Notice tone="warning" title="Aicountly Calendar could not be reached">
              {data.panels.calendar_integration.message} Booked hours and utilisation below are this product's
              own figures and are accurate. Conflicts, external calendars and the next open slot come from
              Calendar and are not shown rather than guessed at.
            </Notice>
          )}

          <div className="appt-split">
            <Panel
              title="Capacity heatmap"
              subtitle="Where demand and availability fall across your team and time slots."
              info="Booked minutes as a share of that band's capacity — three bookings in a band with one practitioner is full; three in a band with six is quiet."
            >
              <div className="appt-heatmap">
                <div className="appt-heatmap-row">
                  <span />
                  {DAYS.map((day) => (
                    <span key={day} className="appt-heatmap-label" style={{ textAlign: 'center' }}>
                      {day}
                    </span>
                  ))}
                </div>

                {data.panels.heatmap.bands.map((band) => (
                  <div key={band.label} className="appt-heatmap-row">
                    <span className="appt-heatmap-label">{band.label}</span>
                    {band.cells.map((cell) => (
                      <span
                        key={`${band.label}-${cell.day_of_week}`}
                        className="appt-heatmap-cell"
                        data-intensity={cell.intensity ?? undefined}
                        data-closed={cell.closed ? 'true' : undefined}
                        title={
                          cell.closed
                            ? `${DAYS[cell.day_of_week]} ${band.label}: closed`
                            : `${DAYS[cell.day_of_week]} ${band.label}: ${cell.load ?? 0}% booked`
                        }
                      />
                    ))}
                  </div>
                ))}
              </div>

              <div className="appt-legend">
                {data.panels.heatmap.legend.map((entry) => (
                  <span key={entry.key}>
                    <i style={{ background: LEGEND_COLOURS[entry.key] }} />
                    {entry.label}
                  </span>
                ))}
                <span>
                  <i
                    style={{
                      background:
                        'repeating-linear-gradient(45deg, #f7f9f8, #f7f9f8 3px, #eef2ef 3px, #eef2ef 6px)',
                      border: '1px solid var(--line)',
                    }}
                  />
                  Closed
                </span>
              </div>
            </Panel>

            <Panel title="Team capacity" subtitle="Live utilisation across your team.">
              {data.panels.team.length === 0 ? (
                <EmptyState title="Nobody on the team yet">
                  Add practitioners under Team &amp; Availability.
                </EmptyState>
              ) : (
                <div>
                  {data.panels.team.map((member) => (
                    <UtilisationMeter
                      key={member.member_uuid}
                      value={member.utilisation}
                      label={member.label}
                      detail={`${member.booked_hours} / ${member.available_hours} hrs`}
                    />
                  ))}
                </div>
              )}
            </Panel>
          </div>

          <div className="appt-grid appt-grid-3">
            <Panel title="Most requested services" subtitle="What clients are booking.">
              {data.panels.services.length === 0 ? (
                <EmptyState title="No bookings in this period" />
              ) : (
                <ol style={{ listStyle: 'none', margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: 12 }}>
                  {data.panels.services.map((service, index) => (
                    <li key={service.service_uuid}>
                      <div className="appt-row" style={{ justifyContent: 'space-between', marginBottom: 6 }}>
                        <span style={{ minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                          <span style={{ color: 'var(--muted)', marginRight: 6 }}>{index + 1}</span>
                          <strong style={{ fontWeight: 650 }}>{service.name}</strong>
                        </span>
                        <span className="num" style={{ fontWeight: 650 }}>
                          {service.share}%
                        </span>
                      </div>
                      <Bar value={service.share} tone="info" />
                      <small style={{ color: 'var(--muted)' }}>{service.bookings} bookings</small>
                    </li>
                  ))}
                </ol>
              )}
            </Panel>

            <Panel
              title="Smart slot opportunities"
              subtitle="Isolated gaps worth filling, and who would take them."
              info="An hour free with appointments either side. A whole free afternoon is a quiet afternoon, not an opportunity."
            >
              {!data.panels.opportunities.available ? (
                <EmptyState title="Availability could not be read">
                  {data.panels.opportunities.message}
                </EmptyState>
              ) : data.panels.opportunities.items.length === 0 ? (
                <EmptyState title="No isolated gaps this week">
                  Every free stretch is part of a quiet period rather than a gap between bookings.
                </EmptyState>
              ) : (
                <div className="appt-stack" style={{ gap: 10 }}>
                  {data.panels.opportunities.items.map((item) => (
                    <div
                      key={`${item.starts_at}-${item.service_uuid}`}
                      className="appt-row"
                      style={{
                        justifyContent: 'space-between',
                        padding: '10px 12px',
                        border: '1px solid var(--line)',
                        borderRadius: 11,
                        background: 'var(--surface-soft)',
                      }}
                    >
                      <span style={{ minWidth: 0 }}>
                        <strong style={{ display: 'block', fontWeight: 650 }}>{item.local_label}</strong>
                        <small style={{ color: 'var(--muted)' }}>
                          {item.service_name}
                          {item.member_label ? ` · ${item.member_label}` : ''} · {item.reason}
                        </small>
                      </span>
                      {can('appointments.waitlist.manage') && item.waitlist_matches.length > 0 && (
                        <Button small onClick={() => navigate('/waitlist')}>
                          Offer slot
                        </Button>
                      )}
                    </div>
                  ))}
                </div>
              )}
            </Panel>

            <Panel title="Location comparison" subtitle="Capacity and utilisation by location.">
              {data.panels.locations.locations.length === 0 ? (
                <EmptyState title="No bookings by location yet" />
              ) : (
                <>
                  {!data.panels.locations.names_available && (
                    <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 0 }}>
                      Aicountly Manage did not answer, so locations are shown by id.
                    </p>
                  )}
                  <div>
                    {data.panels.locations.locations.map((location) => (
                      <UtilisationMeter
                        key={location.bo_id}
                        value={location.utilisation}
                        label={location.label}
                        detail={`${location.booked_hours} / ${location.available_hours} hrs`}
                      />
                    ))}
                  </div>
                </>
              )}
            </Panel>
          </div>

          <Panel
            title="Calendar integration status"
            subtitle="Aicountly Calendar owns every calendar and event. This is what it reports."
            action={
              can('appointments.integrations.manage') ? (
                <Button small onClick={() => navigate('/integrations')}>
                  Integrations
                </Button>
              ) : undefined
            }
          >
            <div className="appt-grid appt-grid-2" style={{ marginBottom: 0 }}>
              <IntegrationRow
                icon={<Zap size={15} />}
                label="Aicountly Calendar"
                value={data.panels.calendar_integration.available ? 'Connected' : 'Unavailable'}
                tone={data.panels.calendar_integration.available ? 'success' : 'danger'}
                detail={
                  data.panels.calendar_integration.available
                    ? 'Availability and events are read live on every request.'
                    : (data.panels.calendar_integration.message ?? 'Not answering.')
                }
              />
              <IntegrationRow
                icon={<Link2 size={15} />}
                label="External calendars"
                value={
                  data.panels.calendar_integration.external_connected === null
                    ? 'Not reported'
                    : `${data.panels.calendar_integration.external_connected} connected`
                }
                tone={data.panels.calendar_integration.external_connected === null ? 'neutral' : 'success'}
                detail={
                  data.panels.calendar_integration.external_connected === null
                    ? 'Google, Outlook and Apple connections are managed in Aicountly Calendar and are only visible to the person who owns them.'
                    : data.panels.calendar_integration.external_accounts
                        .map((account) => `${account.provider} · ${account.status}`)
                        .join(', ')
                }
              />
              <IntegrationRow
                icon={<CalendarX size={15} />}
                label="Calendar conflicts"
                value={
                  data.panels.calendar_integration.conflicts === null
                    ? 'Not reported'
                    : data.panels.calendar_integration.conflicts === 0
                      ? 'All clear'
                      : `${data.panels.calendar_integration.conflicts} to review`
                }
                tone={
                  data.panels.calendar_integration.conflicts === null
                    ? 'neutral'
                    : data.panels.calendar_integration.conflicts === 0
                      ? 'success'
                      : 'warning'
                }
                detail="Conflicts are detected by Aicountly Calendar across every calendar it syncs. Appointments repeats what it reports."
              />
              <IntegrationRow
                icon={<Timer size={15} />}
                label="Last sync"
                value={
                  data.panels.calendar_integration.last_sync_at === null
                    ? 'Not reported'
                    : new Date(data.panels.calendar_integration.last_sync_at).toLocaleString()
                }
                tone="neutral"
                detail="When Aicountly Calendar last exchanged events with an external provider."
              />
            </div>
          </Panel>
        </>
      )}
    </DashboardFrame>
  )
}

function IntegrationRow({
  icon,
  label,
  value,
  tone,
  detail,
}: {
  icon: React.ReactNode
  label: string
  value: string
  tone: 'success' | 'warning' | 'danger' | 'neutral'
  detail: string
}) {
  return (
    <div
      style={{
        display: 'grid',
        gridTemplateColumns: 'auto minmax(0, 1fr) auto',
        gap: 12,
        alignItems: 'start',
        padding: '12px 14px',
        border: '1px solid var(--line)',
        borderRadius: 12,
        background: 'var(--surface-soft)',
      }}
    >
      <span className="appt-metric-icon" aria-hidden style={{ margin: 0, width: 30, height: 30 }}>
        {icon}
      </span>
      <span style={{ minWidth: 0 }}>
        <strong style={{ display: 'block', fontWeight: 650 }}>{label}</strong>
        <small style={{ color: 'var(--muted)', lineHeight: 1.5, display: 'block', marginTop: 2 }}>{detail}</small>
      </span>
      <span className={`appt-status appt-status-${tone}`}>{value}</span>
    </div>
  )
}
