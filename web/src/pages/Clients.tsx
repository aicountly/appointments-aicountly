/**
 * Clients, as an appointment business sees them.
 *
 * THERE IS NO CLIENT TABLE HERE. This list is built from this product's own
 * bookings, grouped by contact: how many times somebody has been, what they
 * usually book, when they last came. Their name, number and address belong to
 * Aicountly Contacts and are read from there.
 *
 * What Appointments keeps against a contact is the appointment-shaped part —
 * which practitioner they ask for, which channel they answer, how many times
 * they have not turned up. None of that is Contacts' business.
 */

import { useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { ArrowLeft } from 'lucide-react'
import { api } from '../services/api'
import { useApi } from '../hooks/useApi'
import { useAppointments } from '../context/AppointmentsContext'
import { Button, EmptyState, Field, Notice, Panel, PanelState, StatusPill, formatDateTime } from '../ui'

interface ClientRow {
  contact_uuid: string
  label: string
  phone: string
  email: string
  appointments: number
  completed: number
  no_shows: number
  cancelled: number
  segment: 'new' | 'returning'
  usual_service: string | null
  last_appointment: string | null
  first_appointment: string | null
  preferred_channel: string | null
}

interface ClientDetail {
  contact_uuid: string
  contact: Record<string, unknown> | null
  contact_message: string | null
  appointment_profile: Record<string, unknown>
  history: {
    booking_uuid: string
    reference: string
    starts_at: string
    status: string
    mode: string
    source: string
    service_name: string
    member_label: string | null
  }[]
}

export function ClientsPage() {
  const { timezone } = useAppointments()
  const navigate = useNavigate()
  const [query, setQuery] = useState('')
  const [segment, setSegment] = useState('')

  const list = useApi(
    (signal) =>
      api.list<ClientRow>(
        'v1/clients',
        { q: query || undefined, segment: segment || undefined, limit: 50 },
        signal,
      ),
    [query, segment],
  )

  return (
    <div className="appt-ui">
      <header className="appt-page-header">
        <div>
          <h1>Clients</h1>
          <p>Everybody who has booked with you, and how it went.</p>
        </div>
      </header>

      <div className="appt-filters">
        <Field label="Search">
          <input
            className="appt-input"
            style={{ minWidth: 260 }}
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder="Name, phone or email"
          />
        </Field>

        <Field label="Segment">
          <select className="appt-select" value={segment} onChange={(event) => setSegment(event.target.value)}>
            <option value="">Everybody</option>
            <option value="new">First-timers</option>
            <option value="returning">Returning</option>
          </select>
        </Field>
      </div>

      <PanelState
        loading={list.loading}
        error={list.error}
        data={list.data}
        isEmpty={(data) => data.data.length === 0}
        emptyTitle="No clients yet"
        emptyBody="Clients appear here once they have booked an appointment. Their identity is kept in Aicountly Contacts — this is what Appointments knows about them."
        onRetry={list.reload}
      >
        {(data) => (
          <div className="appt-table-wrap">
            <table className="appt-table">
              <thead>
                <tr>
                  <th>Client</th>
                  <th>Usually books</th>
                  <th className="num">Appointments</th>
                  <th className="num">Completed</th>
                  <th className="num">No-shows</th>
                  <th>Last seen</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {data.data.map((client) => (
                  <tr key={client.contact_uuid}>
                    <td>
                      <strong>{client.label}</strong>
                      <div style={{ color: 'var(--muted)', fontSize: 11.5 }}>
                        {client.phone || client.email || '—'}
                      </div>
                    </td>
                    <td>{client.usual_service ?? '—'}</td>
                    <td className="num">{client.appointments}</td>
                    <td className="num">{client.completed}</td>
                    <td className="num" style={client.no_shows > 0 ? { color: 'var(--danger)' } : undefined}>
                      {client.no_shows}
                    </td>
                    <td style={{ color: 'var(--muted)' }}>{formatDateTime(client.last_appointment, timezone)}</td>
                    <td style={{ textAlign: 'right' }}>
                      <Button small onClick={() => navigate(`/clients/${client.contact_uuid}`)}>
                        Open
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </PanelState>
    </div>
  )
}

export function ClientDetailPage() {
  const { contactUuid = '' } = useParams()
  const navigate = useNavigate()
  const { timezone } = useAppointments()

  const detail = useApi(
    (signal) => api.get<{ data: ClientDetail }>(`v1/clients/${contactUuid}`, undefined, signal),
    [contactUuid],
  )

  return (
    <div className="appt-ui">
      <header className="appt-page-header">
        <div>
          <Button small tone="ghost" onClick={() => navigate('/clients')} style={{ marginBottom: 10 }}>
            <ArrowLeft size={14} aria-hidden />
            All clients
          </Button>
          <h1>{contactName(detail.data?.data) ?? 'Client'}</h1>
          <p>Appointment history and preferences.</p>
        </div>
      </header>

      <PanelState
        loading={detail.loading}
        error={detail.error}
        data={detail.data}
        emptyTitle="Not found"
        onRetry={detail.reload}
      >
        {(payload) => {
          const data = payload.data
          const profile = data.appointment_profile

          return (
            <>
              {data.contact_message !== null && (
                <Notice tone="info" title="Client identity comes from Aicountly Contacts">
                  {data.contact_message}
                </Notice>
              )}

              <div className="appt-split">
                <Panel title="Appointment history" subtitle="Everything booked with this company.">
                  {data.history.length === 0 ? (
                    <EmptyState title="No appointments" />
                  ) : (
                    <div className="appt-table-wrap">
                      <table className="appt-table">
                        <thead>
                          <tr>
                            <th>When</th>
                            <th>Service</th>
                            <th>With</th>
                            <th>Status</th>
                          </tr>
                        </thead>
                        <tbody>
                          {data.history.map((entry) => (
                            <tr key={entry.booking_uuid}>
                              <td>
                                <button
                                  type="button"
                                  onClick={() => navigate(`/appointments?booking=${entry.booking_uuid}`)}
                                  style={{ border: 0, background: 'transparent', padding: 0, cursor: 'pointer', textAlign: 'left' }}
                                >
                                  <strong>{formatDateTime(entry.starts_at, timezone)}</strong>
                                  <div style={{ color: 'var(--muted)', fontSize: 11.5 }}>{entry.reference}</div>
                                </button>
                              </td>
                              <td>{entry.service_name}</td>
                              <td style={{ color: 'var(--muted)' }}>{entry.member_label ?? '—'}</td>
                              <td>
                                <StatusPill status={entry.status} />
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                </Panel>

                <Panel
                  title="Appointment profile"
                  subtitle="What Appointments knows and Contacts does not."
                  info="Counters are maintained from this product's own bookings, incrementally, and can be traced to the rows above."
                >
                  <dl className="appt-definition-list">
                    <div>
                      <dt>Appointments booked</dt>
                      <dd>{String(profile.total_bookings ?? 0)}</dd>
                    </div>
                    <div>
                      <dt>Completed</dt>
                      <dd>{String(profile.completed_count ?? 0)}</dd>
                    </div>
                    <div>
                      <dt>Missed</dt>
                      <dd>{String(profile.no_show_count ?? 0)}</dd>
                    </div>
                    <div>
                      <dt>Cancelled late</dt>
                      <dd>{String(profile.late_cancel_count ?? 0)}</dd>
                    </div>
                    <div>
                      <dt>Preferred channel</dt>
                      <dd>{(profile.preferred_channel as string) ?? 'Not recorded'}</dd>
                    </div>
                    <div>
                      <dt>Preferred time of day</dt>
                      <dd>{(profile.preferred_daypart as string) ?? 'Not recorded'}</dd>
                    </div>
                  </dl>
                </Panel>
              </div>
            </>
          )
        }}
      </PanelState>
    </div>
  )
}

function contactName(detail: ClientDetail | undefined): string | null {
  if (!detail) return null
  const contact = detail.contact as { name?: string } | null
  if (contact?.name) return contact.name
  return detail.history[0] ? 'Client' : null
}
