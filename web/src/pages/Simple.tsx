/**
 * The configuration screens that are read-first and edited rarely.
 *
 * Locations, resources, forms, booking pages and reminders each get a real
 * screen here rather than a "coming soon" card, because the data behind them is
 * real and somebody has to be able to see it. Editing depth varies — booking
 * pages and reminders are where a business spends its time, so those have the
 * fuller treatment.
 */

import { useState } from 'react'
import { Copy, ExternalLink, Plus } from 'lucide-react'
import { api, ApiError } from '../services/api'
import { useApi } from '../hooks/useApi'
import { useAppointments } from '../context/AppointmentsContext'
import { Button, Drawer, Field, Notice, Panel, PanelState, StatusPill } from '../ui'

// ---------------------------------------------------------------------------
// Locations and resources
// ---------------------------------------------------------------------------

interface LocationRow {
  bo_id: number
  name: string | null
  address: string | null
  configured: boolean
  accepts_bookings: boolean
  bookable_online: boolean
  timezone: string
  arrival_instructions: string
  orphaned?: boolean
}

interface ResourceRow {
  resource_uuid: string
  name: string
  kind: string
  capacity: number
  bo_id: number
  calendar_subscriber_uuid: string | null
  turnaround_minutes: number
  is_active: boolean
  service_count: number | null
}

