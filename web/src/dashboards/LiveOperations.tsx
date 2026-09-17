/**
 * DASHBOARD 2 — Live Operations.
 *
 * The front-desk screen, open all day, refreshing itself. Where Overview asks
 * about the day, this asks about the minute: who is in the building, who is
 * late, which room is free, who is waiting.
 *
 * THE SCORE IS EXPLAINABLE. It starts at 100 and subtracts named penalties, and
 * the breakdown comes back with it — so a manager who disagrees with the number
 * can see exactly which part they disagree with. No model produces it.
 *
 * REFRESHING PAUSES WHEN THE TAB IS HIDDEN. A screen behind a desk should not
 * spend the night polling.
 */

import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Activity,
  AlertTriangle,
  Clock,
  Hourglass,
  PhoneCall,
  Play,
  Send,
  UserCheck,
  Users,
} from 'lucide-react'
import { DashboardFrame, useDashboard } from './frame'
import { api, ApiError } from '../services/api'
import { useAppointments } from '../context/AppointmentsContext'
import { Button, Drawer, EmptyState, Notice, Panel, StatusPill } from '../ui'
import type { LivePanels, LiveAppointmentRow, TeamStatusRow } from '../services/types'

const METRIC_ICONS = {
  currently_active: <Activity size={16} />,
  arriving_next_hour: <Clock size={16} />,
  schedule_health: <Activity size={16} />,
  team_available: <Users size={16} />,
  delayed: <AlertTriangle size={16} />,
  waiting: <Hourglass size={16} />,
}

