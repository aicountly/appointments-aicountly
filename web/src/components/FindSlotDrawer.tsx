/**
 * Find a slot.
 *
 * ## The natural-language box
 *
 * "45 minutes with a senior consultant tomorrow afternoon" maps onto OUR
 * filters, from a vocabulary WE supply. The model chooses between values that
 * already exist here; it cannot invent a service, a practitioner or a date, so
 * the worst a garbled or hostile phrase achieves is a search that finds
 * nothing.
 *
 * With no model configured the box still works — the backend falls back to
 * keyword matching, which handles "tax consultation tomorrow" perfectly well.
 * The response says which it was and this screen repeats it, because "the AI
 * understood you" and "we matched a word" are different promises.
 *
 * ## It is a search, not a booking flow
 *
 * Choosing a slot here opens the booking drawer with it pre-filled. Holding and
 * confirming happen there, once, rather than in two places that could drift.
 */

import { useCallback, useState } from 'react'
import { Sparkles, Wand2 } from 'lucide-react'
import { api, ApiError } from '../services/api'
import type { Service, Slot, SlotsResponse } from '../services/types'
import { useApi } from '../hooks/useApi'
import { useAppointments } from '../context/AppointmentsContext'
import { Button, Drawer, EmptyState, Field, LoadingRows, Notice, formatDate } from '../ui'
import { todayInZone } from './NewAppointmentDrawer'

interface Interpreted {
  service_uuid: string | null
  service_name: string | null
  member_uuid: string | null
  member_label: string | null
  daypart: string | null
  from: string
  to: string
  when: string | null
}

