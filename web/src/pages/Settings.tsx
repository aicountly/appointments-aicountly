/**
 * Settings, booking policies and reminder rules.
 *
 * ## The policies are the product
 *
 * Cancellation notice, reschedule limits, no-show grace, whether a booking
 * needs confirming — these are the rules the availability engine and the
 * booking lifecycle enforce. Changing one here changes what the backend
 * refuses, which is why every field says what it does rather than only what it
 * is called.
 */

import { useState } from 'react'
import { api, ApiError } from '../services/api'
import { useApi } from '../hooks/useApi'
import { useAppointments } from '../context/AppointmentsContext'
import type { AppointmentSettings, FeatureState } from '../services/types'
import { Button, EmptyState, Field, Notice, Panel, PanelState } from '../ui'

interface BookingRule {
  rule_uuid: string
  name: string
  cancellation_notice_hours: number
  reschedule_notice_hours: number
  max_reschedules: number
  no_show_after_minutes: number
  requires_confirmation: boolean
  allow_client_cancellation: boolean
  allow_client_reschedule: boolean
  is_default: boolean
}

interface ReminderRule {
  reminder_rule_uuid: string
  name: string
  is_default: boolean
  is_active: boolean
  steps: {
    step_uuid: string
    offset_minutes: number
    channel: string
    template_key: string
    only_if_unconfirmed: boolean
    is_active: boolean
    label: string
  }[]
}