export function LiveOperationsDashboard() {
  const navigate = useNavigate()
  const { can } = useAppointments()

  // Poll every 30 seconds. The cadence is the server's decision —
  // `refresh_seconds` — but the hook needs a number before the first response
  // arrives, so this is the floor rather than the source of truth.
  const state = useDashboard<LivePanels>('live', { pollSeconds: 30 })

  const [health, setHealth] = useState(false)
  const [acting, setActing] = useState<string | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)

  async function act(booking: LiveAppointmentRow, action: string): Promise<void> {
    if (action === 'call') {
      // Receptionist owns voice. Until it is connected this is a phone number,
      // which is more honest than a button that does nothing.
      if (booking.client_phone) window.location.href = `tel:${booking.client_phone}`
      return
    }

    if (action === 'reschedule' || action === 'cancel') {
      navigate(`/appointments?booking=${booking.booking_uuid}&action=${action}`)
      return
    }

    setActing(`${booking.booking_uuid}:${action}`)
    setActionError(null)

    try {
      await api.post(`v1/bookings/${booking.booking_uuid}/${apiAction(action)}`, {})
      state.reload()
    } catch (error) {
      setActionError(error instanceof ApiError ? error.message : (error as Error).message)
    } finally {
      setActing(null)
    }
  }

  return (
    <>
      <DashboardFrame view="live" state={state} showPeriod={false} metricIcons={METRIC_ICONS}>
        {(data) => (
          <>
            {actionError !== null && (
              <Notice tone="danger" title="That action did not go through">
                {actionError}
              </Notice>
            )}

            <div className="appt-split">
              <Panel
                title="Appointment flow (today)"
                subtitle="Live flow of appointments through your practice."
                action={
                  data.panels.flow.being_booked > 0 ? (
                    <span className="appt-status appt-status-info">
                      {data.panels.flow.being_booked} being booked now
                    </span>
                  ) : undefined
                }
              >
                <div
                  style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(auto-fit, minmax(104px, 1fr))',
                    gap: 8,
                  }}
                >
                  {data.panels.flow.stages.map((stage) => (
                    <div
                      key={stage.key}
                      style={{
                        padding: '12px 10px',
                        borderRadius: 12,
                        border: '1px solid var(--line)',
                        background: stage.tone === 'danger' ? 'var(--danger-soft)' : 'var(--surface-soft)',
                        textAlign: 'center',
                      }}
                    >
                      <div
                        className="num"
                        style={{ fontSize: 22, fontWeight: 700, textAlign: 'center', letterSpacing: '-0.03em' }}
                      >
                        {stage.count}
                      </div>
                      <div style={{ color: 'var(--muted)', fontSize: 11.5, fontWeight: 650, marginTop: 2 }}>
                        {stage.label}
                      </div>
                    </div>
                  ))}
                </div>
              </Panel>

              <Panel
                title="Schedule health"
                subtitle="How today is going, and exactly why."
                action={
                  <Button small tone="ghost" onClick={() => setHealth(true)}>
                    View details
                  </Button>
                }
              >
                <div className="appt-row" style={{ gap: 20, alignItems: 'center' }}>
                  <div style={{ textAlign: 'center', flex: 'none' }}>
                    <div
                      style={{
                        fontSize: 46,
                        fontWeight: 700,
                        letterSpacing: '-0.04em',
                        lineHeight: 1,
                        color: healthColour(data.panels.health.band),
                      }}
                    >
                      {data.panels.health.score}
                    </div>
                    <div style={{ color: 'var(--muted)', fontSize: 12 }}>out of 100</div>
                  </div>

                  <div style={{ flex: 1, minWidth: 0 }}>
                    {data.panels.health.components.length === 0 ? (
                      <p style={{ margin: 0, color: 'var(--muted)' }}>
                        {data.panels.health.appointments === 0
                          ? 'Nothing booked today, so there is nothing to go wrong.'
                          : 'Everything is running on time.'}
                      </p>
                    ) : (
                      <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: 6 }}>
                        {data.panels.health.components.map((component) => (
                          <li
                            key={component.key}
                            style={{ display: 'flex', justifyContent: 'space-between', gap: 10, fontSize: 13 }}
                          >
                            <span style={{ minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis' }}>
                              {component.label}
                            </span>
                            <span className="num" style={{ color: 'var(--danger)', fontWeight: 650 }}>
                              −{component.penalty}
                            </span>
                          </li>
                        ))}
                      </ul>
                    )}
                  </div>
                </div>
              </Panel>
            </div>

            <div className="appt-grid appt-grid-3">
              <Panel title="Team status" subtitle="Where everybody is right now.">
                {data.panels.team.length === 0 ? (
                  <EmptyState title="Nobody on the team yet">
                    Add practitioners under Team &amp; Availability to see them here.
                  </EmptyState>
                ) : (
                  <div className="appt-stack" style={{ gap: 8 }}>
                    {data.panels.team.map((member) => (
                      <TeamRow key={member.member_uuid} member={member} />
                    ))}
                  </div>
                )}
              </Panel>

              <Panel title="Resource status" subtitle="Rooms, cabins and equipment.">
                {data.panels.resources.length === 0 ? (
                  <EmptyState title="No resources configured">
                    Add rooms or equipment under Locations &amp; Resources to track them here.
                  </EmptyState>
                ) : (
                  <div className="appt-stack" style={{ gap: 8 }}>
                    {data.panels.resources.map((resource) => (
                      <div
                        key={resource.resource_uuid}
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
                          <strong style={{ display: 'block', fontWeight: 650 }}>{resource.name}</strong>
                          <small style={{ color: 'var(--muted)' }}>{resource.detail}</small>
                        </span>
                        <span
                          className={`appt-status appt-status-${
                            resource.state === 'available' ? 'success' : resource.state === 'turnaround' ? 'info' : 'warning'
                          }`}
                        >
                          {resource.state === 'available'
                            ? 'Available'
                            : resource.state === 'turnaround'
                              ? 'Turnaround'
                              : 'Occupied'}
                        </span>
                      </div>
                    ))}
                  </div>
                )}
              </Panel>

              <Panel
                title="Waitlist quick fill"
                subtitle="A slot has come free. Who would take it?"
                info="Matched on service, practitioner preference, time of day, weekday and waiting time."
              >
                {!data.panels.quick_fill.available ? (
                  <EmptyState title="Nothing to fill">{data.panels.quick_fill.reason}</EmptyState>
                ) : data.panels.quick_fill.matches.length === 0 ? (
                  <EmptyState title="No waitlisted client matches">
                    A slot came free at {data.panels.quick_fill.slot?.local_time}, but nobody on the waitlist
                    wants that service at that time.
                  </EmptyState>
                ) : (
                  <>
                    <div
                      style={{
                        padding: '10px 12px',
                        borderRadius: 11,
                        background: 'var(--soft)',
                        marginBottom: 12,
                      }}
                    >
                      <strong style={{ display: 'block' }}>{data.panels.quick_fill.slot?.local_time}</strong>
                      <small style={{ color: 'var(--muted)' }}>
                        {data.panels.quick_fill.slot?.duration_minutes} minutes ·{' '}
                        {data.panels.quick_fill.slot?.service_name}
                      </small>
                    </div>

                    <div className="appt-stack" style={{ gap: 8 }}>
                      {data.panels.quick_fill.matches.map((match, index) => (
                        <div
                          key={match.waitlist_uuid}
                          className="appt-row"
                          style={{ justifyContent: 'space-between', gap: 10 }}
                        >
                          <span style={{ minWidth: 0 }}>
                            <strong style={{ display: 'block', fontWeight: 650 }}>
                              {index + 1}. {match.client_label}
                            </strong>
                            <small style={{ color: 'var(--muted)' }}>
                              {match.reasons.map((reason) => reason.label).join(' · ')}
                            </small>
                          </span>
                          <span className="appt-status appt-status-success">{match.score}% match</span>
                        </div>
                      ))}
                    </div>

                    {can('appointments.waitlist.manage') && (
                      <Button
                        tone="primary"
                        onClick={() => navigate('/waitlist')}
                        style={{ width: '100%', marginTop: 14 }}
                      >
                        Offer this slot
                      </Button>
                    )}
                  </>
                )}
              </Panel>
            </div>

            <Panel
              title="Live appointments (today)"
              subtitle={`Auto-refreshing every ${data.panels.refresh_seconds} seconds`}
            >
              {data.panels.appointments.length === 0 ? (
                <EmptyState title="Nothing booked today" />
              ) : (
                <div className="appt-table-wrap">
                  <table className="appt-table">
                    <thead>
                      <tr>
                        <th>Time</th>
                        <th>Client</th>
                        <th>Service</th>
                        <th>With</th>
                        <th>Status</th>
                        <th style={{ textAlign: 'right' }}>Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.panels.appointments.map((booking) => (
                        <tr key={booking.booking_uuid}>
                          <td style={{ whiteSpace: 'nowrap' }}>
                            <strong>{booking.local_time}</strong>
                            {booking.late_by_minutes !== null && (
                              <div style={{ color: 'var(--danger)', fontSize: 11.5, fontWeight: 650 }}>
                                {booking.late_by_minutes} min late
                              </div>
                            )}
                          </td>
                          <td>
                            <button
                              type="button"
                              onClick={() => navigate(`/appointments?booking=${booking.booking_uuid}`)}
                              style={{ border: 0, background: 'transparent', padding: 0, cursor: 'pointer', textAlign: 'left' }}
                            >
                              <strong>{booking.client_label}</strong>
                              <div style={{ color: 'var(--muted)', fontSize: 11.5 }}>{booking.reference}</div>
                            </button>
                          </td>
                          <td>{booking.service_name}</td>
                          <td style={{ color: 'var(--muted)' }}>{booking.member_label ?? '—'}</td>
                          <td>
                            <StatusPill status={booking.status} />
                          </td>
                          <td>
                            <div className="appt-row-tight" style={{ justifyContent: 'flex-end' }}>
                              {booking.actions.slice(0, 3).map((action) => (
                                <Button
                                  key={action.key}
                                  small
                                  tone={action.primary ? 'primary' : 'default'}
                                  busy={acting === `${booking.booking_uuid}:${action.key}`}
                                  onClick={() => act(booking, action.key)}
                                >
                                  {ACTION_ICONS[action.key]}
                                  {action.label}
                                </Button>
                              ))}
                            </div>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </Panel>

            {health && (
              <Drawer
                title="How the schedule health score is worked out"
                subtitle="Arithmetic, not a model."
                onClose={() => setHealth(false)}
              >
                <p style={{ margin: 0, lineHeight: 1.6 }}>
                  The score starts at <strong>100</strong> and subtracts a penalty for each thing going wrong
                  today. Each penalty scales with the share of the day it affects, so three no-shows out of
                  five costs more than three out of eighty.
                </p>

                <div className="appt-table-wrap">
                  <table className="appt-table">
                    <thead>
                      <tr>
                        <th>What</th>
                        <th className="num">Count</th>
                        <th className="num">Share</th>
                        <th className="num">Penalty</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.panels.health.components.map((component) => (
                        <tr key={component.key}>
                          <td>{component.label}</td>
                          <td className="num">{component.count}</td>
                          <td className="num">{component.share}%</td>
                          <td className="num" style={{ color: 'var(--danger)' }}>
                            −{component.penalty}
                          </td>
                        </tr>
                      ))}
                      <tr>
                        <td colSpan={3}>
                          <strong>Score</strong>
                        </td>
                        <td className="num">
                          <strong>{data.panels.health.score}</strong>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                <p style={{ color: 'var(--muted)', margin: 0, lineHeight: 1.6 }}>
                  {data.panels.health.on_time_share}% of the appointments that have started today started on
                  time. A day with nothing booked scores 100 — an empty day is not an unhealthy day.
                </p>
              </Drawer>
            )}
          </>
        )}
      </DashboardFrame>
    </>
  )
}

