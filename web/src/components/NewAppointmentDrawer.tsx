/**
 * Book an appointment.
 *
 * ## A drawer, not a wizard
 *
 * One panel with the fields that matter and the slots underneath them.
 * Receptionists book while somebody is on the phone; five screens to collect
 * five fields turns a thirty-second task into a two-minute one.
 *
 * ## The hold
 *
 * Choosing a slot claims it for a few minutes. Two people looking at the same
 * 3pm is normal, and without the hold the second one finds out after filling
 * in the form — or does not find out, and two of them arrive. The countdown is
 * shown because a claim the user cannot see is a claim they will be surprised
 * by.
 *
 * ## When Calendar cannot be read
 *
 * No slots and a plain explanation, with the Book button disabled. Not the
 * rule-derived slots with a warning: somebody would book one.
 */

import { useCallback, useEffect, useMemo, useState } from 'react'
import { AlertTriangle, Search, UserPlus } from 'lucide-react'
import { api, ApiError } from '../services/api'
import type { AppointmentMode, Booking, Service, Slot, SlotHold, SlotsResponse } from '../services/types'
import { useAppointments } from '../context/AppointmentsContext'
import { Button, Drawer, Field, EmptyState, LoadingRows, Notice, formatDate } from '../ui'

interface ContactOption {
  contact_uuid: string | null
  label: string
  phone: string
  email: string
}

