/**
 * DASHBOARD 5 — Business Intelligence & Growth.
 *
 * The screen an owner opens on a Friday. Trends over a period, service and
 * staff performance, where demand comes from, and what the deposits came to.
 *
 * STAFF PERFORMANCE IS NOT AN APPRAISAL. Appointments delivered, utilisation,
 * rebook rate — appointment-shaped measures on a screen about the business.
 * There is deliberately no ranking score and no league position: this is not an
 * HRMS and it must not become the place somebody's review gets written from.
 *
 * PAYMENTS COME FROM PAY. Until Pay is connected the panel says so and offers
 * the connection. It never shows a figure from this product's database, because
 * this product does not have one — Appointments knows a deposit was REQUIRED,
 * and only Pay knows whether it arrived.
 */

import {
  ArrowRight,
  BadgeIndianRupee,
  CalendarRange,
  CalendarX,
  CreditCard,
  Gauge,
  RefreshCw,
  Target,
  UserX,
} from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { DashboardFrame, useDashboard } from './frame'
import { useAppointments } from '../context/AppointmentsContext'
import { Button, EmptyState, Notice, Panel, formatMoney } from '../ui'
import { DonutChart, LineChart } from '../ui/charts'
import type { IntelligencePanels } from '../services/types'

const METRIC_ICONS = {
  appointments: <CalendarRange size={16} />,
  booking_conversion: <Target size={16} />,
  utilisation: <Gauge size={16} />,
  repeat_booking: <RefreshCw size={16} />,
  cancellation: <CalendarX size={16} />,
  no_show: <UserX size={16} />,
}