export function FindSlotDrawer({
  onClose,
  onBook,
}: {
  onClose: () => void
  onBook: (choice: { serviceUuid: string; memberUuid: string | null; startsAt: string }) => void
}) {
  const { timezone, feature } = useAppointments()

  const [phrase, setPhrase] = useState('')
  const [interpreting, setInterpreting] = useState(false)
  const [origin, setOrigin] = useState<'ai' | 'keywords' | null>(null)

  const [serviceUuid, setServiceUuid] = useState('')
  const [memberUuid, setMemberUuid] = useState('')
  const [daypart, setDaypart] = useState('')
  const [from, setFrom] = useState(() => todayInZone(timezone))
  const [days, setDays] = useState(7)

  const [results, setResults] = useState<SlotsResponse | null>(null)
  const [searching, setSearching] = useState(false)
  const [searchError, setSearchError] = useState<ApiError | null>(null)

  const services = useApi<{ data: { services: Service[] } }>(
    (signal) => api.get('v1/services', undefined, signal),
    [],
  )

  const serviceList = services.data?.data.services ?? []

  const search = useCallback(
    async (override?: Partial<Interpreted>) => {
      const useService = override?.service_uuid ?? serviceUuid
      if (!useService) return

      setSearching(true)
      setSearchError(null)

      const start = override?.from ?? `${from}T00:00:00`
      const end = override?.to ?? addDays(from, days)

      try {
        const payload = await api.get<{ data: SlotsResponse }>('v1/availability/slots', {
          service_uuid: useService,
          member_uuid: override?.member_uuid ?? memberUuid ?? undefined,
          daypart: override?.daypart ?? daypart ?? undefined,
          from: start,
          to: end,
          limit: 120,
        })
        setResults(payload.data)
      } catch (error) {
        setResults(null)
        setSearchError(error instanceof ApiError ? error : new ApiError(0, 'error', (error as Error).message))
      } finally {
        setSearching(false)
      }
    },
    [serviceUuid, memberUuid, daypart, from, days],
  )

  async function interpret(): Promise<void> {
    if (phrase.trim() === '') return

    setInterpreting(true)
    setSearchError(null)

    try {
      const payload = await api.get<{ data: { interpreted: Interpreted; origin: 'ai' | 'keywords' } }>(
        'v1/availability/interpret',
        { q: phrase.trim() },
      )

      const { interpreted, origin: source } = payload.data
      setOrigin(source)

      if (interpreted.service_uuid) setServiceUuid(interpreted.service_uuid)
      if (interpreted.member_uuid) setMemberUuid(interpreted.member_uuid)
      setDaypart(interpreted.daypart ?? '')
      setFrom(interpreted.from.slice(0, 10))

      await search(interpreted)
    } catch (error) {
      setSearchError(error instanceof ApiError ? error : new ApiError(0, 'error', (error as Error).message))
    } finally {
      setInterpreting(false)
    }
  }

  const aiEnabled = feature('ai').enabled

  return (
    <Drawer
      title="Find a slot"
      subtitle="Search across services, practitioners and days."
      onClose={onClose}
      wide
      footer={
        <>
          <Button onClick={onClose}>Close</Button>
          <Button tone="primary" onClick={() => search()} busy={searching} disabled={!serviceUuid}>
            Search
          </Button>
        </>
      }
    >
      <Field
        label="Describe what you need"
        hint={
          aiEnabled
            ? 'For example: 45 minutes with a senior consultant tomorrow afternoon.'
            : 'No AI provider is configured, so this matches on service names and words like "tomorrow".'
        }
      >
        <div className="appt-row" style={{ gap: 8, flexWrap: 'nowrap' }}>
          <input
            className="appt-input"
            value={phrase}
            onChange={(event) => setPhrase(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                event.preventDefault()
                interpret()
              }
            }}
            placeholder="Tax consultation, tomorrow afternoon"
          />
          <Button onClick={interpret} busy={interpreting} disabled={phrase.trim() === ''}>
            <Wand2 size={15} aria-hidden />
            Interpret
          </Button>
        </div>
      </Field>

      {origin !== null && (
        <p style={{ color: 'var(--muted)', fontSize: 12, margin: 0, display: 'flex', alignItems: 'center', gap: 6 }}>
          <Sparkles size={13} aria-hidden />
          {origin === 'ai'
            ? 'Interpreted by the AI provider configured for this deployment. The filters below are this product’s own — a model cannot invent a service or a practitioner.'
            : 'Matched on keywords. No AI provider is configured for this deployment.'}
        </p>
      )}

      <div className="appt-grid appt-grid-2" style={{ marginBottom: 0 }}>
        <Field label="Service">
          <select className="appt-select" value={serviceUuid} onChange={(event) => setServiceUuid(event.target.value)}>
            <option value="">Choose a service</option>
            {serviceList.map((service) => (
              <option key={service.service_uuid} value={service.service_uuid}>
                {service.name} · {service.duration_minutes} min
              </option>
            ))}
          </select>
        </Field>

        <Field label="Practitioner">
          <select className="appt-select" value={memberUuid} onChange={(event) => setMemberUuid(event.target.value)}>
            <option value="">Any available</option>
            {(results?.members ?? []).map((member) => (
              <option key={member.member_uuid} value={member.member_uuid}>
                {member.display_label ?? 'Team member'}
                {member.job_title ? ` · ${member.job_title}` : ''}
              </option>
            ))}
          </select>
        </Field>
      </div>

      <div className="appt-grid appt-grid-3" style={{ marginBottom: 0 }}>
        <Field label="From">
          <input
            className="appt-input"
            type="date"
            value={from}
            min={todayInZone(timezone)}
            onChange={(event) => setFrom(event.target.value)}
          />
        </Field>

        <Field label="Days to search">
          <select className="appt-select" value={days} onChange={(event) => setDays(Number(event.target.value))}>
            <option value={1}>1 day</option>
            <option value={3}>3 days</option>
            <option value={7}>1 week</option>
            <option value={14}>2 weeks</option>
            <option value={30}>1 month</option>
          </select>
        </Field>

        <Field label="Time of day">
          <select className="appt-select" value={daypart} onChange={(event) => setDaypart(event.target.value)}>
            <option value="">Any</option>
            <option value="morning">Morning</option>
            <option value="afternoon">Afternoon</option>
            <option value="evening">Evening</option>
          </select>
        </Field>
      </div>

      {searchError !== null && (
        <Notice
          tone={searchError.isCalendarUnavailable ? 'warning' : 'danger'}
          title={
            searchError.isCalendarUnavailable
              ? 'Calendar availability is temporarily unavailable'
              : 'The search did not run'
          }
          action={
            searchError.retryable ? (
              <Button small onClick={() => search()}>
                Retry
              </Button>
            ) : undefined
          }
        >
          {searchError.message}
        </Notice>
      )}

      {searching ? (
        <LoadingRows rows={3} height={52} />
      ) : results === null ? (
        <EmptyState title="Search to see what is free">
          Pick a service and a date range, or describe what you need above.
        </EmptyState>
      ) : results.slots.length === 0 ? (
        <EmptyState title="Nothing free in that window">
          Widen the date range, try another practitioner, or add the client to the waitlist.
        </EmptyState>
      ) : (
        <div className="appt-stack">
          {groupByDay(results.slots).map((day) => (
            <div key={day.date}>
              <p style={{ margin: '0 0 8px', fontSize: 12, fontWeight: 650, color: 'var(--muted)' }}>
                {formatDate(`${day.date}T12:00:00`, timezone)}
              </p>
              <div className="appt-slot-grid">
                {day.slots.map((slot) => (
                  <button
                    key={`${slot.member_uuid}-${slot.starts_at}`}
                    type="button"
                    className="appt-slot"
                    onClick={() =>
                      onBook({
                        serviceUuid: results.service.service_uuid,
                        memberUuid: slot.member_uuid,
                        startsAt: slot.starts_at,
                      })
                    }
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
    </Drawer>
  )
}

function groupByDay(slots: Slot[]): { date: string; slots: Slot[] }[] {
  const byDate = new Map<string, Slot[]>()

  for (const slot of slots) {
    const existing = byDate.get(slot.local_date)
    if (existing) existing.push(slot)
    else byDate.set(slot.local_date, [slot])
  }

  return [...byDate.entries()]
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([date, entries]) => ({ date, slots: entries }))
}

function addDays(date: string, days: number): string {
  const parsed = new Date(`${date}T00:00:00Z`)
  parsed.setUTCDate(parsed.getUTCDate() + days)
  return `${parsed.toISOString().slice(0, 10)}T23:59:59`
}
