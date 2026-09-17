/**
 * DASHBOARD 1 — Appointment Command Center.
 *
 * The screen somebody opens at 8:50am. A day view: today's schedule as a live
 * timeline, the handful of things that need a decision, and where the bookings
 * are coming from.
 *
 * THE TIMELINE SHOWS OPEN SLOTS TOO. A day view that shows only what is booked
 * hides the thing a receptionist can act on — a 60-minute gap at 1pm with a
 * Book button on it is the most useful row on the screen. Those slots come from
 * the same availability engine the booking flow uses, so one that appears here
 * can actually be taken.
 */

import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  AlertTriangle,
  CalendarCheck,
  CalendarClock,
  CalendarDays,
  ChevronRight,
  CircleDot,
  Clock,
  Gauge,
  ListChecks,
  Plus,
  Users,
} from 'lucide-react'
import { DashboardFrame, useDashboard } from './frame'
import { resolveRoute } from '../shell/navConfig'
import { useAppointments } from '../context/AppointmentsContext'
import { Button, EmptyState, Notice, Panel, StatusPill, statusLabel } from '../ui'
import { DonutChart } from '../ui/charts'
import { NewAppointmentDrawer } from '../components/NewAppointmentDrawer'
import type { AttentionItem, OverviewPanels, TimelineItem } from '../services/types'

const METRIC_ICONS = {
  today_appointments: <CalendarDays size={16} />,
  upcoming: <CalendarClock size={16} />,
  confirmed: <CalendarCheck size={16} />,
  pending: <Clock size={16} />,
  waitlist: <Users size={16} />,
  no_show_risk: <AlertTriangle size={16} />,
  available_slots: <ListChecks size={16} />,
  utilisation: <Gauge size={16} />,
}

export function OverviewDashboard() {
  const navigate = useNavigate()
  const { can } = useAppointments()
  const state = useDashboard<OverviewPanels>('overview')

  const [booking, setBooking] = useState<
    { serviceUuid?: string; memberUuid?: string | null; startsAt?: string } | null
  >(null)

  return (
    <>
      <DashboardFrame
        view="overview"
        state={state}
        showPeriod={false}
        metricIcons={METRIC_ICONS}
        primaryAction={
          can('appointments.booking.create') ? (
            <Button tone="primary" onClick={() => setBooking({})}>
              <Plus size={15} aria-hidden />
              New appointment
            </Button>
          ) : undefined
        }
      >
        {(data) => (
          <>
            {!data.panels.calendar.available && (
              <Notice tone="warning" title="Calendar availability is temporarily unavailable">
                {data.panels.calendar.message} Appointments already booked are shown below; open slots and
                new bookings are paused until Aicountly Calendar answers, because a slot this product cannot
                confirm is a slot it will not offer.
              </Notice>
            )}

            <div className="appt-split">
              <Panel
                title="Today's schedule"
                subtitle="Your day at a glance. Stay on track."
                action={
                  <Button small onClick={() => navigate('/calendar')}>
                    <CalendarDays size={14} aria-hidden />
                    View calendar
                  </Button>
                }
              >
                {data.panels.schedule.items.length === 0 ? (
                  <EmptyState title="Nothing booked today">
                    When appointments are booked they appear here as a live timeline, with open slots between
                    them.
                  </EmptyState>
                ) : (
                  <>
                    <div className="appt-timeline">
                      {data.panels.schedule.items.map((item) => (
                        <TimelineRow
                          key={`${item.kind}-${item.booking_uuid ?? item.starts_at}-${item.member_uuid ?? ''}`}
                          item={item}
                          onOpen={() =>
                            item.booking_uuid
                              ? navigate(`/appointments?booking=${item.booking_uuid}`)
                              : setBooking({
                                  memberUuid: item.member_uuid ?? null,
                                  startsAt: item.starts_at ?? undefined,
                                })
                          }
                          canBook={can('appointments.booking.create')}
                        />
                      ))}
                    </div>

                    <div className="appt-row" style={{ justifyContent: 'space-between', marginTop: 14 }}>
                      <Button small tone="ghost" onClick={() => navigate('/appointments')}>
                        View full day
                        <ChevronRight size={14} aria-hidden />
                      </Button>
                      <span style={{ color: 'var(--muted)', fontSize: 12 }}>
                        {data.panels.schedule.appointment_count} appointment
                        {data.panels.schedule.appointment_count === 1 ? '' : 's'} today
                      </span>
                    </div>
                  </>
                )}
              </Panel>

              <Panel title="Needs attention" subtitle="Key items that need your attention today.">
                {data.panels.attention.length === 0 ? (
                  <EmptyState title="Nothing needs you right now">
                    Every appointment today is confirmed, prepared and on the calendar.
                  </EmptyState>
                ) : (
                  <div className="appt-attention">
                    {data.panels.attention.map((item) => (
                      <AttentionRow key={item.key} item={item} onOpen={navigate} />
                    ))}
                  </div>
                )}
              </Panel>
            </div>

            <Panel
              title="Booking sources"
              subtitle="Where the last 30 days of appointments came from."
              info="Attributed by the credential that created each booking, never by what the request claimed."
            >
              {data.panels.sources.length === 0 ? (
                <EmptyState title="No bookings yet">
                  Once appointments are booked, this shows whether they came from the booking page, the front
                  desk, Receptionist or an integration.
                </EmptyState>
              ) : (
                <DonutChart
                  slices={data.panels.sources.map((source) => ({
                    label: source.label,
                    value: source.total,
                    share: source.share,
                  }))}
                  total={data.panels.sources.reduce((sum, source) => sum + source.total, 0)}
                  totalLabel="Total bookings"
                />
              )}
            </Panel>
          </>
        )}
      </DashboardFrame>

      {booking !== null && (
        <NewAppointmentDrawer
          initial={booking}
          onClose={() => setBooking(null)}
          onBooked={(bookingUuid) => {
            setBooking(null)
            state.reload()
            navigate(`/appointments?booking=${bookingUuid}`)
          }}
        />
      )}
    </>
  )
}