const ACTION_ICONS: Record<string, React.ReactNode> = {
  start: <Play size={13} aria-hidden />,
  arrive: <UserCheck size={13} aria-hidden />,
  remind: <Send size={13} aria-hidden />,
  call: <PhoneCall size={13} aria-hidden />,
}

function apiAction(action: string): string {
  return (
    {
      start: 'start',
      arrive: 'arrive',
      complete: 'complete',
      no_show: 'no-show',
      confirm: 'confirm',
      remind: 'confirm',
    }[action] ?? action
  )
}

function healthColour(band: string): string {
  return band === 'attention' ? 'var(--danger)' : band === 'watch' ? 'var(--warning)' : 'var(--action)'
}

function TeamRow({ member }: { member: TeamStatusRow }) {
  const tone =
    member.state === 'running_late'
      ? 'danger'
      : member.state === 'in_appointment'
        ? 'info'
        : member.state === 'break'
          ? 'warning'
          : 'success'

  const label =
    member.state === 'running_late'
      ? `Running ${member.late_by_minutes} min late`
      : member.state === 'in_appointment'
        ? 'In appointment'
        : member.state === 'break'
          ? 'Break'
          : 'Available'

  return (
    <div
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
        <strong style={{ display: 'block', fontWeight: 650 }}>{member.label}</strong>
        <small style={{ color: 'var(--muted)' }}>{member.job_title ?? member.detail}</small>
      </span>
      <span className={`appt-status appt-status-${tone}`}>{label}</span>
    </div>
  )
}
