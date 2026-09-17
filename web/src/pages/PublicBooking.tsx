/**
 * The public booking page — /b/{slug}.
 *
 * ## No session, and none is asked for
 *
 * A client following a booking link has no AICOUNTLY account and never will.
 * These calls carry no Authorization header at all, the company is resolved
 * server-side from the slug, and the protections are rate limiting,
 * revalidation and idempotency rather than a login.
 *
 * ## What it must never do
 *
 * Offer a slot it could not confirm, or take a deposit-required booking while
 * Aicountly Pay is off. Both would end with a client who believes they have an
 * appointment they do not have.
 *
 * ## It is styled but not branded as the app
 *
 * This is the client's view of the business, not of Appointments. The shell,
 * the launcher and the company switcher are all absent on purpose.
 */

import { useCallback, useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { CalendarCheck, CheckCircle2, ChevronLeft } from 'lucide-react'
import { publicApi, ApiError } from '../services/api'
import { Button, EmptyState, Field, LoadingRows, Notice } from '../ui'
import '../ui/appointments-ui.css'

interface PublicService {
  service_uuid: string
  name: string
  description: string
  duration_minutes: number
  modes: string[]
  price_minor: number | null
  currency: string
  deposit_required: boolean
  deposit_minor: number | null
  form: { form_uuid: string; name: string; field_count: number } | null
}

interface PublicPage {
  page: {
    slug: string
    kind: string
    headline: string
    intro: string
    brand_colour: string | null
    logo_url: string | null
    terms: string
    timezone: string
    locale: string
    require_client_phone: boolean
    require_client_email: boolean
  }
  services: PublicService[]
  payments: { enabled: boolean; message: string | null }
}

interface PublicSlot {
  starts_at: string
  ends_at: string
  local_time: string
  local_date: string
  daypart: string
  duration_minutes: number
  member_uuid: string | null
}

export function PublicBookingPage() {
  const { slug = '' } = useParams()

  const [page, setPage] = useState<PublicPage | null>(null)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState<string | null>(null)

  const [service, setService] = useState<PublicService | null>(null)
  const [slots, setSlots] = useState<PublicSlot[]>([])
  const [slotsLoading, setSlotsLoading] = useState(false)
  const [slotsError, setSlotsError] = useState<ApiError | null>(null)
  const [chosen, setChosen] = useState<PublicSlot | null>(null)

  const [name, setName] = useState('')
  const [phone, setPhone] = useState('')
  const [email, setEmail] = useState('')
  const [notes, setNotes] = useState('')
  const [acceptTerms, setAcceptTerms] = useState(false)

  const [submitting, setSubmitting] = useState(false)
  const [submitError, setSubmitError] = useState<ApiError | null>(null)
  const [confirmed, setConfirmed] = useState<{
    reference: string
    local_time: string
    service_name: string
    status: string
    message: string
  } | null>(null)

  // `booking_started` is recorded so the Client Experience funnel has a real
  // top rather than one inferred from the stage below it.
  const [startedAt] = useState(() => Date.now())

  useEffect(() => {
    let cancelled = false

    publicApi
      .get<{ data: PublicPage }>(`public/pages/${slug}`)
      .then((payload) => {
        if (cancelled) return
        setPage(payload.data)
        if (payload.data.services.length === 1) setService(payload.data.services[0])
      })
      .catch((error: Error) => {
        if (!cancelled) setLoadError(error.message)
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [slug])

  const loadSlots = useCallback(
    (chosenService: PublicService) => {
      setSlotsLoading(true)
      setSlotsError(null)
      setChosen(null)

      publicApi
        .get<{ data: { slots: PublicSlot[] } }>(`public/pages/${slug}/slots`, {
          service_uuid: chosenService.service_uuid,
        })
        .then((payload) => setSlots(payload.data.slots))
        .catch((error: Error) => {
          setSlots([])
          setSlotsError(error instanceof ApiError ? error : new ApiError(0, 'error', error.message))
        })
        .finally(() => setSlotsLoading(false))
    },
    [slug],
  )

  useEffect(() => {
    if (service !== null) loadSlots(service)
  }, [service, loadSlots])

  async function book(): Promise<void> {
    if (service === null || chosen === null) return

    setSubmitting(true)
    setSubmitError(null)

    try {
      const payload = await publicApi.post<{
        data: {
          booking: { reference: string; local_time: string; service_name: string; status: string }
          message: string
        }
      }>(`public/pages/${slug}/bookings`, {
        service_uuid: service.service_uuid,
        starts_at: chosen.starts_at,
        ends_at: chosen.ends_at,
        member_uuid: chosen.member_uuid,
        client_name: name,
        client_phone: phone,
        client_email: email,
        notes,
        accept_terms: acceptTerms,
        booking_started: true,
        slot_selected: true,
        started_seconds: Math.round((Date.now() - startedAt) / 1000),
      })

      setConfirmed({ ...payload.data.booking, message: payload.data.message })
    } catch (error) {
      const apiError = error instanceof ApiError ? error : new ApiError(0, 'error', (error as Error).message)
      setSubmitError(apiError)

      if (apiError.isSlotTaken && service !== null) {
        setChosen(null)
        loadSlots(service)
      }
    } finally {
      setSubmitting(false)
    }
  }

  if (loading) {
    return (
      <main className="appt-ui" style={{ maxWidth: 760, margin: '0 auto', minHeight: '100vh' }}>
        <LoadingRows rows={4} height={72} />
      </main>
    )
  }

  if (loadError !== null || page === null) {
    return (
      <main className="appt-ui" style={{ maxWidth: 560, margin: '0 auto', minHeight: '100vh', paddingTop: 80 }}>
        <EmptyState title="This booking page is not available">
          The link may have changed, or the business may have taken the page down. Please contact them
          directly.
        </EmptyState>
      </main>
    )
  }

  if (confirmed !== null) {
    return (
      <main className="appt-ui" style={{ maxWidth: 560, margin: '0 auto', minHeight: '100vh', paddingTop: 60 }}>
        <div className="appt-panel" style={{ textAlign: 'center' }}>
          <span className="appt-metric-icon" aria-hidden style={{ margin: '0 auto 14px', width: 52, height: 52 }}>
            <CheckCircle2 size={26} />
          </span>
          <h1 style={{ fontSize: 22, margin: '0 0 8px' }}>{confirmed.message}</h1>
          <p style={{ color: 'var(--muted)', margin: '0 0 18px', lineHeight: 1.6 }}>
            {confirmed.service_name} · {confirmed.local_time}
          </p>
          <p style={{ fontSize: 13, color: 'var(--muted)', margin: 0 }}>
            Your reference is <strong>{confirmed.reference}</strong>. Keep it in case you need to get in touch.
          </p>
        </div>
      </main>
    )
  }

  // A business's own colour, applied to the one token that drives accents.
  // Everything else keeps the defaults, because #187900 is what clears contrast
  // for white text and a brand colour that does not is not the button's problem
  // to discover at runtime.
  const shellStyle: React.CSSProperties = {
    maxWidth: 760,
    margin: '0 auto',
    minHeight: '100vh',
    ...(page.page.brand_colour ? ({ '--brand': page.page.brand_colour } as React.CSSProperties) : {}),
  }

  return (
    <main className="appt-ui" style={shellStyle}>
      <header style={{ padding: '32px 0 24px', textAlign: 'center' }}>
        {page.page.logo_url && (
          <img
            src={page.page.logo_url}
            alt=""
            height={48}
            style={{ maxWidth: 200, objectFit: 'contain', marginBottom: 16 }}
          />
        )}
        <h1 style={{ fontSize: 26, margin: '0 0 8px', letterSpacing: '-0.03em' }}>
          {page.page.headline || 'Book an appointment'}
        </h1>
        {page.page.intro && (
          <p style={{ color: 'var(--muted)', margin: 0, lineHeight: 1.6, maxWidth: '58ch', marginInline: 'auto' }}>
            {page.page.intro}
          </p>
        )}
      </header>

      {!page.payments.enabled && page.services.some((entry) => entry.deposit_required) && (
        <Notice tone="info" title="Some services cannot be booked online yet">
          {page.payments.message}
        </Notice>
      )}

      {/* --- Choose a service ------------------------------------------- */}
      {service === null ? (
        <section className="appt-stack">
          <h2 style={{ fontSize: 15, margin: 0 }}>Choose a service</h2>

          {page.services.length === 0 ? (
            <EmptyState title="Nothing can be booked online right now">
              Please contact the business directly.
            </EmptyState>
          ) : (
            page.services.map((entry) => {
              const blocked = entry.deposit_required && !page.payments.enabled

              return (
                <button
                  key={entry.service_uuid}
                  type="button"
                  className="appt-panel"
                  disabled={blocked}
                  onClick={() => setService(entry)}
                  style={{
                    textAlign: 'left',
                    cursor: blocked ? 'not-allowed' : 'pointer',
                    opacity: blocked ? 0.6 : 1,
                    border: '1px solid var(--line)',
                  }}
                >
                  <strong style={{ display: 'block', fontSize: 15.5 }}>{entry.name}</strong>
                  {entry.description && (
                    <p style={{ color: 'var(--muted)', margin: '4px 0 8px', fontSize: 13, lineHeight: 1.55 }}>
                      {entry.description}
                    </p>
                  )}
                  <span style={{ color: 'var(--muted)', fontSize: 12.5 }}>
                    {entry.duration_minutes} minutes
                    {entry.price_minor !== null && ` · ${formatPrice(entry.price_minor, entry.currency)}`}
                    {blocked && ' · requires a deposit, which cannot be taken online yet'}
                  </span>
                </button>
              )
            })
          )}
        </section>
      ) : (
        <>
          <Button small tone="ghost" onClick={() => setService(null)} style={{ marginBottom: 14 }}>
            <ChevronLeft size={14} aria-hidden />
            Choose a different service
          </Button>

          <section className="appt-panel" style={{ marginBottom: 14 }}>
            <strong style={{ display: 'block', fontSize: 15.5 }}>{service.name}</strong>
            <span style={{ color: 'var(--muted)', fontSize: 12.5 }}>
              {service.duration_minutes} minutes · times shown in {page.page.timezone}
            </span>
          </section>

          {/* --- Choose a time ------------------------------------------ */}
          <section className="appt-panel" style={{ marginBottom: 14 }}>
            <h2 style={{ fontSize: 15, margin: '0 0 12px' }}>Choose a time</h2>

            {slotsLoading ? (
              <LoadingRows rows={2} height={44} />
            ) : slotsError !== null ? (
              <Notice
                tone="warning"
                title="Availability is temporarily unavailable"
                action={
                  <Button small onClick={() => loadSlots(service)}>
                    Try again
                  </Button>
                }
              >
                Please try again in a moment. We will not offer a time we cannot confirm.
              </Notice>
            ) : slots.length === 0 ? (
              <EmptyState title="Nothing available in the next two weeks">
                Please contact the business directly to find a time.
              </EmptyState>
            ) : (
              <div className="appt-stack">
                {groupByDay(slots).map((day) => (
                  <div key={day.date}>
                    <p style={{ margin: '0 0 8px', fontSize: 12.5, fontWeight: 650, color: 'var(--muted)' }}>
                      {formatDay(day.date, page.page.timezone)}
                    </p>
                    <div className="appt-slot-grid">
                      {day.slots.map((slot) => (
                        <button
                          key={slot.starts_at}
                          type="button"
                          className="appt-slot"
                          aria-pressed={chosen?.starts_at === slot.starts_at}
                          onClick={() => setChosen(slot)}
                        >
                          {slot.local_time}
                        </button>
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </section>

          {/* --- Your details ------------------------------------------- */}
          {chosen !== null && (
            <section className="appt-panel">
              <h2 style={{ fontSize: 15, margin: '0 0 14px' }}>Your details</h2>

              {submitError !== null && (
                <Notice
                  tone={submitError.isSlotTaken ? 'warning' : 'danger'}
                  title={submitError.isSlotTaken ? 'That time was just taken' : 'The booking did not go through'}
                >
                  {submitError.message}
                </Notice>
              )}

              <div className="appt-stack">
                <Field label="Your name" error={submitError?.fieldErrors.client_name}>
                  <input className="appt-input" value={name} onChange={(event) => setName(event.target.value)} />
                </Field>

                <Field
                  label={page.page.require_client_phone ? 'Phone number' : 'Phone number (optional)'}
                  error={submitError?.fieldErrors.client_phone}
                >
                  <input
                    className="appt-input"
                    value={phone}
                    inputMode="tel"
                    onChange={(event) => setPhone(event.target.value)}
                  />
                </Field>

                <Field
                  label={page.page.require_client_email ? 'Email' : 'Email (optional)'}
                  error={submitError?.fieldErrors.client_email}
                >
                  <input
                    className="appt-input"
                    type="email"
                    value={email}
                    onChange={(event) => setEmail(event.target.value)}
                  />
                </Field>

                <Field label="Anything we should know? (optional)">
                  <textarea
                    className="appt-textarea"
                    value={notes}
                    onChange={(event) => setNotes(event.target.value)}
                  />
                </Field>

                {page.page.terms && (
                  <>
                    <details>
                      <summary style={{ cursor: 'pointer', fontSize: 13, fontWeight: 650 }}>
                        Terms and cancellation policy
                      </summary>
                      <p style={{ color: 'var(--muted)', fontSize: 13, lineHeight: 1.6, whiteSpace: 'pre-wrap' }}>
                        {page.page.terms}
                      </p>
                    </details>

                    <label className="appt-row-tight">
                      <input
                        type="checkbox"
                        checked={acceptTerms}
                        onChange={(event) => setAcceptTerms(event.target.checked)}
                      />
                      <span>I accept the terms above</span>
                    </label>
                    {submitError?.fieldErrors.accept_terms && (
                      <span style={{ color: 'var(--danger)', fontSize: 12 }}>
                        {submitError.fieldErrors.accept_terms}
                      </span>
                    )}
                  </>
                )}

                <Button tone="primary" onClick={book} busy={submitting} style={{ minHeight: 48 }}>
                  <CalendarCheck size={16} aria-hidden />
                  Book {chosen.local_time}
                </Button>
              </div>
            </section>
          )}
        </>
      )}

      <footer style={{ padding: '32px 0', textAlign: 'center', color: 'var(--muted)', fontSize: 12 }}>
        Booking powered by Aicountly Appointments
      </footer>
    </main>
  )
}

function groupByDay(slots: PublicSlot[]): { date: string; slots: PublicSlot[] }[] {
  const byDate = new Map<string, PublicSlot[]>()

  for (const slot of slots) {
    const existing = byDate.get(slot.local_date)
    if (existing) existing.push(slot)
    else byDate.set(slot.local_date, [slot])
  }

  return [...byDate.entries()].sort(([a], [b]) => a.localeCompare(b)).map(([date, entries]) => ({ date, slots: entries }))
}

function formatDay(date: string, timezone: string): string {
  try {
    return new Intl.DateTimeFormat('en-IN', {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      timeZone: timezone,
    }).format(new Date(`${date}T12:00:00Z`))
  } catch {
    return date
  }
}

function formatPrice(minor: number, currency: string): string {
  try {
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency, maximumFractionDigits: 0 }).format(
      minor / 100,
    )
  } catch {
    return `${currency} ${(minor / 100).toFixed(0)}`
  }
}