export function SettingsPage() {
  const { can, reload: reloadSession } = useAppointments()

  const settings = useApi(
    (signal) =>
      api.get<{
        data: {
          settings: AppointmentSettings
          features: Record<string, FeatureState>
          rules: BookingRule[]
          reminder_rules: ReminderRule[]
        }
      }>('v1/settings', undefined, signal),
    [],
  )

  const [draft, setDraft] = useState<Partial<AppointmentSettings>>({})
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [saved, setSaved] = useState(false)

  async function save(): Promise<void> {
    setSaving(true)
    setError(null)
    setSaved(false)

    try {
      await api.put('v1/settings', draft)
      setDraft({})
      setSaved(true)
      settings.reload()
      reloadSession()
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(0, 'error', (err as Error).message))
    } finally {
      setSaving(false)
    }
  }

  const editable = can('appointments.settings.manage')
  const fieldErrors = error?.fieldErrors ?? {}

  return (
    <div className="appt-ui">
      <header className="appt-page-header">
        <div>
          <h1>Settings</h1>
          <p>How this business books, holds and reminds.</p>
        </div>
        {editable && (
          <Button tone="primary" onClick={save} busy={saving} disabled={Object.keys(draft).length === 0}>
            Save changes
          </Button>
        )}
      </header>

      {saved && (
        <Notice tone="success" title="Settings saved">
          Availability and booking rules use these immediately.
        </Notice>
      )}

      {error !== null && Object.keys(fieldErrors).length === 0 && (
        <Notice tone="danger" title="These could not be saved">
          {error.message}
        </Notice>
      )}

      <PanelState
        loading={settings.loading}
        error={settings.error}
        data={settings.data}
        emptyTitle="Settings could not be loaded"
        onRetry={settings.reload}
      >
        {(payload) => {
          const current = { ...payload.data.settings, ...draft }

          return (
            <>
              <div className="appt-split">
                <Panel title="Scheduling" subtitle="How slots are offered and held.">
                  <div className="appt-grid appt-grid-2" style={{ marginBottom: 0 }}>
                    <Field
                      label="Timezone"
                      hint="Every time on every screen is rendered in this zone, not the browser's."
                      error={fieldErrors.timezone}
                    >
                      <input
                        className="appt-input"
                        value={current.timezone}
                        disabled={!editable}
                        onChange={(event) => setDraft({ ...draft, timezone: event.target.value })}
                      />
                    </Field>

                    <Field label="Currency">
                      <input
                        className="appt-input"
                        value={current.currency}
                        disabled={!editable}
                        maxLength={3}
                        onChange={(event) => setDraft({ ...draft, currency: event.target.value.toUpperCase() })}
                      />
                    </Field>

                    <Field
                      label="Slot granularity"
                      hint="The grid slots line up to. Offering 9:07 is technically free and practically useless."
                      error={fieldErrors.slot_granularity_minutes}
                    >
                      <select
                        className="appt-select"
                        value={current.slot_granularity_minutes}
                        disabled={!editable}
                        onChange={(event) =>
                          setDraft({ ...draft, slot_granularity_minutes: Number(event.target.value) })
                        }
                      >
                        {[5, 10, 15, 20, 30, 60].map((value) => (
                          <option key={value} value={value}>
                            {value} minutes
                          </option>
                        ))}
                      </select>
                    </Field>

                    <Field
                      label="Slot hold"
                      hint="How long a slot is held while somebody finishes booking. It is the length of a checkout, not a reservation."
                      error={fieldErrors.hold_duration_seconds}
                    >
                      <select
                        className="appt-select"
                        value={current.hold_duration_seconds}
                        disabled={!editable}
                        onChange={(event) =>
                          setDraft({ ...draft, hold_duration_seconds: Number(event.target.value) })
                        }
                      >
                        {[120, 300, 600, 900].map((value) => (
                          <option key={value} value={value}>
                            {value / 60} minutes
                          </option>
                        ))}
                      </select>
                    </Field>
                  </div>

                  <label className="appt-row-tight" style={{ marginTop: 14 }}>
                    <input
                      type="checkbox"
                      checked={current.auto_confirm_online}
                      disabled={!editable}
                      onChange={(event) => setDraft({ ...draft, auto_confirm_online: event.target.checked })}
                    />
                    <span>Confirm online bookings automatically</span>
                  </label>
                </Panel>

                <Panel title="Features" subtitle="What is on for this company, and what this deployment can do.">
                  <div className="appt-stack" style={{ gap: 10 }}>
                    {Object.entries(payload.data.features).map(([flag, state]) => (
                      <div
                        key={flag}
                        className="appt-row"
                        style={{
                          justifyContent: 'space-between',
                          padding: '10px 12px',
                          border: '1px solid var(--line)',
                          borderRadius: 11,
                          background: 'var(--surface-soft)',
                          alignItems: 'start',
                        }}
                      >
                        <span style={{ minWidth: 0 }}>
                          <strong style={{ display: 'block', fontWeight: 650, textTransform: 'capitalize' }}>
                            {flag.replace(/_/g, ' ')}
                          </strong>
                          {state.reason && (
                            <small style={{ color: 'var(--muted)', lineHeight: 1.5 }}>{state.reason}</small>
                          )}
                        </span>
                        <span className={`appt-status appt-status-${state.enabled ? 'success' : 'neutral'}`}>
                          {state.enabled ? 'On' : 'Off'}
                        </span>
                      </div>
                    ))}
                  </div>
                </Panel>
              </div>

              <Panel
                title="Booking policies"
                subtitle="What the backend enforces on every cancellation and reschedule."
              >
                {payload.data.rules.length === 0 ? (
                  <EmptyState title="No policies yet">
                    A default policy is created with the company. If you are seeing this, something went wrong
                    with that.
                  </EmptyState>
                ) : (
                  <div className="appt-table-wrap">
                    <table className="appt-table">
                      <thead>
                        <tr>
                          <th>Policy</th>
                          <th className="num">Cancel notice</th>
                          <th className="num">Move notice</th>
                          <th className="num">Max moves</th>
                          <th className="num">No-show after</th>
                          <th>Needs confirming</th>
                        </tr>
                      </thead>
                      <tbody>
                        {payload.data.rules.map((rule) => (
                          <tr key={rule.rule_uuid}>
                            <td>
                              <strong>{rule.name}</strong>
                              {rule.is_default && (
                                <span className="appt-status appt-status-success" style={{ marginLeft: 8 }}>
                                  Default
                                </span>
                              )}
                            </td>
                            <td className="num">{rule.cancellation_notice_hours}h</td>
                            <td className="num">{rule.reschedule_notice_hours}h</td>
                            <td className="num">{rule.max_reschedules}</td>
                            <td className="num">{rule.no_show_after_minutes} min</td>
                            <td>{rule.requires_confirmation ? 'Yes' : 'No'}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </Panel>

              <Panel
                title="Reminder rules"
                subtitle="Appointments owns these timers. Delivery goes through the messaging provider."
              >
                {payload.data.reminder_rules.length === 0 ? (
                  <EmptyState title="No reminder rules" />
                ) : (
                  <div className="appt-stack">
                    {payload.data.reminder_rules.map((rule) => (
                      <div key={rule.reminder_rule_uuid}>
                        <div className="appt-row" style={{ justifyContent: 'space-between', marginBottom: 8 }}>
                          <strong style={{ fontWeight: 650 }}>
                            {rule.name}
                            {rule.is_default && (
                              <span className="appt-status appt-status-success" style={{ marginLeft: 8 }}>
                                Default
                              </span>
                            )}
                          </strong>
                        </div>

                        <div className="appt-row" style={{ gap: 8 }}>
                          {rule.steps.map((step) => (
                            <span key={step.step_uuid} className="appt-status appt-status-neutral">
                              {step.label} · {step.channel}
                              {step.only_if_unconfirmed && ' · only if unconfirmed'}
                            </span>
                          ))}
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </Panel>
            </>
          )
        }}
      </PanelState>
    </div>
  )
}