export function IntelligenceDashboard() {
  const navigate = useNavigate()
  const { can, timezone } = useAppointments()
  const state = useDashboard<IntelligencePanels>('intelligence')

  return (
    <DashboardFrame view="intelligence" state={state} periodLabel="Last 7 weeks" metricIcons={METRIC_ICONS}>
      {(data) => (
        <>
          <div className="appt-split">
            <Panel title="Demand trend" subtitle="Appointments, bookings, completions and cancellations over time.">
              {data.panels.demand_trend.points.length < 2 ? (
                <EmptyState title="Not enough history yet">
                  A trend needs at least two periods. Come back once there is more than a day of bookings.
                </EmptyState>
              ) : (
                <LineChart
                  points={data.panels.demand_trend.points}
                  series={data.panels.demand_trend.series}
                  formatX={(value) =>
                    new Intl.DateTimeFormat('en-IN', {
                      day: 'numeric',
                      month: 'short',
                      timeZone: timezone,
                    }).format(new Date(`${value}T12:00:00Z`))
                  }
                />
              )}
            </Panel>

            <Panel
              title="Booking sources"
              subtitle="Where appointments came from in this period."
              info="Attributed by the credential that created each booking, never by what the request claimed."
            >
              {data.panels.sources.length === 0 ? (
                <EmptyState title="No bookings in this period" />
              ) : (
                <DonutChart
                  slices={data.panels.sources.map((source) => ({
                    label: source.label,
                    value: source.total,
                    share: source.share,
                  }))}
                  total={data.panels.sources.reduce((sum, source) => sum + source.total, 0)}
                  totalLabel="Total appointments"
                  size={172}
                />
              )}
            </Panel>
          </div>

          <div className="appt-grid appt-grid-3">
            <Panel
              title="Service performance"
              subtitle="Bookings, completions and growth."
              info="Utilisation here is a service's share of the period's booked time — a service has no capacity of its own, only a claim on the team's."
            >
              {data.panels.services.length === 0 ? (
                <EmptyState title="No services yet" />
              ) : (
                <div className="appt-table-wrap" style={{ border: 0 }}>
                  <table className="appt-table">
                    <thead>
                      <tr>
                        <th>Service</th>
                        <th className="num">Bookings</th>
                        <th className="num">Done</th>
                        <th className="num">Share</th>
                        <th className="num">Growth</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.panels.services.map((service) => (
                        <tr key={service.service_uuid}>
                          <td>{service.name}</td>
                          <td className="num">{service.bookings}</td>
                          <td className="num">{service.completions}</td>
                          <td className="num">{service.utilisation}%</td>
                          <td className="num">
                            {service.growth === null ? (
                              <span style={{ color: 'var(--muted)' }} title="No previous period to compare against">
                                —
                              </span>
                            ) : (
                              <span className={service.growth >= 0 ? 'appt-tone-positive' : 'appt-tone-negative'}>
                                {service.growth >= 0 ? '↑' : '↓'} {Math.abs(service.growth)}%
                              </span>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </Panel>

            <Panel
              title="Staff performance"
              subtitle="Appointment work only."
              info="Appointments delivered, utilisation and rebooking. Not a performance rating — this is not an HRMS."
            >
              {data.panels.staff.length === 0 ? (
                <EmptyState title="No appointments by practitioner yet" />
              ) : (
                <div className="appt-table-wrap" style={{ border: 0 }}>
                  <table className="appt-table">
                    <thead>
                      <tr>
                        <th>Practitioner</th>
                        <th className="num">Appointments</th>
                        <th className="num">Utilisation</th>
                        <th className="num">Rebook</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.panels.staff.map((member) => (
                        <tr key={member.member_uuid}>
                          <td>
                            <strong>{member.label}</strong>
                            {member.job_title && (
                              <div style={{ color: 'var(--muted)', fontSize: 11.5 }}>{member.job_title}</div>
                            )}
                          </td>
                          <td className="num">{member.appointments}</td>
                          <td className="num">{member.utilisation}%</td>
                          <td className="num">{member.rebook_rate}%</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </Panel>

            <Panel title="Receptionist contribution" subtitle="Appointments the voice front desk created.">
              {!data.panels.receptionist.configured ? (
                <>
                  <Notice tone="info" title="Receptionist is not connected yet">
                    {data.panels.receptionist.unavailable_reason} Appointments already accepts bookings from a
                    Receptionist service key and attributes them correctly — the figures below are the ones it
                    can see from its own records.
                  </Notice>
                  <ReceptionistCounts data={data.panels.receptionist} />
                </>
              ) : (
                <>
                  {data.panels.receptionist.unavailable_reason !== null && (
                    <Notice tone="warning" title="Receptionist did not answer">
                      {data.panels.receptionist.unavailable_reason}
                    </Notice>
                  )}
                  <ReceptionistCounts data={data.panels.receptionist} />
                  {data.panels.receptionist.deep_link !== null && (
                    <Button
                      style={{ width: '100%', marginTop: 12 }}
                      onClick={() => window.open(data.panels.receptionist.deep_link!, '_blank', 'noopener')}
                    >
                      Open in Receptionist
                      <ArrowRight size={14} aria-hidden />
                    </Button>
                  )}
                </>
              )}
            </Panel>
          </div>

          <Panel title="Payments & deposits" subtitle="Deposits and prepayments, through Aicountly Pay.">
            {!data.panels.payments.configured ? (
              <div
                style={{
                  display: 'grid',
                  gridTemplateColumns: 'auto minmax(0, 1fr)',
                  gap: 18,
                  alignItems: 'start',
                  padding: '4px 0',
                }}
              >
                <span className="appt-metric-icon appt-metric-icon-warning" aria-hidden style={{ width: 44, height: 44, margin: 0 }}>
                  <CreditCard size={20} />
                </span>

                <div>
                  <strong style={{ display: 'block', fontSize: 15 }}>
                    {data.panels.payments.unavailable_reason}
                  </strong>
                  <p style={{ color: 'var(--muted)', margin: '4px 0 14px', lineHeight: 1.6, maxWidth: '60ch' }}>
                    Appointments decides <em>whether</em> money is required — which services take a deposit,
                    how much, and what a late cancellation costs. Aicountly Pay collects it. Until Pay is
                    connected, those policies are stored and nothing is charged.
                    {data.panels.payments.deposit_required_minor > 0 && (
                      <>
                        {' '}
                        Booking policy in this period asked for{' '}
                        <strong>
                          {formatMoney(data.panels.payments.deposit_required_minor, data.panels.payments.currency)}
                        </strong>{' '}
                        in deposits — what was actually collected is Pay's to report.
                      </>
                    )}
                  </p>

                  <ul style={{ listStyle: 'none', margin: '0 0 16px', padding: 0, display: 'grid', gap: 6 }}>
                    {data.panels.payments.benefits.map((benefit) => (
                      <li key={benefit} style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13 }}>
                        <span style={{ color: 'var(--action)' }}>✓</span>
                        {benefit}
                      </li>
                    ))}
                  </ul>

                  {can('appointments.integrations.manage') && data.panels.payments.connect_action && (
                    <Button tone="primary" onClick={() => navigate('/integrations')}>
                      <BadgeIndianRupee size={15} aria-hidden />
                      {data.panels.payments.connect_action.label}
                    </Button>
                  )}
                </div>
              </div>
            ) : (
              <div className="appt-grid appt-grid-3" style={{ marginBottom: 0 }}>
                <MoneyTile
                  label="Appointment value"
                  minor={data.panels.payments.appointment_value_minor}
                  currency={data.panels.payments.currency}
                />
                <MoneyTile
                  label="Collected"
                  minor={data.panels.payments.collected_minor}
                  currency={data.panels.payments.currency}
                  tone="success"
                />
                <MoneyTile
                  label="Pending"
                  minor={data.panels.payments.pending_minor}
                  currency={data.panels.payments.currency}
                  tone="warning"
                />
                <MoneyTile
                  label="Refunded"
                  minor={data.panels.payments.refunded_minor}
                  currency={data.panels.payments.currency}
                />
                <MoneyTile
                  label="Deposits required by policy"
                  minor={data.panels.payments.deposit_required_minor}
                  currency={data.panels.payments.currency}
                  note="This product's own figure"
                />
              </div>
            )}
          </Panel>
        </>
      )}
    </DashboardFrame>
  )
}

function ReceptionistCounts({ data }: { data: IntelligencePanels['receptionist'] }) {
  const rows: { label: string; value: number | null; note?: string }[] = [
    { label: 'Initiated', value: data.initiated, note: 'Only Receptionist knows how many calls did not become a booking.' },
    { label: 'Booked', value: data.attributed.booked },
    { label: 'Rescheduled', value: data.attributed.rescheduled },
    { label: 'Cancelled', value: data.attributed.cancelled },
  ]

  return (
    <div className="appt-grid appt-grid-2" style={{ marginBottom: 0, gap: 10 }}>
      {rows.map((row) => (
        <div
          key={row.label}
          style={{
            padding: '12px 14px',
            border: '1px solid var(--line)',
            borderRadius: 11,
            background: 'var(--surface-soft)',
          }}
        >
          <div className="num" style={{ fontSize: 22, fontWeight: 700, textAlign: 'left' }}>
            {row.value === null ? (
              <span style={{ color: 'var(--muted)', fontSize: 15 }} title={row.note}>
                Not reported
              </span>
            ) : (
              row.value
            )}
          </div>
          <div style={{ fontSize: 12, color: 'var(--muted)', fontWeight: 650 }}>{row.label}</div>
        </div>
      ))}
    </div>
  )
}

function MoneyTile({
  label,
  minor,
  currency,
  tone,
  note,
}: {
  label: string
  minor: number | null
  currency: string
  tone?: 'success' | 'warning'
  note?: string
}) {
  return (
    <div
      style={{
        padding: '14px 16px',
        border: '1px solid var(--line)',
        borderRadius: 12,
        background:
          tone === 'success' ? 'var(--soft)' : tone === 'warning' ? 'var(--warning-soft)' : 'var(--surface-soft)',
      }}
    >
      <div className="num" style={{ fontSize: 22, fontWeight: 700, textAlign: 'left', letterSpacing: '-0.03em' }}>
        {minor === null ? <span style={{ color: 'var(--muted)', fontSize: 15 }}>Not reported</span> : formatMoney(minor, currency)}
      </div>
      <div style={{ fontSize: 12, color: 'var(--muted)', fontWeight: 650, marginTop: 2 }}>{label}</div>
      {note && <small style={{ color: 'var(--muted)' }}>{note}</small>}
    </div>
  )
}