const DOT_COLOUR: Record<string, string> = {
  COMPLETED: '#25b003',
  IN_PROGRESS: '#2563eb',
  CONFIRMED: '#25b003',
  ARRIVED: '#2563eb',
  PENDING: '#f59e0b',
  NO_SHOW: '#b42318',
  CANCELLED: '#c9d7ce',
  AVAILABLE: '#c9d7ce',
}

function TimelineRow({
  item,
  onOpen,
  canBook,
}: {
  item: TimelineItem
  onOpen: () => void
  canBook: boolean
}) {
  const available = item.kind === 'available'

  return (
    <div className="appt-timeline-row" data-kind={item.kind}>
      <span className="appt-timeline-time">{item.local_time}</span>
      <span
        className="appt-timeline-dot"
        aria-hidden
        style={{ background: DOT_COLOUR[item.status] ?? '#c9d7ce' }}
      />

      <div className="appt-timeline-body">
        <strong>{available ? 'Available slot' : item.client_label}</strong>
        <span>
          {available
            ? `${item.duration_minutes} min${item.member_label ? ` · ${item.member_label}` : ''}`
            : `${item.service_name} · ${item.duration_minutes} min${item.member_label ? ` · ${item.member_label}` : ''}`}
        </span>
      </div>

      {available ? (
        canBook ? (
          <Button small onClick={onOpen}>
            <Plus size={13} aria-hidden />
            Book
          </Button>
        ) : (
          <StatusPill status="AVAILABLE" />
        )
      ) : (
        <div className="appt-row-tight">
          {item.calendar_sync_state === 'failed' && (
            <span
              title="This booking has no entry on anybody's calendar yet. It still holds its slot here."
              style={{ color: 'var(--danger)' }}
            >
              <AlertTriangle size={14} aria-hidden />
              <span className="appt-visually-hidden">No calendar entry</span>
            </span>
          )}
          {(item.attendee_count ?? 0) > 0 && (
            <span style={{ color: 'var(--muted)', fontSize: 12 }}>+{item.attendee_count}</span>
          )}
          <button
            type="button"
            onClick={onOpen}
            style={{ border: 0, background: 'transparent', padding: 0, cursor: 'pointer' }}
            title={`Open ${item.reference ?? 'appointment'}`}
          >
            <StatusPill status={item.status}>{statusLabel(item.status)}</StatusPill>
          </button>
        </div>
      )}
    </div>
  )
}

function AttentionRow({ item, onOpen }: { item: AttentionItem; onOpen: (path: string) => void }) {
  const path = resolveRoute(item.action.route, item.action.params)

  const tone =
    item.tone === 'danger'
      ? 'appt-metric-icon appt-metric-icon-danger'
      : item.tone === 'warning'
        ? 'appt-metric-icon appt-metric-icon-warning'
        : item.tone === 'info'
          ? 'appt-metric-icon appt-metric-icon-info'
          : 'appt-metric-icon'

  return (
    <button type="button" onClick={() => path && onOpen(path)} disabled={path === null}>
      <span className={tone} aria-hidden style={{ margin: 0, width: 30, height: 30 }}>
        <CircleDot size={14} />
      </span>
      <span style={{ minWidth: 0 }}>
        <strong>
          {item.count} {item.title}
        </strong>
        <small>{item.detail}</small>
      </span>
      <span className="appt-count-pill">{item.count}</span>
      <ChevronRight size={15} aria-hidden style={{ color: 'var(--muted)' }} />
    </button>
  )
}
