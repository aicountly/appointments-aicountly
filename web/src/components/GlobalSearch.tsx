/**
 * ⌘K search across appointments, clients and services.
 *
 * Three small queries in parallel rather than one clever endpoint: each of
 * these already exists, each is already permission-checked on the backend, and
 * a combined search endpoint would need its own opinion about what a
 * receptionist may see.
 *
 * The results are deliberately shallow — a name, a time, a status. This is a
 * way to GET somewhere, not a report.
 */

import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { CalendarRange, ClipboardList, Search, User } from 'lucide-react'
import { api } from '../services/api'
import type { Booking, Service } from '../services/types'
import { useAppointments } from '../context/AppointmentsContext'
import { Drawer, EmptyState, LoadingRows, StatusPill, formatDateTime } from '../ui'

interface ClientRow {
  contact_uuid: string
  label: string
  phone: string
  email: string
  appointments: number
}

export function GlobalSearch({ onClose }: { onClose: () => void }) {
  const navigate = useNavigate()
  const { timezone, can } = useAppointments()

  const [query, setQuery] = useState('')
  const [loading, setLoading] = useState(false)
  const [bookings, setBookings] = useState<Booking[]>([])
  const [clients, setClients] = useState<ClientRow[]>([])
  const [services, setServices] = useState<Service[]>([])

  useEffect(() => {
    const term = query.trim()
    if (term.length < 2) {
      setBookings([])
      setClients([])
      setServices([])
      return
    }

    const controller = new AbortController()

    const timer = window.setTimeout(() => {
      setLoading(true)

      // In parallel. Serially, the slowest of the three would decide how fast
      // the box feels.
      Promise.allSettled([
        can('appointments.booking.view')
          ? api.list<Booking>('v1/bookings', { q: term, limit: 5 }, controller.signal)
          : Promise.resolve({ data: [] as Booking[] }),
        can('appointments.clients.view')
          ? api.list<ClientRow>('v1/clients', { q: term, limit: 5 }, controller.signal)
          : Promise.resolve({ data: [] as ClientRow[] }),
        can('appointments.booking.view')
          ? api.get<{ data: { services: Service[] } }>('v1/services', undefined, controller.signal)
          : Promise.resolve({ data: { services: [] as Service[] } }),
      ])
        .then(([bookingResult, clientResult, serviceResult]) => {
          if (controller.signal.aborted) return

          setBookings(bookingResult.status === 'fulfilled' ? (bookingResult.value.data as Booking[]) : [])
          setClients(clientResult.status === 'fulfilled' ? (clientResult.value.data as ClientRow[]) : [])

          // Services are a short list, so they are filtered here rather than
          // asking for a search endpoint that does not need to exist.
          const all = serviceResult.status === 'fulfilled' ? serviceResult.value.data.services : []
          setServices(
            all.filter((service) => service.name.toLowerCase().includes(term.toLowerCase())).slice(0, 5),
          )
        })
        .finally(() => {
          if (!controller.signal.aborted) setLoading(false)
        })
    }, 220)

    return () => {
      controller.abort()
      window.clearTimeout(timer)
    }
  }, [query, can])

  const empty = useMemo(
    () => bookings.length === 0 && clients.length === 0 && services.length === 0,
    [bookings, clients, services],
  )

  function go(path: string): void {
    onClose()
    navigate(path)
  }

  return (
    <Drawer title="Search" subtitle="Clients, appointments and services" onClose={onClose}>
      <div style={{ position: 'relative' }}>
        <Search size={16} aria-hidden style={{ position: 'absolute', left: 12, top: 14, color: 'var(--muted)' }} />
        <input
          className="appt-input"
          style={{ paddingLeft: 36 }}
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          placeholder="Search clients, appointments, services…"
          // eslint-disable-next-line jsx-a11y/no-autofocus
          autoFocus
        />
      </div>

      {query.trim().length < 2 ? (
        <EmptyState title="Type at least two characters">
          Search by client name, phone, email or appointment reference.
        </EmptyState>
      ) : loading ? (
        <LoadingRows rows={3} height={52} />
      ) : empty ? (
        <EmptyState title={`Nothing matched “${query.trim()}”`}>
          Try a phone number or an appointment reference such as AP-1042.
        </EmptyState>
      ) : (
        <div className="appt-stack">
          {bookings.length > 0 && (
            <ResultGroup icon={<CalendarRange size={14} aria-hidden />} title="Appointments">
              {bookings.map((booking) => (
                <ResultRow
                  key={booking.booking_uuid}
                  onClick={() => go(`/appointments?booking=${booking.booking_uuid}`)}
                  title={booking.client.name || 'Client'}
                  detail={`${booking.reference} · ${booking.service.name} · ${formatDateTime(booking.starts_at, timezone)}`}
                  trailing={<StatusPill status={booking.status} />}
                />
              ))}
            </ResultGroup>
          )}

          {clients.length > 0 && (
            <ResultGroup icon={<User size={14} aria-hidden />} title="Clients">
              {clients.map((client) => (
                <ResultRow
                  key={client.contact_uuid}
                  onClick={() => go(`/clients/${client.contact_uuid}`)}
                  title={client.label}
                  detail={[client.phone, client.email].filter(Boolean).join(' · ')}
                  trailing={
                    <span style={{ color: 'var(--muted)', fontSize: 12 }}>
                      {client.appointments} appointment{client.appointments === 1 ? '' : 's'}
                    </span>
                  }
                />
              ))}
            </ResultGroup>
          )}

          {services.length > 0 && (
            <ResultGroup icon={<ClipboardList size={14} aria-hidden />} title="Services">
              {services.map((service) => (
                <ResultRow
                  key={service.service_uuid}
                  onClick={() => go(`/services?service=${service.service_uuid}`)}
                  title={service.name}
                  detail={`${service.duration_minutes} minutes`}
                />
              ))}
            </ResultGroup>
          )}
        </div>
      )}
    </Drawer>
  )
}

function ResultGroup({
  icon,
  title,
  children,
}: {
  icon: React.ReactNode
  title: string
  children: React.ReactNode
}) {
  return (
    <div>
      <p
        style={{
          margin: '0 0 8px',
          display: 'flex',
          alignItems: 'center',
          gap: 6,
          fontSize: 11.5,
          fontWeight: 700,
          color: 'var(--muted)',
          textTransform: 'uppercase',
        }}
      >
        {icon}
        {title}
      </p>
      <div className="appt-attention">{children}</div>
    </div>
  )
}

function ResultRow({
  onClick,
  title,
  detail,
  trailing,
}: {
  onClick: () => void
  title: string
  detail: string
  trailing?: React.ReactNode
}) {
  return (
    <button type="button" onClick={onClick} style={{ gridTemplateColumns: 'minmax(0, 1fr) auto' }}>
      <span style={{ minWidth: 0 }}>
        <strong>{title}</strong>
        <small style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', display: 'block' }}>
          {detail}
        </small>
      </span>
      {trailing}
    </button>
  )
}