export function NewAppointmentDrawer({
  onClose,
  onBooked,
  initial,
}: {
  onClose: () => void
  onBooked: (bookingUuid: string) => void
  initial?: {
    serviceUuid?: string
    memberUuid?: string | null
    startsAt?: string
    waitlistUuid?: string
    contactUuid?: string | null
    clientName?: string
  }
}) {
  const { timezone, feature } = useAppointments()

  const [services, setServices] = useState<Service[]>([])
  const [servicesError, setServicesError] = useState<string | null>(null)
  const [serviceUuid, setServiceUuid] = useState(initial?.serviceUuid ?? '')
  const [memberUuid, setMemberUuid] = useState(initial?.memberUuid ?? '')
  const [mode, setMode] = useState<AppointmentMode | ''>('')
  const [date, setDate] = useState(() => todayInZone(timezone))

  const [client, setClient] = useState<ContactOption>({
    contact_uuid: initial?.contactUuid ?? null,
    label: initial?.clientName ?? '',
    phone: '',
    email: '',
  })
  const [clientQuery, setClientQuery] = useState('')
  const [clientResults, setClientResults] = useState<ContactOption[]>([])
  const [clientSearchNote, setClientSearchNote] = useState<string | null>(null)
  const [creatingClient, setCreatingClient] = useState(false)

  const [slots, setSlots] = useState<SlotsResponse | null>(null)
  const [slotsLoading, setSlotsLoading] = useState(false)
  const [slotsError, setSlotsError] = useState<ApiError | null>(null)
  const [chosen, setChosen] = useState<Slot | null>(null)

  const [hold, setHold] = useState<SlotHold | null>(null)
  const [holdSecondsLeft, setHoldSecondsLeft] = useState(0)

  const [notes, setNotes] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [submitError, setSubmitError] = useState<ApiError | null>(null)

  const service = services.find((entry) => entry.service_uuid === serviceUuid)

  // --- Services ------------------------------------------------------------
  useEffect(() => {
    const controller = new AbortController()

    api
      .get<{ data: { services: Service[] } }>('v1/services', undefined, controller.signal)
      .then((payload) => {
        if (controller.signal.aborted) return
        const list = payload.data.services
        setServices(list)
        setServicesError(null)
        setServiceUuid((current) => current || (list[0]?.service_uuid ?? ''))
      })
      .catch((err: Error) => {
        if (!controller.signal.aborted) setServicesError(err.message)
      })

    return () => controller.abort()
  }, [])

  // --- Client search -------------------------------------------------------
  useEffect(() => {
    if (clientQuery.trim().length < 2) {
      setClientResults([])
      return
    }

    const controller = new AbortController()
    // Debounced: a receptionist types a name at speed and every keystroke would
    // otherwise be a request to Contacts.
    const timer = window.setTimeout(() => {
      api
        .get<{ data: { contacts: ContactOption[]; source: string; message: string | null } }>(
          'v1/clients/search',
          { q: clientQuery.trim() },
          controller.signal,
        )
        .then((payload) => {
          if (controller.signal.aborted) return
          setClientResults(payload.data.contacts)
          setClientSearchNote(payload.data.message)
        })
        .catch(() => {
          if (!controller.signal.aborted) setClientResults([])
        })
    }, 250)

    return () => {
      controller.abort()
      window.clearTimeout(timer)
    }
  }, [clientQuery])

  // --- Slots ---------------------------------------------------------------
  const loadSlots = useCallback(
    (signal?: AbortSignal) => {
      if (!serviceUuid || !date) return

      setSlotsLoading(true)
      setSlotsError(null)

      const from = `${date}T00:00:00`
      const to = `${date}T23:59:59`

      api
        .get<{ data: SlotsResponse }>(
          'v1/availability/slots',
          { service_uuid: serviceUuid, member_uuid: memberUuid || undefined, from, to, limit: 200 },
          signal,
        )
        .then((payload) => {
          if (signal?.aborted) return
          setSlots(payload.data)
        })
        .catch((err: Error) => {
          if (signal?.aborted) return
          setSlots(null)
          setSlotsError(err instanceof ApiError ? err : new ApiError(0, 'error', err.message))
        })
        .finally(() => {
          if (!signal?.aborted) setSlotsLoading(false)
        })
    },
    [serviceUuid, memberUuid, date],
  )

  useEffect(() => {
    const controller = new AbortController()
    setChosen(null)
    releaseHold()
    loadSlots(controller.signal)
    return () => controller.abort()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [loadSlots])

  // --- Hold countdown ------------------------------------------------------
  useEffect(() => {
    if (hold === null) return

    const tick = () => {
      const remaining = Math.max(0, Math.round((new Date(hold.expires_at).getTime() - Date.now()) / 1000))
      setHoldSecondsLeft(remaining)
      if (remaining === 0) {
        // Expired. The slot is somebody else's again, and pretending otherwise
        // would fail at submit with a message about a slot the user thought
        // they had.
        setHold(null)
        setChosen(null)
        loadSlots()
      }
    }

    tick()
    const timer = window.setInterval(tick, 1000)
    return () => window.clearInterval(timer)
  }, [hold, loadSlots])

  function releaseHold(): void {
    setHold((current) => {
      if (current !== null) {
        // Fire and forget: the hold expires by itself, so failing to release it
        // early costs a few minutes of one slot and nothing else.
        api.del(`v1/availability/holds/${current.hold_uuid}`).catch(() => undefined)
      }
      return null
    })
    setHoldSecondsLeft(0)
  }

  async function chooseSlot(slot: Slot): Promise<void> {
    setSubmitError(null)
    releaseHold()
    setChosen(slot)

    try {
      const response = await api.post<SlotHold>('v1/availability/holds', {
        service_uuid: serviceUuid,
        member_uuid: slot.member_uuid,
        resource_uuids: slot.resource_uuids,
        starts_at: slot.starts_at,
        ends_at: slot.ends_at,
        bo_id: slot.bo_id,
      })
      setHold(response.data)
    } catch (error) {
      setChosen(null)
      setSubmitError(error instanceof ApiError ? error : new ApiError(0, 'error', (error as Error).message))
      loadSlots()
    }
  }

  async function createClient(): Promise<void> {
    setCreatingClient(true)
    setSubmitError(null)

    try {
      const response = await api.post<{ contact_uuid: string | null; message: string | null }>('v1/clients', {
        name: client.label,
        phone: client.phone,
        email: client.email,
      })
      setClient((current) => ({ ...current, contact_uuid: response.data.contact_uuid }))
      setClientSearchNote(response.data.message)
    } catch (error) {
      setSubmitError(error instanceof ApiError ? error : new ApiError(0, 'error', (error as Error).message))
    } finally {
      setCreatingClient(false)
    }
  }

  async function book(): Promise<void> {
    if (chosen === null) return

    setSubmitting(true)
    setSubmitError(null)

    try {
      const response = await api.post<{ booking: Booking }>('v1/bookings', {
        service_uuid: serviceUuid,
        member_uuid: chosen.member_uuid,
        starts_at: chosen.starts_at,
        ends_at: chosen.ends_at,
        bo_id: chosen.bo_id,
        mode: mode || undefined,
        contact_uuid: client.contact_uuid,
        client_name: client.label,
        client_phone: client.phone,
        client_email: client.email,
        notes,
        hold_uuid: hold?.hold_uuid,
        waitlist_uuid: initial?.waitlistUuid,
      })

      onBooked(response.data.booking.booking_uuid)
    } catch (error) {
      const apiError = error instanceof ApiError ? error : new ApiError(0, 'error', (error as Error).message)
      setSubmitError(apiError)

      // Somebody took it first. Clear the choice and show what is left rather
      // than leaving a dead slot selected.
      if (apiError.isSlotTaken) {
        setChosen(null)
        setHold(null)
        loadSlots()
      }
    } finally {
      setSubmitting(false)
    }
  }

  const clientReady = client.label.trim() !== '' && (client.phone.trim() !== '' || client.email.trim() !== '')
  const canBook = chosen !== null && clientReady && !submitting

  const grouped = useMemo(() => groupByDaypart(slots?.slots ?? []), [slots])

  return (
    <Drawer
      title="New appointment"
      subtitle={service ? `${service.name} · ${service.duration_minutes} minutes` : undefined}
      onClose={() => {
        releaseHold()
        onClose()
      }}
      footer={
        <>
          {hold !== null && (
            <span style={{ marginRight: 'auto', color: 'var(--muted)', fontSize: 12.5, alignSelf: 'center' }}>
              Slot held for {formatCountdown(holdSecondsLeft)}
            </span>
          )}
          <Button
            onClick={() => {
              releaseHold()
              onClose()
            }}
          >
            Cancel
          </Button>
          <Button tone="primary" onClick={book} disabled={!canBook} busy={submitting}>
            Book appointment
          </Button>
        </>
      }
    >
      {servicesError !== null && (
        <Notice tone="danger" title="Services could not be loaded">
          {servicesError}
        </Notice>
      )}

      {submitError !== null && (
        <Notice
          tone={submitError.isCalendarUnavailable ? 'warning' : 'danger'}
          title={
            submitError.isSlotTaken
              ? 'That slot was taken'
              : submitError.isCalendarUnavailable
                ? 'Availability could not be confirmed'
                : 'The booking did not go through'
          }
          action={
            submitError.retryable ? (
              <Button small onClick={() => loadSlots()}>
                Refresh slots
              </Button>
            ) : undefined
          }
        >
          {submitError.message}
        </Notice>
      )}

      {/* --- Client ------------------------------------------------------ */}
      <fieldset style={{ border: 0, padding: 0, margin: 0 }}>
        <legend className="appt-eyebrow">Client</legend>

        <Field label="Search or add a client">
          <div style={{ position: 'relative' }}>
            <Search
              size={15}
              aria-hidden
              style={{ position: 'absolute', left: 12, top: 14, color: 'var(--muted)' }}
            />
            <input
              className="appt-input"
              style={{ paddingLeft: 34 }}
              value={clientQuery || client.label}
              placeholder="Name, phone or email"
              onChange={(event) => {
                setClientQuery(event.target.value)
                setClient((current) => ({ ...current, label: event.target.value, contact_uuid: null }))
              }}
            />
          </div>
        </Field>

        {clientResults.length > 0 && (
          <ul style={{ listStyle: 'none', margin: '8px 0 0', padding: 0, display: 'flex', flexDirection: 'column', gap: 4 }}>
            {clientResults.map((option) => (
              <li key={`${option.contact_uuid}-${option.label}`}>
                <button
                  type="button"
                  className="appt-button"
                  style={{ width: '100%', justifyContent: 'flex-start' }}
                  onClick={() => {
                    setClient(option)
                    setClientQuery('')
                    setClientResults([])
                  }}
                >
                  <strong>{option.label}</strong>
                  <span style={{ color: 'var(--muted)', fontWeight: 400 }}>
                    {option.phone || option.email}
                  </span>
                </button>
              </li>
            ))}
          </ul>
        )}

        {clientSearchNote !== null && (
          <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 8 }}>{clientSearchNote}</p>
        )}

        <div className="appt-grid appt-grid-2" style={{ marginTop: 12, marginBottom: 0 }}>
          <Field label="Phone">
            <input
              className="appt-input"
              value={client.phone}
              onChange={(event) => setClient((current) => ({ ...current, phone: event.target.value }))}
              placeholder="+91…"
              inputMode="tel"
            />
          </Field>
          <Field label="Email">
            <input
              className="appt-input"
              type="email"
              value={client.email}
              onChange={(event) => setClient((current) => ({ ...current, email: event.target.value }))}
              placeholder="name@example.com"
            />
          </Field>
        </div>

        {client.contact_uuid === null && clientReady && (
          <div style={{ marginTop: 10 }}>
            <Button small onClick={createClient} busy={creatingClient}>
              <UserPlus size={14} aria-hidden />
              Add to Aicountly Contacts
            </Button>
            <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 6 }}>
              Optional. The appointment can be booked without it and linked to a contact later — Contacts owns
              client identity, so this creates the record there rather than here.
            </p>
          </div>
        )}
      </fieldset>

      {/* --- What and when ------------------------------------------------ */}
      <fieldset style={{ border: 0, padding: 0, margin: 0 }}>
        <legend className="appt-eyebrow">Appointment</legend>

        <div className="appt-grid appt-grid-2" style={{ marginBottom: 12 }}>
          <Field label="Service">
            <select
              className="appt-select"
              value={serviceUuid}
              onChange={(event) => setServiceUuid(event.target.value)}
            >
              {services.map((entry) => (
                <option key={entry.service_uuid} value={entry.service_uuid}>
                  {entry.name} · {entry.duration_minutes} min
                </option>
              ))}
            </select>
          </Field>

          <Field label="Practitioner">
            <select
              className="appt-select"
              value={memberUuid}
              onChange={(event) => setMemberUuid(event.target.value)}
            >
              <option value="">Any available</option>
              {(slots?.members ?? []).map((member) => (
                <option key={member.member_uuid} value={member.member_uuid}>
                  {member.display_label ?? 'Team member'}
                </option>
              ))}
            </select>
          </Field>
        </div>

        <div className="appt-grid appt-grid-2" style={{ marginBottom: 12 }}>
          <Field label="Date">
            <input
              className="appt-input"
              type="date"
              value={date}
              min={todayInZone(timezone)}
              onChange={(event) => setDate(event.target.value)}
            />
          </Field>

          <Field
            label="Mode"
            hint={
              service?.modes.includes('AICOUNTLY_CONNECT') && !feature('connect').enabled
                ? 'Aicountly Connect is not available yet, so a video appointment will be booked as a phone call.'
                : undefined
            }
          >
            <select
              className="appt-select"
              value={mode}
              onChange={(event) => setMode(event.target.value as AppointmentMode)}
            >
              {(service?.modes ?? ['IN_PERSON']).map((option) => (
                <option key={option} value={option}>
                  {MODE_LABELS[option] ?? option}
                </option>
              ))}
            </select>
          </Field>
        </div>

        {service?.deposit_required && !feature('pay').enabled && (
          <Notice tone="warning" title="This service normally takes a deposit">
            Aicountly Pay integration is not enabled yet, so no deposit will be requested. The appointment can
            still be booked from here by a member of staff.
          </Notice>
        )}
      </fieldset>

      {/* --- Slots -------------------------------------------------------- */}
      <fieldset style={{ border: 0, padding: 0, margin: 0 }}>
        <legend className="appt-eyebrow">Available slots · {formatDate(`${date}T12:00:00`, timezone)}</legend>

        {slotsLoading ? (
          <LoadingRows rows={2} height={44} />
        ) : slotsError !== null ? (
          <Notice
            tone="warning"
            title={
              slotsError.isCalendarUnavailable
                ? 'Calendar availability is temporarily unavailable'
                : 'Slots could not be loaded'
            }
            action={
              <Button small onClick={() => loadSlots()}>
                Retry
              </Button>
            }
          >
            {slotsError.message}
            {slotsError.isCalendarUnavailable &&
              ' Appointments will not offer a slot it cannot confirm — booking one that turned out to be taken is worse than waiting.'}
          </Notice>
        ) : (slots?.slots.length ?? 0) === 0 ? (
          <EmptyState title="Nothing free on this day">
            Try another date, a different practitioner, or add the client to the waitlist.
          </EmptyState>
        ) : (
          <div className="appt-stack">
            {grouped.map((group) => (
              <div key={group.daypart}>
                <p style={{ margin: '0 0 8px', fontSize: 12, fontWeight: 650, color: 'var(--muted)' }}>
                  {group.label}
                </p>
                <div className="appt-slot-grid">
                  {group.slots.map((slot) => (
                    <button
                      key={`${slot.member_uuid}-${slot.starts_at}`}
                      type="button"
                      className="appt-slot"
                      aria-pressed={chosen?.starts_at === slot.starts_at && chosen?.member_uuid === slot.member_uuid}
                      onClick={() => chooseSlot(slot)}
                      title={slot.member_label ? `with ${slot.member_label}` : undefined}
                    >
                      {slot.local_time}
                    </button>
                  ))}
                </div>
              </div>
            ))}
          </div>
        )}
      </fieldset>

      {chosen !== null && hold === null && (
        <Notice tone="warning" title="That slot is no longer held">
          <AlertTriangle size={14} aria-hidden /> Choose it again to hold it while you finish.
        </Notice>
      )}

      <Field label="Notes" hint="Visible to the team. Not sent to the client.">
        <textarea
          className="appt-textarea"
          value={notes}
          onChange={(event) => setNotes(event.target.value)}
          placeholder="Anything the practitioner should know"
        />
      </Field>
    </Drawer>
  )
}

const MODE_LABELS: Record<string, string> = {
  IN_PERSON: 'In person',
  PHONE: 'Phone',
  AICOUNTLY_CONNECT: 'Aicountly Connect',
  EXTERNAL_VIDEO: 'External video',
}

function groupByDaypart(slots: Slot[]): { daypart: string; label: string; slots: Slot[] }[] {
  const order = [
    { daypart: 'morning', label: 'Morning' },
    { daypart: 'afternoon', label: 'Afternoon' },
    { daypart: 'evening', label: 'Evening' },
  ]

  return order
    .map((group) => ({ ...group, slots: slots.filter((slot) => slot.daypart === group.daypart) }))
    .filter((group) => group.slots.length > 0)
}

function formatCountdown(seconds: number): string {
  const minutes = Math.floor(seconds / 60)
  const rest = seconds % 60
  return `${minutes}:${String(rest).padStart(2, '0')}`
}

/** Today in the company's zone, not the browser's — they can differ. */
export function todayInZone(timezone: string): string {
  try {
    return new Intl.DateTimeFormat('en-CA', {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      timeZone: timezone,
    }).format(new Date())
  } catch {
    return new Date().toISOString().slice(0, 10)
  }
}
