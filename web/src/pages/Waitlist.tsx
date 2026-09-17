/**
 * The waitlist.
 *
 * A waitlist nobody acts on is a list of disappointed people. What makes this
 * one worth keeping is that every match carries the REASONS it matched — the
 * service, the practitioner they asked for, the time of day, how long they have
 * waited — so a receptionist has a call to make rather than a ranked mystery.
 *
 * ONE OFFER AT A TIME. Offering a slot to three people and giving it to whoever
 * answers first turns a waitlist into a complaint. An offer holds the slot for
 * one client for a bounded time; when it lapses they go back to waiting.
 */

import { useState } from 'react'
import { Plus } from 'lucide-react'
import { api, ApiError } from '../services/api'
import { useApi } from '../hooks/useApi'
import { useAppointments } from '../context/AppointmentsContext'
import type { Service, WaitlistEntry } from '../services/types'
import { Button, Drawer, Field, Notice, PanelState, StatusPill, formatDate } from '../ui'

export function WaitlistPage() {
  const { can, timezone, feature } = useAppointments()
  const [adding, setAdding] = useState(false)
  const [status, setStatus] = useState('waiting')

  const list = useApi(
    (signal) => api.list<WaitlistEntry>('v1/waitlist', { status: status || undefined, limit: 100 }, signal),
    [status],
  )

  const enabled = feature('waitlist').enabled

  return (
    <div className="appt-ui">
      <header className="appt-page-header">
        <div>
          <h1>Waitlist</h1>
          <p>Clients waiting for a slot, and who matches when one comes free.</p>
        </div>
        {can('appointments.waitlist.manage') && enabled && (
          <Button tone="primary" onClick={() => setAdding(true)}>
            <Plus size={15} aria-hidden />
            Add to waitlist
          </Button>
        )}
      </header>

      {!enabled && (
        <Notice tone="info" title="The waitlist is turned off">
          {feature('waitlist').reason}
        </Notice>
      )}

      <div className="appt-filters">
        <Field label="Status">
          <select className="appt-select" value={status} onChange={(event) => setStatus(event.target.value)}>
            <option value="waiting">Waiting</option>
            <option value="offered">Offered a slot</option>
            <option value="booked">Booked</option>
            <option value="expired">Expired</option>
            <option value="">Everything</option>
          </select>
        </Field>
      </div>

      <PanelState
        loading={list.loading}
        error={list.error}
        data={list.data}
        isEmpty={(data) => data.data.length === 0}
        emptyTitle={status === 'waiting' ? 'Nobody is waiting' : 'Nothing here'}
        emptyBody="When a client wants an appointment you cannot offer yet, add them here. The moment a slot comes free, Appointments works out who it suits and why."
        onRetry={list.reload}
      >
        {(data) => (
          <div className="appt-table-wrap">
            <table className="appt-table">
              <thead>
                <tr>
                  <th>Client</th>
                  <th>Service</th>
                  <th>Wants</th>
                  <th>Window</th>
                  <th>Status</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {data.data.map((entry) => (
                  <tr key={entry.waitlist_uuid}>
                    <td>
                      <strong>{entry.client.name || 'Client'}</strong>
                      <div style={{ color: 'var(--muted)', fontSize: 11.5 }}>
                        {entry.client.phone || entry.client.email || 'No contact details'}
                      </div>
                    </td>
                    <td>{entry.service.name ?? '—'}</td>
                    <td style={{ color: 'var(--muted)', fontSize: 12.5 }}>
                      {entry.daypart === 'any' ? 'Any time' : entry.daypart}
                      {entry.preferred_member && ` · ${entry.preferred_member.label ?? 'a specific practitioner'}`}
                    </td>
                    <td style={{ color: 'var(--muted)', fontSize: 12.5 }}>
                      {formatDate(`${entry.earliest_date}T12:00:00`, timezone)} –{' '}
                      {formatDate(`${entry.latest_date}T12:00:00`, timezone)}
                    </td>
                    <td>
                      <StatusPill
                        status={
                          entry.status === 'booked'
                            ? 'COMPLETED'
                            : entry.status === 'offered'
                              ? 'PENDING'
                              : entry.status === 'waiting'
                                ? 'CONFIRMED'
                                : 'CANCELLED'
                        }
                      >
                        {entry.status}
                      </StatusPill>
                      {entry.offer && (
                        <div style={{ color: 'var(--muted)', fontSize: 11.5 }}>
                          Offered {formatDate(entry.offer.slot_start, timezone)}
                        </div>
                      )}
                    </td>
                    <td style={{ textAlign: 'right' }}>
                      {can('appointments.waitlist.manage') && entry.status === 'waiting' && (
                        <Button
                          small
                          tone="ghost"
                          onClick={async () => {
                            await api.del(`v1/waitlist/${entry.waitlist_uuid}`)
                            list.reload()
                          }}
                        >
                          Remove
                        </Button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </PanelState>

      {adding && (
        <AddToWaitlistDrawer
          onClose={() => setAdding(false)}
          onSaved={() => {
            setAdding(false)
            list.reload()
          }}
        />
      )}
    </div>
  )
}

function AddToWaitlistDrawer({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const { timezone } = useAppointments()

  const [form, setForm] = useState({
    service_uuid: '',
    client_name: '',
    client_phone: '',
    client_email: '',
    earliest_date: todayInZone(timezone),
    latest_date: addMonths(todayInZone(timezone), 1),
    daypart: 'any',
    priority: 0,
    notes: '',
  })

  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)

  const services = useApi(
    (signal) => api.get<{ data: { services: Service[] } }>('v1/services', undefined, signal),
    [],
  )

  const fieldErrors = error?.fieldErrors ?? {}

  async function save(): Promise<void> {
    setSaving(true)
    setError(null)

    try {
      await api.post('v1/waitlist', form)
      onSaved()
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(0, 'error', (err as Error).message))
    } finally {
      setSaving(false)
    }
  }

  return (
    <Drawer
      title="Add to the waitlist"
      subtitle="When a matching slot comes free, this client appears on Live Operations."
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button tone="primary" onClick={save} busy={saving}>
            Add
          </Button>
        </>
      }
    >
      {error !== null && Object.keys(fieldErrors).length === 0 && (
        <Notice tone="danger" title="This could not be saved">
          {error.message}
        </Notice>
      )}

      <Field label="Service" error={fieldErrors.service_uuid}>
        <select
          className="appt-select"
          value={form.service_uuid}
          onChange={(event) => setForm({ ...form, service_uuid: event.target.value })}
        >
          <option value="">Choose a service</option>
          {(services.data?.data.services ?? []).map((service) => (
            <option key={service.service_uuid} value={service.service_uuid}>
              {service.name}
            </option>
          ))}
        </select>
      </Field>

      <Field label="Client name">
        <input
          className="appt-input"
          value={form.client_name}
          onChange={(event) => setForm({ ...form, client_name: event.target.value })}
        />
      </Field>

      <div className="appt-grid appt-grid-2" style={{ marginBottom: 0 }}>
        <Field label="Phone" error={fieldErrors.client_phone}>
          <input
            className="appt-input"
            value={form.client_phone}
            onChange={(event) => setForm({ ...form, client_phone: event.target.value })}
            inputMode="tel"
          />
        </Field>
        <Field label="Email">
          <input
            className="appt-input"
            type="email"
            value={form.client_email}
            onChange={(event) => setForm({ ...form, client_email: event.target.value })}
          />
        </Field>
      </div>

      <p style={{ color: 'var(--muted)', fontSize: 12, margin: 0 }}>
        A phone number or an email address is required — without one there is no way to tell them a slot came
        free, which would make this a row nobody can act on.
      </p>

      <div className="appt-grid appt-grid-2" style={{ marginBottom: 0 }}>
        <Field label="Earliest date" error={fieldErrors.earliest_date}>
          <input
            className="appt-input"
            type="date"
            value={form.earliest_date}
            onChange={(event) => setForm({ ...form, earliest_date: event.target.value })}
          />
        </Field>
        <Field label="Latest date" error={fieldErrors.latest_date}>
          <input
            className="appt-input"
            type="date"
            value={form.latest_date}
            onChange={(event) => setForm({ ...form, latest_date: event.target.value })}
          />
        </Field>
      </div>

      <div className="appt-grid appt-grid-2" style={{ marginBottom: 0 }}>
        <Field label="Time of day">
          <select
            className="appt-select"
            value={form.daypart}
            onChange={(event) => setForm({ ...form, daypart: event.target.value })}
          >
            <option value="any">Any</option>
            <option value="morning">Morning</option>
            <option value="afternoon">Afternoon</option>
            <option value="evening">Evening</option>
          </select>
        </Field>

        <Field label="Priority" hint="Higher comes first when several clients match.">
          <input
            className="appt-input"
            type="number"
            min={0}
            max={100}
            value={form.priority}
            onChange={(event) => setForm({ ...form, priority: Number(event.target.value) })}
          />
        </Field>
      </div>

      <Field label="Notes">
        <textarea
          className="appt-textarea"
          value={form.notes}
          onChange={(event) => setForm({ ...form, notes: event.target.value })}
        />
      </Field>
    </Drawer>
  )
}

function todayInZone(timezone: string): string {
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

function addMonths(date: string, months: number): string {
  const parsed = new Date(`${date}T12:00:00Z`)
  parsed.setUTCMonth(parsed.getUTCMonth() + months)
  return parsed.toISOString().slice(0, 10)
}