export function LocationsPage() {
  const { can } = useAppointments()
  const [addingResource, setAddingResource] = useState(false)

  const locations = useApi(
    (signal) =>
      api.get<{ data: { locations: LocationRow[]; branch_names_available: boolean } }>(
        'v1/locations',
        undefined,
        signal,
      ),
    [],
  )

  const resources = useApi(
    (signal) =>
      api.get<{ data: { resources: ResourceRow[]; kinds: string[] } }>(
        'v1/resources',
        { include_inactive: '1' },
        signal,
      ),
    [],
  )

  return (
    <div className="appt-ui">
      <header className="appt-page-header">
        <div>
          <h1>Locations &amp; resources</h1>
          <p>Where appointments happen, and what they need.</p>
        </div>
        {can('appointments.resources.manage') && (
          <Button tone="primary" onClick={() => setAddingResource(true)}>
            <Plus size={15} aria-hidden />
            Add a resource
          </Button>
        )}
      </header>

      <Panel
        title="Locations"
        subtitle="Branches from Aicountly Manage, with appointment settings attached."
        info="The branch itself — its name, address and phone number — belongs to Manage and is read from there."
      >
        <PanelState
          loading={locations.loading}
          error={locations.error}
          data={locations.data}
          isEmpty={(data) => data.data.locations.length === 0}
          emptyTitle="No branches yet"
          emptyBody="Create a branch in Aicountly Manage, then configure it for appointments here."
          onRetry={locations.reload}
        >
          {(data) => (
            <>
              {!data.data.branch_names_available && (
                <Notice tone="warning" title="Aicountly Manage did not answer">
                  Locations are shown by id. Their appointment settings are unaffected.
                </Notice>
              )}

              <div className="appt-table-wrap">
                <table className="appt-table">
                  <thead>
                    <tr>
                      <th>Location</th>
                      <th>Timezone</th>
                      <th>Bookings</th>
                      <th>Online</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.data.locations.map((location) => (
                      <tr key={location.bo_id}>
                        <td>
                          <strong>{location.name ?? `Location ${location.bo_id}`}</strong>
                          {location.address && (
                            <div style={{ color: 'var(--muted)', fontSize: 11.5 }}>{location.address}</div>
                          )}
                          {location.orphaned && (
                            <div style={{ color: 'var(--warning)', fontSize: 11.5 }}>
                              Manage no longer reports this branch
                            </div>
                          )}
                        </td>
                        <td style={{ color: 'var(--muted)' }}>{location.timezone}</td>
                        <td>
                          <StatusPill status={location.accepts_bookings ? 'CONFIRMED' : 'CANCELLED'}>
                            {location.accepts_bookings ? 'Accepting' : 'Closed'}
                          </StatusPill>
                        </td>
                        <td>{location.bookable_online ? 'Yes' : 'Staff only'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          )}
        </PanelState>
      </Panel>

      <Panel
        title="Resources"
        subtitle="Rooms, cabins, chairs and equipment an appointment needs."
        info="A resource with its own calendar in Aicountly Calendar is conflict-checked there, like a person."
      >
        <PanelState
          loading={resources.loading}
          error={resources.error}
          data={resources.data}
          isEmpty={(data) => data.data.resources.length === 0}
          emptyTitle="No resources yet"
          emptyBody="Add a room or a piece of equipment if an appointment cannot happen without it — availability then checks both the practitioner and the resource."
          onRetry={resources.reload}
        >
          {(data) => (
            <div className="appt-table-wrap">
              <table className="appt-table">
                <thead>
                  <tr>
                    <th>Resource</th>
                    <th>Kind</th>
                    <th className="num">Turnaround</th>
                    <th className="num">Services</th>
                    <th>Own calendar</th>
                  </tr>
                </thead>
                <tbody>
                  {data.data.resources.map((resource) => (
                    <tr key={resource.resource_uuid} style={resource.is_active ? undefined : { opacity: 0.55 }}>
                      <td>
                        <strong>{resource.name}</strong>
                      </td>
                      <td style={{ textTransform: 'capitalize' }}>{resource.kind}</td>
                      <td className="num" title="Cleaning or reset time before the next booking.">
                        {resource.turnaround_minutes} min
                      </td>
                      <td className="num">{resource.service_count ?? 0}</td>
                      <td>{resource.calendar_subscriber_uuid ? 'Yes' : 'No'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </PanelState>
      </Panel>

      {addingResource && (
        <ResourceDrawer
          kinds={resources.data?.data.kinds ?? []}
          onClose={() => setAddingResource(false)}
          onSaved={() => {
            setAddingResource(false)
            resources.reload()
          }}
        />
      )}
    </div>
  )
}

function ResourceDrawer({
  kinds,
  onClose,
  onSaved,
}: {
  kinds: string[]
  onClose: () => void
  onSaved: () => void
}) {
  const [form, setForm] = useState({ name: '', kind: 'room', capacity: 1, turnaround_minutes: 0 })
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function save(): Promise<void> {
    setSaving(true)
    setError(null)

    try {
      await api.post('v1/resources', form)
      onSaved()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : (err as Error).message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Drawer
      title="Add a resource"
      subtitle="Something an appointment needs as well as a practitioner."
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
      {error !== null && (
        <Notice tone="danger" title="This could not be saved">
          {error}
        </Notice>
      )}

      <Field label="Name">
        <input
          className="appt-input"
          value={form.name}
          onChange={(event) => setForm({ ...form, name: event.target.value })}
          placeholder="Treatment room 1"
        />
      </Field>

      <Field label="Kind">
        <select className="appt-select" value={form.kind} onChange={(event) => setForm({ ...form, kind: event.target.value })}>
          {kinds.map((kind) => (
            <option key={kind} value={kind} style={{ textTransform: 'capitalize' }}>
              {kind}
            </option>
          ))}
        </select>
      </Field>

      <Field
        label="Turnaround (minutes)"
        hint="Cleaning or reset time. The resource is not offered again until it has passed — showing it as free is how somebody gets booked into a room being mopped."
      >
        <input
          className="appt-input"
          type="number"
          min={0}
          max={240}
          value={form.turnaround_minutes}
          onChange={(event) => setForm({ ...form, turnaround_minutes: Number(event.target.value) })}
        />
      </Field>
    </Drawer>
  )
}

// ---------------------------------------------------------------------------
// Booking pages
// ---------------------------------------------------------------------------

interface BookingPage {
  page_uuid: string
  slug: string
  public_url: string | null
  kind: string
  headline: string
  intro: string
  is_published: boolean
  service_uuids: string[]
}

export function BookingPagesPage() {
  const { can, feature } = useAppointments()
  const [editing, setEditing] = useState<BookingPage | 'new' | null>(null)
  const [copied, setCopied] = useState<string | null>(null)

  const list = useApi(
    (signal) =>
      api.get<{ data: { pages: BookingPage[]; enabled: boolean; base_url: string } }>(
        'v1/booking-pages',
        undefined,
        signal,
      ),
    [],
  )

  return (
    <div className="appt-ui">
      <header className="appt-page-header">
        <div>
          <h1>Booking pages</h1>
          <p>Public pages clients book through.</p>
        </div>
        {can('appointments.booking_pages.manage') && feature('public_booking').enabled && (
          <Button tone="primary" onClick={() => setEditing('new')}>
            <Plus size={15} aria-hidden />
            New page
          </Button>
        )}
      </header>

      {!feature('public_booking').enabled && (
        <Notice tone="info" title="Public booking is turned off">
          {feature('public_booking').reason}
        </Notice>
      )}

      <PanelState
        loading={list.loading}
        error={list.error}
        data={list.data}
        isEmpty={(data) => data.data.pages.length === 0}
        emptyTitle="No booking pages yet"
        emptyBody="A booking page is a public link clients use to book themselves in. The address carries no company id, so it cannot be changed into somebody else's business."
        onRetry={list.reload}
      >
        {(data) => (
          <div className="appt-stack">
            {data.data.pages.map((page) => (
              <div
                key={page.page_uuid}
                className="appt-row"
                style={{
                  justifyContent: 'space-between',
                  padding: '14px 16px',
                  border: '1px solid var(--line)',
                  borderRadius: 12,
                  background: 'var(--surface)',
                }}
              >
                <span style={{ minWidth: 0 }}>
                  <strong style={{ display: 'block', fontWeight: 650 }}>{page.headline || `/${page.slug}`}</strong>
                  <small style={{ color: 'var(--muted)' }}>{page.public_url}</small>
                </span>

                <div className="appt-row-tight">
                  <StatusPill status={page.is_published ? 'CONFIRMED' : 'DRAFT'}>
                    {page.is_published ? 'Published' : 'Draft'}
                  </StatusPill>

                  {page.public_url && (
                    <>
                      <Button
                        small
                        onClick={() => {
                          navigator.clipboard?.writeText(page.public_url!).then(
                            () => setCopied(page.page_uuid),
                            () => undefined,
                          )
                        }}
                      >
                        <Copy size={13} aria-hidden />
                        {copied === page.page_uuid ? 'Copied' : 'Copy link'}
                      </Button>

                      {page.is_published && (
                        <Button small onClick={() => window.open(page.public_url!, '_blank', 'noopener')}>
                          <ExternalLink size={13} aria-hidden />
                          Open
                        </Button>
                      )}
                    </>
                  )}

                  {can('appointments.booking_pages.manage') && (
                    <Button small onClick={() => setEditing(page)}>
                      Edit
                    </Button>
                  )}
                </div>
              </div>
            ))}
          </div>
        )}
      </PanelState>

      {editing !== null && (
        <BookingPageDrawer
          page={editing === 'new' ? null : editing}
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

function BookingPageDrawer({
  page,
  onClose,
  onSaved,
}: {
  page: BookingPage | null
  onClose: () => void
  onSaved: () => void
}) {
  const [form, setForm] = useState({
    slug: page?.slug ?? '',
    kind: page?.kind ?? 'business',
    headline: page?.headline ?? '',
    intro: page?.intro ?? '',
    is_published: page?.is_published ?? false,
  })
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)

  const fieldErrors = error?.fieldErrors ?? {}

  async function save(): Promise<void> {
    setSaving(true)
    setError(null)

    try {
      await api.post('v1/booking-pages', { ...form, page_uuid: page?.page_uuid })
      onSaved()
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(0, 'error', (err as Error).message))
    } finally {
      setSaving(false)
    }
  }

  return (
    <Drawer
      title={page === null ? 'New booking page' : `/${page.slug}`}
      subtitle="A public link clients book through."
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button tone="primary" onClick={save} busy={saving}>
            Save page
          </Button>
        </>
      }
    >
      {error !== null && Object.keys(fieldErrors).length === 0 && (
        <Notice tone="danger" title="This could not be saved">
          {error.message}
        </Notice>
      )}

      <Field
        label="Web address"
        hint="Becomes /b/your-slug. No company id, so a booking link cannot be changed into somebody else's business."
        error={fieldErrors.slug}
      >
        <input
          className="appt-input"
          value={form.slug}
          onChange={(event) => setForm({ ...form, slug: event.target.value })}
          placeholder="greenleaf-wellness"
        />
      </Field>

      <Field label="Headline" hint="The first thing a client reads.">
        <input
          className="appt-input"
          value={form.headline}
          onChange={(event) => setForm({ ...form, headline: event.target.value })}
          placeholder="Book an appointment with GreenLeaf Wellness"
        />
      </Field>

      <Field label="Introduction">
        <textarea
          className="appt-textarea"
          value={form.intro}
          onChange={(event) => setForm({ ...form, intro: event.target.value })}
        />
      </Field>

      <label className="appt-row-tight">
        <input
          type="checkbox"
          checked={form.is_published}
          onChange={(event) => setForm({ ...form, is_published: event.target.checked })}
        />
        <span>Published — clients can reach this page</span>
      </label>

      <p style={{ color: 'var(--muted)', fontSize: 12, margin: 0, lineHeight: 1.6 }}>
        An unpublished page is a draft and answers 404 publicly. Building a booking page should not put it on
        the internet halfway through.
      </p>
    </Drawer>
  )
}

// ---------------------------------------------------------------------------
// Forms
// ---------------------------------------------------------------------------

interface FormRow {
  form_uuid: string
  name: string
  description: string
  is_sensitive: boolean
  is_active: boolean
  field_count: number | null
  service_count: number | null
}

export function FormsPage() {
  const list = useApi(
    (signal) =>
      api.get<{ data: { forms: FormRow[]; field_types: string[] } }>(
        'v1/forms',
        { include_inactive: '1' },
        signal,
      ),
    [],
  )

  return (
    <div className="appt-ui">
      <header className="appt-page-header">
        <div>
          <h1>Forms</h1>
          <p>What a client is asked when they book.</p>
        </div>
      </header>

      <PanelState
        loading={list.loading}
        error={list.error}
        data={list.data}
        isEmpty={(data) => data.data.forms.length === 0}
        emptyTitle="No forms yet"
        emptyBody="Attach a form to a service to collect what the practitioner needs before the appointment — allergies, what the client wants to discuss, a consent."
        onRetry={list.reload}
      >
        {(data) => (
          <div className="appt-table-wrap">
            <table className="appt-table">
              <thead>
                <tr>
                  <th>Form</th>
                  <th className="num">Questions</th>
                  <th className="num">Used by</th>
                  <th>Sensitive</th>
                </tr>
              </thead>
              <tbody>
                {data.data.forms.map((form) => (
                  <tr key={form.form_uuid}>
                    <td>
                      <strong>{form.name}</strong>
                      {form.description && (
                        <div style={{ color: 'var(--muted)', fontSize: 11.5 }}>{form.description}</div>
                      )}
                    </td>
                    <td className="num">{form.field_count ?? 0}</td>
                    <td className="num">{form.service_count ?? 0} services</td>
                    <td>
                      {form.is_sensitive ? (
                        <span
                          className="appt-status appt-status-warning"
                          title="Answers are hidden from anybody without the client-management permission. Whether it was completed stays visible to everybody."
                        >
                          Sensitive
                        </span>
                      ) : (
                        <span style={{ color: 'var(--muted)' }}>—</span>
                      )}
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

// ---------------------------------------------------------------------------
// Reminders
// ---------------------------------------------------------------------------

export function RemindersPage() {
  const list = useApi(
    (signal) =>
      api.get<{
        data: {
          rules: {
            reminder_rule_uuid: string
            name: string
            is_default: boolean
            is_active: boolean
            steps: { step_uuid: string; label: string; channel: string; only_if_unconfirmed: boolean }[]
          }[]
          channels: string[]
          messaging: { configured: boolean; reason: string | null }
        }
      }>('v1/settings/reminder-rules', undefined, signal),
    [],
  )

  return (
    <div className="appt-ui">
      <header className="appt-page-header">
        <div>
          <h1>Reminders</h1>
          <p>When clients are reminded, and how.</p>
        </div>
      </header>

      <PanelState
        loading={list.loading}
        error={list.error}
        data={list.data}
        emptyTitle="Reminder rules could not be loaded"
        onRetry={list.reload}
      >
        {(data) => (
          <>
            {!data.data.messaging.configured && (
              <Notice tone="warning" title="No messaging provider is connected">
                {data.data.messaging.reason} Appointments still computes and schedules every reminder — they
                are recorded as <strong>not sent</strong> with the reason, never shown as delivered. A
                performance panel reporting 96% delivery on messages nobody sent is worse than an empty one.
              </Notice>
            )}

            <Notice tone="info" title="Appointments owns these timers">
              There is no central automation engine in the AICOUNTLY fleet. A reminder is a fact about an
              appointment, so when an appointment moves the reminder moves, and when it is cancelled the
              reminder is cancelled. Delivery — the provider, the template, the sender identity — belongs to
              the messaging product.
            </Notice>

            {data.data.rules.map((rule) => (
              <Panel
                key={rule.reminder_rule_uuid}
                title={rule.name}
                subtitle={rule.is_default ? 'Used by every service that has not chosen another' : undefined}
              >
                <div className="appt-stack" style={{ gap: 8 }}>
                  {rule.steps.map((step) => (
                    <div
                      key={step.step_uuid}
                      className="appt-row"
                      style={{
                        justifyContent: 'space-between',
                        padding: '10px 12px',
                        border: '1px solid var(--line)',
                        borderRadius: 11,
                        background: 'var(--surface-soft)',
                      }}
                    >
                      <span>
                        <strong style={{ display: 'block', fontWeight: 650 }}>{step.label}</strong>
                        {step.only_if_unconfirmed && (
                          <small style={{ color: 'var(--muted)' }}>Only if the client has not confirmed</small>
                        )}
                      </span>
                      <span className="appt-status appt-status-neutral" style={{ textTransform: 'capitalize' }}>
                        {step.channel}
                        {step.channel === 'voice' && ' · via Receptionist'}
                      </span>
                    </div>
                  ))}
                </div>
              </Panel>
            ))}
          </>
        )}
      </PanelState>
    </div>
  )
}
