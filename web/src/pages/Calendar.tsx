/**
 * The calendar inside Appointments.
 *
 * ## Appointments may draw a calendar; it may not own one
 *
 * Every event in this grid comes from Aicountly Calendar on the request that
 * draws it. There is no local event table and no synchronisation. What is shown
 * is two layers:
 *
 *  - APPOINTMENTS, this product's own bookings, with the client, the service
 *    and the status. Ours to describe.
 *  - BUSY, everything else on the practitioners' calendars — as blocks of time
 *    with no title. Somebody's dentist appointment occupies 3pm, and that is
 *    all a practice calendar needs to know about it.
 *
 * When Calendar cannot be read, the appointments layer still draws and a banner
 * says the rest of the diary is missing. It does NOT silently show a day that
 * looks emptier than it is.
 */

import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronLeft, ChevronRight, Plus } from 'lucide-react'
import { api } from '../services/api'
import { useApi } from '../hooks/useApi'
import { useAppointments } from '../context/AppointmentsContext'
import type { CalendarViewResponse } from '../services/types'
import { Button, EmptyState, Notice, PanelState, StatusPill } from '../ui'
import { NewAppointmentDrawer, todayInZone } from '../components/NewAppointmentDrawer'

const DAY_LABELS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

export function CalendarPage() {
  const navigate = useNavigate()
  const { timezone, can } = useAppointments()

  const [anchor, setAnchor] = useState(() => todayInZone(timezone))
  const [memberUuid, setMemberUuid] = useState('')
  const [booking, setBooking] = useState<{ startsAt?: string; memberUuid?: string | null } | null>(null)

  const weekStart = useMemo(() => startOfWeek(anchor), [anchor])
  const weekEnd = useMemo(() => addDays(weekStart, 7), [weekStart])

  const view = useApi(
    (signal) =>
      api.get<{ data: CalendarViewResponse }>(
        'v1/calendar',
        { from: `${weekStart}T00:00:00`, to: `${weekEnd}T00:00:00`, member_uuid: memberUuid || undefined },
        signal,
      ),
    [weekStart, weekEnd, memberUuid],
  )

  const days = useMemo(() => Array.from({ length: 7 }, (_, index) => addDays(weekStart, index)), [weekStart])

  return (
    <div className="appt-ui">
      <header className="appt-page-header">
        <div>
          <h1>Calendar</h1>
          <p>Your appointments and the rest of the team's diary, side by side.</p>
        </div>

        <div className="appt-actions">
          <div className="appt-row-tight">
            <Button small onClick={() => setAnchor(addDays(weekStart, -7))} aria-label="Previous week">
              <ChevronLeft size={15} aria-hidden />
            </Button>
            <Button small onClick={() => setAnchor(todayInZone(timezone))}>
              Today
            </Button>
            <Button small onClick={() => setAnchor(addDays(weekStart, 7))} aria-label="Next week">
              <ChevronRight size={15} aria-hidden />
            </Button>
          </div>

          <select
            className="appt-select"
            style={{ minWidth: 180 }}
            value={memberUuid}
            onChange={(event) => setMemberUuid(event.target.value)}
          >
            <option value="">Whole team</option>
            {(view.data?.data.members ?? []).map((member) => (
              <option key={member.member_uuid} value={member.member_uuid}>
                {member.label}
              </option>
            ))}
          </select>

          {can('appointments.booking.create') && (
            <Button tone="primary" onClick={() => setBooking({})}>
              <Plus size={15} aria-hidden />
              New appointment
            </Button>
          )}
        </div>
      </header>

      <PanelState
        loading={view.loading}
        error={view.error}
        data={view.data}
        emptyTitle="This week could not be loaded"
        onRetry={view.reload}
        skeletonRows={5}
      >
        {(payload) => {
          const data = payload.data

          const byDay = new Map<string, typeof data.appointments>()
          for (const appointment of data.appointments) {
            const key = appointment.local_date ?? ''
            const existing = byDay.get(key)
            if (existing) existing.push(appointment)
            else byDay.set(key, [appointment])
          }

          const busyByDay = new Map<string, number>()
          for (const block of data.busy.blocks) {
            const key = localDate(block.starts_at, timezone)
            busyByDay.set(key, (busyByDay.get(key) ?? 0) + 1)
          }

          return (
            <>
              {!data.busy.available && (
                <Notice tone="warning" title="Part of the diary could not be read">
                  {data.busy.message} Appointments booked through this product are shown below and are
                  complete. Anything a practitioner put in their own calendar is missing from this view until
                  Aicountly Calendar answers.
                </Notice>
              )}

              <div
                style={{
                  display: 'grid',
                  gridTemplateColumns: 'repeat(7, minmax(150px, 1fr))',
                  gap: 10,
                  overflowX: 'auto',
                }}
              >
                {days.map((day) => {
                  const appointments = byDay.get(day) ?? []
                  const busyCount = busyByDay.get(day) ?? 0
                  const isToday = day === todayInZone(timezone)

                  return (
                    <div
                      key={day}
                      style={{
                        background: 'var(--surface)',
                        border: `1px solid ${isToday ? 'rgb(37 176 3 / 45%)' : 'var(--line)'}`,
                        borderRadius: 14,
                        padding: 12,
                        minHeight: 320,
                      }}
                    >
                      <div className="appt-row" style={{ justifyContent: 'space-between', marginBottom: 10 }}>
                        <span>
                          <strong style={{ display: 'block', fontSize: 13 }}>
                            {DAY_LABELS[new Date(`${day}T12:00:00Z`).getUTCDay()]}
                          </strong>
                          <small style={{ color: 'var(--muted)' }}>{day.slice(8)}</small>
                        </span>
                        {busyCount > 0 && (
                          <span
                            className="appt-status appt-status-neutral"
                            title="Blocks of time on team calendars that are not Appointments bookings. Titles are never read."
                          >
                            {busyCount} busy
                          </span>
                        )}
                      </div>

                      {appointments.length === 0 ? (
                        <p style={{ color: 'var(--muted)', fontSize: 12, margin: 0 }}>Nothing booked</p>
                      ) : (
                        <div className="appt-stack" style={{ gap: 6 }}>
                          {appointments.map((appointment) => (
                            <button
                              key={appointment.booking_uuid}
                              type="button"
                              onClick={() => navigate(`/appointments?booking=${appointment.booking_uuid}`)}
                              style={{
                                display: 'block',
                                width: '100%',
                                textAlign: 'left',
                                padding: '8px 10px',
                                borderRadius: 9,
                                border: '1px solid var(--line)',
                                borderLeft: `3px solid ${appointment.colour ?? '#25b003'}`,
                                background: 'var(--surface-soft)',
                                cursor: 'pointer',
                              }}
                            >
                              <strong style={{ display: 'block', fontSize: 12.5 }}>{appointment.local_time}</strong>
                              <span style={{ display: 'block', fontSize: 12.5, fontWeight: 600 }}>
                                {appointment.title}
                              </span>
                              <small style={{ color: 'var(--muted)' }}>{appointment.service_name}</small>
                              <div style={{ marginTop: 5 }}>
                                <StatusPill status={appointment.status} />
                              </div>
                            </button>
                          ))}
                        </div>
                      )}
                    </div>
                  )
                })}
              </div>

              {data.appointments.length === 0 && (
                <div style={{ marginTop: 16 }}>
                  <EmptyState title="Nothing booked this week">
                    Use New appointment, or Find slot in the header, to fill it.
                  </EmptyState>
                </div>
              )}

              <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 14 }}>
                Appointments are this product's own. Busy time comes from Aicountly Calendar, which owns every
                calendar in the fleet — its titles are deliberately not read, because a practice calendar needs
                to know that 3pm is taken, not what for.
              </p>
            </>
          )
        }}
      </PanelState>

      {booking !== null && (
        <NewAppointmentDrawer
          initial={booking}
          onClose={() => setBooking(null)}
          onBooked={() => {
            setBooking(null)
            view.reload()
          }}
        />
      )}
    </div>
  )
}

function startOfWeek(date: string): string {
  const parsed = new Date(`${date}T12:00:00Z`)
  // Monday-first, which is what a business week is in every market this ships to.
  const day = (parsed.getUTCDay() + 6) % 7
  parsed.setUTCDate(parsed.getUTCDate() - day)
  return parsed.toISOString().slice(0, 10)
}

function addDays(date: string, days: number): string {
  const parsed = new Date(`${date}T12:00:00Z`)
  parsed.setUTCDate(parsed.getUTCDate() + days)
  return parsed.toISOString().slice(0, 10)
}

function localDate(iso: string, timezone: string): string {
  try {
    return new Intl.DateTimeFormat('en-CA', {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      timeZone: timezone,
    }).format(new Date(iso))
  } catch {
    return iso.slice(0, 10)
  }
}
