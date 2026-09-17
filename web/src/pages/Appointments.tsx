/**
 * The appointment list and the detail panel.
 *
 * ## The detail panel shows BOTH times
 *
 * The booking's agreed time — what was arranged, what the reminders count down
 * to — and Calendar's current time for the event. When a practitioner drags the
 * event later in their own Google calendar, those disagree, and this screen
 * says so rather than quietly preferring one. That disagreement is a real
 * thing that happens and the client has not been told about it.
 */

import { useCallback, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { AlertTriangle, CalendarClock, ExternalLink, Filter, RefreshCw } from 'lucide-react'
import { api, ApiError } from '../services/api'
import { useApi } from '../hooks/useApi'
import { useAppointments } from '../context/AppointmentsContext'
import type { Booking, BookingStatus } from '../services/types'
import {
  Button,
  Drawer,
  EmptyState,
  Field,
  LoadingRows,
  Notice,
  PanelState,
  StatusPill,
  formatDateTime,
  statusLabel,
} from '../ui'

interface BookingDetail {
  booking: Booking
  metadata: Record<string, unknown>
  reminders: {
    reminder_uuid: string
    channel: string
    scheduled_for: string
    status: string
    status_detail: string | null
    outcome: string | null
  }[]
  calendar: {
    state: string
    message: string | null
    event: { event_uuid: string; title: string; start_at: string | null; end_at: string; status: string } | null
    matches_booking: boolean | null
  }
  client: {
    contact_uuid: string | null
    captured: { name: string; email: string; phone: string }
    contact: Record<string, unknown> | null
    contact_message: string | null
    relationship: Record<string, unknown> | null
    relationship_message: string | null
    appointment_profile: Record<string, unknown> | null
  }
  history: { booking_uuid: string; reference: string; starts_at: string; status: string; reason: string | null; is_current: boolean }[]
}

const STATUS_FILTERS: { value: string; label: string }[] = [
  { value: '', label: 'Every status' },
  { value: 'PENDING', label: 'Awaiting confirmation' },
  { value: 'CONFIRMED', label: 'Confirmed' },
  { value: 'ARRIVED', label: 'Arrived' },
  { value: 'IN_PROGRESS', label: 'In progress' },
  { value: 'COMPLETED', label: 'Completed' },
  { value: 'CANCELLED', label: 'Cancelled' },
  { value: 'NO_SHOW', label: 'No-show' },
]

export function AppointmentsPage() {
  const [params, setParams] = useSearchParams()
  const { timezone, can } = useAppointments()

  const status = params.get('status') ?? ''
  const query = params.get('q') ?? ''
  const upcoming = params.get('upcoming')
  const calendarFilter = params.get('calendar')
  const openBooking = params.get('booking')

  const setParam = useCallback(
    (key: string, value: string | null) => {
      const next = new URLSearchParams(params)
      if (value === null || value === '') next.delete(key)
      else next.set(key, value)
      setParams(next, { replace: true })
    },
    [params, setParams],
  )

  const list = useApi(
    (signal) =>
      api.list<Booking>(
        'v1/bookings',
        {
          status: status || undefined,
          q: query || undefined,
          upcoming: upcoming ?? undefined,
          calendar: calendarFilter ?? undefined,
          forms: params.get('forms') ?? undefined,
          payment: params.get('payment') ?? undefined,
          limit: 50,
        },
        signal,
      ),
    [status, query, upcoming, calendarFilter, params.get('forms'), params.get('payment')],
  )

  return (
    <div className="appt-ui">
      <header className="appt-page-header">
        <div>
          <h1>Appointments</h1>
          <p>Every booking, with what it is waiting on.</p>
        </div>
        <div className="appt-actions">
          <Button small onClick={list.reload}>
            <RefreshCw size={14} aria-hidden />
            Refresh
          </Button>
        </div>
      </header>

      <div className="appt-filters">
        <Field label="Search">
          <input
            className="appt-input"
            style={{ minWidth: 260 }}
            value={query}
            onChange={(event) => setParam('q', event.target.value)}
            placeholder="Name, phone, email or AP-1042"
          />
        </Field>

        <Field label="Status">
          <select className="appt-select" value={status} onChange={(event) => setParam('status', event.target.value)}>
            {STATUS_FILTERS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </Field>

        <button
          type="button"
          className="appt-chip"
          aria-pressed={upcoming !== null}
          onClick={() => setParam('upcoming', upcoming === null ? '1' : null)}
        >
          <CalendarClock size={14} aria-hidden />
          Upcoming only
        </button>

        <button
          type="button"
          className="appt-chip"
          aria-pressed={calendarFilter === 'failed'}
          onClick={() => setParam('calendar', calendarFilter === 'failed' ? null : 'failed')}
          title="Bookings whose calendar entry failed to write"
        >
          <AlertTriangle size={14} aria-hidden />
          No calendar entry
        </button>

        {params.toString() !== '' && (
          <Button small tone="ghost" onClick={() => setParams(new URLSearchParams(), { replace: true })}>
            <Filter size={14} aria-hidden />
            Clear filters
          </Button>
        )}
      </div>

      <PanelState
        loading={list.loading}
        error={list.error}
        data={list.data}
        isEmpty={(data) => data.data.length === 0}
        emptyTitle="No appointments match"
        emptyBody="Try clearing the filters, or book one from the header."
        onRetry={list.reload}
        skeletonRows={6}
      >
        {(data) => (
          <>
            <div className="appt-table-wrap">
              <table className="appt-table">
                <thead>
                  <tr>
                    <th>When</th>
                    <th>Client</th>
                    <th>Service</th>
                    <th>With</th>
                    <th>Source</th>
                    <th>Status</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {data.data.map((booking) => (
                    <tr key={booking.booking_uuid}>
                      <td style={{ whiteSpace: 'nowrap' }}>
                        <strong>{formatDateTime(booking.starts_at, timezone)}</strong>
                        <div style={{ color: 'var(--muted)', fontSize: 11.5 }}>{booking.reference}</div>
                      </td>
                      <td>
                        <strong>{booking.client.name || 'Client'}</strong>
                        <div style={{ color: 'var(--muted)', fontSize: 11.5 }}>
                          {booking.client.phone || booking.client.email || '—'}
                        </div>
                      </td>
                      <td>{booking.service.name}</td>
                      <td style={{ color: 'var(--muted)' }}>{booking.member?.label ?? '—'}</td>
                      <td style={{ color: 'var(--muted)', fontSize: 12 }}>{booking.booking_source.replace(/_/g, ' ').toLowerCase()}</td>
                      <td>
                        <div className="appt-row-tight">
                          <StatusPill status={booking.status} />
                          {booking.calendar.state === 'failed' && (
                            <span title="No entry on anybody's calendar yet" style={{ color: 'var(--danger)' }}>
                              <AlertTriangle size={14} aria-hidden />
                            </span>
                          )}
                        </div>
                      </td>
                      <td style={{ textAlign: 'right' }}>
                        <Button small onClick={() => setParam('booking', booking.booking_uuid)}>
                          Open
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 12 }}>
              Showing {data.data.length} of {data.meta.total}
            </p>
          </>
        )}
      </PanelState>

      {openBooking !== null && (
        <BookingDetailDrawer
          bookingUuid={openBooking}
          onClose={() => setParam('booking', null)}
          onChanged={list.reload}
          canEdit={can('appointments.booking.edit')}
          canCancel={can('appointments.booking.cancel')}
        />
      )}
    </div>
  )
}

function BookingDetailDrawer({
  bookingUuid,
  onClose,
  onChanged,
  canEdit,
  canCancel,
}: {
  bookingUuid: string
  onClose: () => void
  onChanged: () => void
  canEdit: boolean
  canCancel: boolean
}) {
  const { timezone } = useAppointments()
  const [acting, setActing] = useState<string | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const [cancelReason, setCancelReason] = useState('')
  const [cancelling, setCancelling] = useState(false)

  const detail = useApi(
    (signal) => api.get<{ data: BookingDetail }>(`v1/bookings/${bookingUuid}`, undefined, signal),
    [bookingUuid],
  )

  async function transition(action: string): Promise<void> {
    setActing(action)
    setActionError(null)

    try {
      await api.post(`v1/bookings/${bookingUuid}/${action}`, {})
      detail.reload()
      onChanged()
    } catch (error) {
      setActionError(error instanceof ApiError ? error.message : (error as Error).message)
    } finally {
      setActing(null)
    }
  }

  async function cancel(): Promise<void> {
    if (cancelReason.trim() === '') {
      setActionError('A reason is required to cancel an appointment.')
      return
    }

    setCancelling(true)
    setActionError(null)

    try {
      await api.post(`v1/bookings/${bookingUuid}/cancel`, { reason: cancelReason })
      detail.reload()
      onChanged()
      setCancelReason('')
    } catch (error) {
      setActionError(error instanceof ApiError ? error.message : (error as Error).message)
    } finally {
      setCancelling(false)
    }
  }

  async function retryCalendar(): Promise<void> {
    setActing('calendar')
    setActionError(null)

    try {
      await api.post(`v1/bookings/${bookingUuid}/retry-calendar`, {})
      detail.reload()
      onChanged()
    } catch (error) {
      setActionError(error instanceof ApiError ? error.message : (error as Error).message)
    } finally {
      setActing(null)
    }
  }

  const booking = detail.data?.data.booking

  return (
    <Drawer
      title={booking?.reference ?? 'Appointment'}
      subtitle={booking ? `${booking.service.name} · ${booking.client.name || 'Client'}` : undefined}
      onClose={onClose}
      wide
    >
      {detail.loading ? (
        <LoadingRows rows={4} />
      ) : detail.error !== null ? (
        <Notice tone="danger" title="This appointment could not be loaded">
          {detail.error.message}
        </Notice>
      ) : detail.data === null ? (
        <EmptyState title="Not found" />
      ) : (
        (() => {
          const data = detail.data.data

          return (
            <>
              {actionError !== null && (
                <Notice tone="danger" title="That did not work">
                  {actionError}
                </Notice>
              )}

              <div className="appt-row" style={{ justifyContent: 'space-between' }}>
                <StatusPill status={data.booking.status} />
                <span style={{ color: 'var(--muted)', fontSize: 12.5 }}>
                  Booked via {data.booking.booking_source.replace(/_/g, ' ').toLowerCase()}
                </span>
              </div>

              {/* --- Time: ours and Calendar's -------------------------- */}
              <section>
                <p className="appt-eyebrow">When</p>

                <dl className="appt-definition-list">
                  <div>
                    <dt>Agreed with the client</dt>
                    <dd>
                      <strong>{formatDateTime(data.booking.starts_at, timezone)}</strong>
                      <div style={{ color: 'var(--muted)', fontSize: 12.5 }}>
                        {data.booking.duration_minutes} minutes · {data.booking.timezone}
                      </div>
                    </dd>
                  </div>

                  <div>
                    <dt>On the calendar</dt>
                    <dd>
                      {data.calendar.event === null ? (
                        <span style={{ color: 'var(--muted)' }}>{data.calendar.message}</span>
                      ) : (
                        <>
                          <strong>{formatDateTime(data.calendar.event.start_at, timezone)}</strong>
                          <div style={{ color: 'var(--muted)', fontSize: 12.5 }}>
                            Aicountly Calendar · {data.calendar.event.status}
                          </div>
                        </>
                      )}
                    </dd>
                  </div>
                </dl>

                {data.calendar.matches_booking === false && (
                  <Notice tone="warning" title="The calendar entry has been moved">
                    Somebody changed this event in Aicountly Calendar after the appointment was agreed. The
                    client was told the time above. Reschedule the appointment here if the new time is the
                    right one — that is what moves the client's reminders and tells them.
                  </Notice>
                )}

                {data.booking.calendar.state === 'failed' && (
                  <Notice
                    tone="danger"
                    title="This booking has no calendar entry"
                    action={
                      <Button small busy={acting === 'calendar'} onClick={retryCalendar}>
                        Retry
                      </Button>
                    }
                  >
                    {data.booking.calendar.error} The appointment still holds its slot here, so nobody else can
                    be booked into it — but it will not appear in anybody's diary until this succeeds.
                  </Notice>
                )}
              </section>

              {/* --- Actions -------------------------------------------- */}
              {(canEdit || canCancel) && nextActions(data.booking.status).length > 0 && (
                <section>
                  <p className="appt-eyebrow">Move it on</p>
                  <div className="appt-actions">
                    {nextActions(data.booking.status).map((action) => (
                      <Button
                        key={action.key}
                        small
                        tone={action.tone}
                        busy={acting === action.key}
                        disabled={action.needsCancel ? !canCancel : !canEdit}
                        onClick={() => transition(action.key)}
                      >
                        {action.label}
                      </Button>
                    ))}
                  </div>
                </section>
              )}

              {/* --- Client --------------------------------------------- */}
              <section>
                <p className="appt-eyebrow">Client</p>

                <dl className="appt-definition-list">
                  <div>
                    <dt>Captured at booking</dt>
                    <dd>
                      {data.client.captured.name || '—'}
                      <div style={{ color: 'var(--muted)', fontSize: 12.5 }}>
                        {[data.client.captured.phone, data.client.captured.email].filter(Boolean).join(' · ') || '—'}
                      </div>
                    </dd>
                  </div>

                  {data.client.contact_message !== null && (
                    <div>
                      <dt>Aicountly Contacts</dt>
                      <dd style={{ color: 'var(--muted)' }}>{data.client.contact_message}</dd>
                    </div>
                  )}

                  {data.client.relationship_message !== null && (
                    <div>
                      <dt>Aicountly CRM</dt>
                      <dd style={{ color: 'var(--muted)' }}>{data.client.relationship_message}</dd>
                    </div>
                  )}
                </dl>
              </section>

              {/* --- Reminders ------------------------------------------ */}
              {data.reminders.length > 0 && (
                <section>
                  <p className="appt-eyebrow">Reminders</p>
                  <div className="appt-table-wrap">
                    <table className="appt-table">
                      <thead>
                        <tr>
                          <th>When</th>
                          <th>Channel</th>
                          <th>Status</th>
                        </tr>
                      </thead>
                      <tbody>
                        {data.reminders.map((reminder) => (
                          <tr key={reminder.reminder_uuid}>
                            <td>{formatDateTime(reminder.scheduled_for, timezone)}</td>
                            <td>{reminder.channel}</td>
                            <td>
                              <StatusPill
                                status={
                                  reminder.status === 'sent' || reminder.status === 'delivered'
                                    ? 'COMPLETED'
                                    : reminder.status === 'failed'
                                      ? 'NO_SHOW'
                                      : 'PENDING'
                                }
                              >
                                {reminder.status.replace(/_/g, ' ')}
                              </StatusPill>
                              {reminder.status_detail && (
                                <div style={{ color: 'var(--muted)', fontSize: 11.5 }}>{reminder.status_detail}</div>
                              )}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </section>
              )}

              {/* --- History -------------------------------------------- */}
              {data.history.length > 0 && (
                <section>
                  <p className="appt-eyebrow">This appointment has been moved</p>
                  <ul className="appt-evidence">
                    {data.history.map((entry) => (
                      <li key={entry.booking_uuid}>
                        <strong>{entry.reference}</strong>
                        <span>
                          {' '}
                          · {formatDateTime(entry.starts_at, timezone)} · {statusLabel(entry.status)}
                          {entry.is_current ? ' · this one' : ''}
                        </span>
                      </li>
                    ))}
                  </ul>
                </section>
              )}

              {/* --- Cancel -------------------------------------------- */}
              {canCancel && ['PENDING', 'CONFIRMED', 'ARRIVED'].includes(data.booking.status) && (
                <section>
                  <p className="appt-eyebrow">Cancel</p>
                  <Field
                    label="Reason"
                    hint="Required. Cancellations with no reason are rows nobody can learn anything from."
                  >
                    <input
                      className="appt-input"
                      value={cancelReason}
                      onChange={(event) => setCancelReason(event.target.value)}
                      placeholder="Client called to cancel"
                    />
                  </Field>
                  <Button tone="danger" onClick={cancel} busy={cancelling} style={{ marginTop: 10 }}>
                    Cancel this appointment
                  </Button>
                </section>
              )}

              {data.booking.references.connect_join_url && (
                <Button
                  onClick={() => window.open(data.booking.references.connect_join_url!, '_blank', 'noopener')}
                >
                  <ExternalLink size={14} aria-hidden />
                  Join the meeting
                </Button>
              )}
            </>
          )
        })()
      )}
    </Drawer>
  )
}

function nextActions(
  status: BookingStatus,
): { key: string; label: string; tone?: 'primary' | 'danger'; needsCancel?: boolean }[] {
  switch (status) {
    case 'PENDING':
      return [
        { key: 'confirm', label: 'Confirm', tone: 'primary' },
        { key: 'arrive', label: 'Mark arrived' },
        { key: 'no-show', label: 'No-show', needsCancel: true },
      ]
    case 'CONFIRMED':
      return [
        { key: 'arrive', label: 'Mark arrived', tone: 'primary' },
        { key: 'start', label: 'Start' },
        { key: 'no-show', label: 'No-show', needsCancel: true },
      ]
    case 'ARRIVED':
      return [
        { key: 'start', label: 'Start', tone: 'primary' },
        { key: 'complete', label: 'Complete' },
      ]
    case 'IN_PROGRESS':
      return [{ key: 'complete', label: 'Complete', tone: 'primary' }]
    case 'NO_SHOW':
      return [{ key: 'complete', label: 'They turned up after all' }]
    default:
      return []
  }
}
