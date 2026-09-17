/**
 * Services — what can be booked, for how long, by whom.
 *
 * DURATION AND BUFFERS ARE THREE NUMBERS. What the client is told, what the
 * diary loses before, and what it loses after. A 60-minute consultation with a
 * 15-minute write-up is a 60-minute appointment that occupies 75, and the form
 * says so where somebody is entering them.
 */

import { useState } from 'react'
import { Plus } from 'lucide-react'
import { api, ApiError } from '../services/api'
import { useApi } from '../hooks/useApi'
import { useAppointments } from '../context/AppointmentsContext'
import type { AppointmentMode, Service } from '../services/types'
import { Button, Drawer, Field, Notice, PanelState, formatMoney } from '../ui'

const MODES: { key: AppointmentMode; label: string }[] = [
  { key: 'IN_PERSON', label: 'In person' },
  { key: 'PHONE', label: 'Phone' },
  { key: 'AICOUNTLY_CONNECT', label: 'Aicountly Connect' },
  { key: 'EXTERNAL_VIDEO', label: 'External video' },
]

export function ServicesPage() {
  const { can, currency, feature } = useAppointments()
  const [editing, setEditing] = useState<Service | 'new' | null>(null)

  const list = useApi(
    (signal) => api.get<{ data: { services: Service[] } }>('v1/services', { include_inactive: '1' }, signal),
    [],
  )

  return (
    <div className="appt-ui">
      <header className="appt-page-header">
        <div>
          <h1>Services</h1>
          <p>What clients can book, and the rules around it.</p>
        </div>
        {can('appointments.services.manage') && (
          <Button tone="primary" onClick={() => setEditing('new')}>
            <Plus size={15} aria-hidden />
            New service
          </Button>
        )}
      </header>

      <PanelState
        loading={list.loading}
        error={list.error}
        data={list.data}
        isEmpty={(data) => data.data.services.length === 0}
        emptyTitle="No services yet"
        emptyBody="A service is what a client books: a name, a duration, and who can deliver it. Add the first one to start taking appointments."
        onRetry={list.reload}
      >
        {(data) => (
          <div className="appt-table-wrap">
            <table className="appt-table">
              <thead>
                <tr>
                  <th>Service</th>
                  <th className="num">Duration</th>
                  <th className="num">Occupies</th>
                  <th className="num">Staff</th>
                  <th>Deposit</th>
                  <th>Online</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {data.data.services.map((service) => (
                  <tr key={service.service_uuid} style={service.is_active ? undefined : { opacity: 0.55 }}>
                    <td>
                      <strong>{service.name}</strong>
                      <div style={{ color: 'var(--muted)', fontSize: 11.5 }}>
                        {service.category}
                        {!service.is_active && ' · retired'}
                        {service.price_minor !== null && ` · ${formatMoney(service.price_minor, service.currency)}`}
                      </div>
                    </td>
                    <td className="num">{service.duration_minutes} min</td>
                    <td className="num" title="Duration plus buffers — what the diary actually loses.">
                      {service.duration_minutes + service.buffer_before_mins + service.buffer_after_mins} min
                    </td>
                    <td className="num">{service.staff_count ?? 0}</td>
                    <td>
                      {service.deposit_required ? (
                        <span className="appt-status appt-status-warning">
                          {service.deposit_minor !== null ? formatMoney(service.deposit_minor, service.currency) : 'Required'}
                        </span>
                      ) : (
                        <span style={{ color: 'var(--muted)' }}>—</span>
                      )}
                    </td>
                    <td>
                      {service.is_bookable_online ? (
                        <span className="appt-status appt-status-success">Bookable</span>
                      ) : (
                        <span className="appt-status appt-status-neutral">Staff only</span>
                      )}
                    </td>
                    <td style={{ textAlign: 'right' }}>
                      {can('appointments.services.manage') && (
                        <Button small onClick={() => setEditing(service)}>
                          Edit
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

      {editing !== null && (
        <ServiceDrawer
          service={editing === 'new' ? null : editing}
          currency={currency}
          payEnabled={feature('pay').enabled}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            list.reload()
          }}
        />
      )}
    </div>
  )
}

function ServiceDrawer({
  service,
  currency,
  payEnabled,
  onClose,
  onSaved,
}: {
  service: Service | null
  currency: string
  payEnabled: boolean
  onClose: () => void
  onSaved: () => void
}) {
  const [form, setForm] = useState(() => ({
    name: service?.name ?? '',
    description: service?.description ?? '',
    category: service?.category ?? 'general',
    duration_minutes: service?.duration_minutes ?? 30,
    buffer_before_mins: service?.buffer_before_mins ?? 0,
    buffer_after_mins: service?.buffer_after_mins ?? 0,
    min_notice_minutes: service?.min_notice_minutes ?? 120,
    booking_horizon_days: service?.booking_horizon_days ?? 60,
    capacity: service?.capacity ?? 1,
    modes: service?.modes ?? (['IN_PERSON'] as AppointmentMode[]),
    price_minor: service?.price_minor ?? null,
    deposit_required: service?.deposit_required ?? false,
    deposit_minor: service?.deposit_minor ?? null,
    is_active: service?.is_active ?? true,
    is_bookable_online: service?.is_bookable_online ?? true,
  }))

  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)

  const fieldErrors = error?.fieldErrors ?? {}

  async function save(): Promise<void> {
    setSaving(true)
    setError(null)

    try {
      if (service === null) {
        await api.post('v1/services', form)
      } else {
        await api.put(`v1/services/${service.service_uuid}`, form)
      }
      onSaved()
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(0, 'error', (err as Error).message))
    } finally {
      setSaving(false)
    }
  }

  const occupies = form.duration_minutes + form.buffer_before_mins + form.buffer_after_mins

  return (
    <Drawer
      title={service === null ? 'New service' : service.name}
      subtitle="What a client books, and the rules around it."
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button tone="primary" onClick={save} busy={saving}>
            Save service
          </Button>
        </>
      }
    >
      {error !== null && Object.keys(fieldErrors).length === 0 && (
        <Notice tone="danger" title="This could not be saved">
          {error.message}
        </Notice>
      )}

      <Field label="Name" error={fieldErrors.name}>
        <input
          className="appt-input"
          value={form.name}
          onChange={(event) => setForm({ ...form, name: event.target.value })}
          placeholder="Tax consultation"
        />
      </Field>

      <Field label="Description" hint="Shown on the public booking page.">
        <textarea
          className="appt-textarea"
          value={form.description}
          onChange={(event) => setForm({ ...form, description: event.target.value })}
        />
      </Field>

      <div className="appt-grid appt-grid-3" style={{ marginBottom: 0 }}>
        <Field label="Duration (minutes)" hint="What the client is told." error={fieldErrors.duration_minutes}>
          <input
            className="appt-input"
            type="number"
            min={5}
            max={1440}
            value={form.duration_minutes}
            onChange={(event) => setForm({ ...form, duration_minutes: Number(event.target.value) })}
          />
        </Field>

        <Field label="Buffer before" hint="Setup time." error={fieldErrors.buffer_before_mins}>
          <input
            className="appt-input"
            type="number"
            min={0}
            max={240}
            value={form.buffer_before_mins}
            onChange={(event) => setForm({ ...form, buffer_before_mins: Number(event.target.value) })}
          />
        </Field>

        <Field label="Buffer after" hint="Write-up, cleaning." error={fieldErrors.buffer_after_mins}>
          <input
            className="appt-input"
            type="number"
            min={0}
            max={240}
            value={form.buffer_after_mins}
            onChange={(event) => setForm({ ...form, buffer_after_mins: Number(event.target.value) })}
          />
        </Field>
      </div>

      <Notice tone="info" title={`This occupies ${occupies} minutes of the diary`}>
        The client is told {form.duration_minutes} minutes. Buffers are why those are different numbers —
        telling a client 75 minutes would be wrong, and booking the next one at +{form.duration_minutes} would
        be too.
      </Notice>

      <div className="appt-grid appt-grid-2" style={{ marginBottom: 0 }}>
        <Field label="Minimum notice (minutes)" hint="How soon a client may book.">
          <input
            className="appt-input"
            type="number"
            min={0}
            value={form.min_notice_minutes}
            onChange={(event) => setForm({ ...form, min_notice_minutes: Number(event.target.value) })}
          />
        </Field>

        <Field label="Booking horizon (days)" hint="How far ahead a client may book.">
          <input
            className="appt-input"
            type="number"
            min={1}
            max={730}
            value={form.booking_horizon_days}
            onChange={(event) => setForm({ ...form, booking_horizon_days: Number(event.target.value) })}
          />
        </Field>
      </div>

      <fieldset style={{ border: 0, padding: 0, margin: 0 }}>
        <legend className="appt-eyebrow">Appointment modes</legend>
        <div className="appt-row">
          {MODES.map((mode) => (
            <button
              key={mode.key}
              type="button"
              className="appt-chip"
              aria-pressed={form.modes.includes(mode.key)}
              onClick={() =>
                setForm({
                  ...form,
                  modes: form.modes.includes(mode.key)
                    ? form.modes.filter((entry) => entry !== mode.key)
                    : [...form.modes, mode.key],
                })
              }
            >
              {mode.label}
            </button>
          ))}
        </div>
        {fieldErrors.modes && <p style={{ color: 'var(--danger)', fontSize: 12 }}>{fieldErrors.modes}</p>}
      </fieldset>

      <fieldset style={{ border: 0, padding: 0, margin: 0 }}>
        <legend className="appt-eyebrow">Price and deposit</legend>

        <div className="appt-grid appt-grid-2" style={{ marginBottom: 0 }}>
          <Field label={`Display price (${currency})`} hint="Shown to clients. Not a charge.">
            <input
              className="appt-input"
              type="number"
              min={0}
              value={form.price_minor === null ? '' : form.price_minor / 100}
              onChange={(event) =>
                setForm({
                  ...form,
                  price_minor: event.target.value === '' ? null : Math.round(Number(event.target.value) * 100),
                })
              }
            />
          </Field>

          <Field
            label={`Deposit (${currency})`}
            hint={payEnabled ? 'Collected by Aicountly Pay.' : 'Aicountly Pay is not enabled yet.'}
            error={fieldErrors.deposit_minor}
          >
            <input
              className="appt-input"
              type="number"
              min={0}
              disabled={!form.deposit_required}
              value={form.deposit_minor === null ? '' : form.deposit_minor / 100}
              onChange={(event) =>
                setForm({
                  ...form,
                  deposit_minor: event.target.value === '' ? null : Math.round(Number(event.target.value) * 100),
                })
              }
            />
          </Field>
        </div>

        <label className="appt-row-tight" style={{ marginTop: 10 }}>
          <input
            type="checkbox"
            checked={form.deposit_required}
            onChange={(event) => setForm({ ...form, deposit_required: event.target.checked })}
          />
          <span>This service requires a deposit</span>
        </label>

        {form.deposit_required && !payEnabled && (
          <Notice tone="warning" title="Aicountly Pay integration is not enabled yet">
            The policy will be stored, but nothing can be collected. While Pay is off this service cannot be
            booked on a public booking page — confirming it would tell the client a payment had been arranged.
            Staff can still book it from inside Appointments.
          </Notice>
        )}
      </fieldset>

      <fieldset style={{ border: 0, padding: 0, margin: 0 }}>
        <legend className="appt-eyebrow">Availability</legend>

        <label className="appt-row-tight">
          <input
            type="checkbox"
            checked={form.is_bookable_online}
            onChange={(event) => setForm({ ...form, is_bookable_online: event.target.checked })}
          />
          <span>Clients can book this online</span>
        </label>

        <label className="appt-row-tight" style={{ marginTop: 8 }}>
          <input
            type="checkbox"
            checked={form.is_active}
            onChange={(event) => setForm({ ...form, is_active: event.target.checked })}
          />
          <span>Active</span>
        </label>

        {service !== null && (service.upcoming_count ?? 0) > 0 && !form.is_active && (
          <Notice tone="info" title={`${service.upcoming_count} upcoming appointments use this service`}>
            Retiring it stops new bookings. Appointments already in the diary are unaffected and will go ahead.
          </Notice>
        )}
      </fieldset>
    </Drawer>
  )
}
