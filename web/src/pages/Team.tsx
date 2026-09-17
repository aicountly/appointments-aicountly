/**
 * Team & Availability.
 *
 * ## A team member is not an employee record
 *
 * Aicountly Manage owns people. A row here is a reference to one of them plus
 * the appointment-specific settings: which hours they take bookings in, what
 * their buffers are, which calendar their appointments are written to. Names
 * are read from Manage live, so somebody renamed there is renamed here.
 *
 * ## Working hours are not free/busy
 *
 * This says "Tuesdays, 9 to 1 and 2 to 6". Whether next Tuesday at 10:30 is
 * already taken is Aicountly Calendar's answer, read live when somebody looks
 * for a slot. Both are needed and neither substitutes for the other.
 */

import { useState } from 'react'
import { Plus } from 'lucide-react'
import { api, ApiError } from '../services/api'
import { useApi } from '../hooks/useApi'
import { useAppointments } from '../context/AppointmentsContext'
import type { AvailabilityWindow, TeamMember } from '../services/types'
import { Button, Drawer, Field, LoadingRows, Notice, PanelState } from '../ui'

const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']

interface Candidate {
  user_uuid: string
  label: string
  email: string | null
  already_added: boolean
}

export function TeamPage() {
  const { can } = useAppointments()
  const [editing, setEditing] = useState<TeamMember | 'new' | null>(null)

  const list = useApi(
    (signal) =>
      api.get<{ data: { members: TeamMember[]; names_available: boolean } }>(
        'v1/team',
        { include_inactive: '1' },
        signal,
      ),
    [],
  )

  return (
    <div className="appt-ui">
      <header className="appt-page-header">
        <div>
          <h1>Team &amp; availability</h1>
          <p>Who takes appointments, and when.</p>
        </div>
        {can('appointments.team.manage') && (
          <Button tone="primary" onClick={() => setEditing('new')}>
            <Plus size={15} aria-hidden />
            Add someone
          </Button>
        )}
      </header>

      <PanelState
        loading={list.loading}
        error={list.error}
        data={list.data}
        isEmpty={(data) => data.data.members.length === 0}
        emptyTitle="Nobody on the team yet"
        emptyBody="Add colleagues from your company in Aicountly Manage. Appointments keeps their booking settings; Manage keeps who they are."
        onRetry={list.reload}
      >
        {(data) => (
          <>
            {!data.data.names_available && (
              <Notice tone="warning" title="Aicountly Manage did not answer">
                Names could not be read, so people are shown by their appointment label. Their settings and
                availability are unaffected.
              </Notice>
            )}

            <div className="appt-table-wrap">
              <table className="appt-table">
                <thead>
                  <tr>
                    <th>Person</th>
                    <th>Role</th>
                    <th className="num">Services</th>
                    <th className="num">Working windows</th>
                    <th>Bookings</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {data.data.members.map((member) => (
                    <tr key={member.member_uuid} style={member.is_active ? undefined : { opacity: 0.55 }}>
                      <td>
                        <strong>{member.label ?? member.display_label ?? 'Team member'}</strong>
                        {member.confirmed_in_manage === false && (
                          <div style={{ color: 'var(--warning)', fontSize: 11.5 }}>
                            Not found in Manage — they may have been removed from the company
                          </div>
                        )}
                      </td>
                      <td style={{ color: 'var(--muted)' }}>{member.job_title ?? '—'}</td>
                      <td className="num">{member.service_count ?? 0}</td>
                      <td className="num">{member.window_count ?? 0}</td>
                      <td>
                        {member.accepts_bookings ? (
                          <span className="appt-status appt-status-success">Accepting</span>
                        ) : (
                          <span className="appt-status appt-status-neutral">Closed</span>
                        )}
                      </td>
                      <td style={{ textAlign: 'right' }}>
                        {can('appointments.team.manage') && (
                          <Button small onClick={() => setEditing(member)}>
                            Availability
                          </Button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        )}
      </PanelState>

      {editing !== null && (
        <MemberDrawer
          member={editing === 'new' ? null : editing}
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

function MemberDrawer({
  member,
  onClose,
  onSaved,
}: {
  member: TeamMember | null
  onClose: () => void
  onSaved: () => void
}) {
  const [userUuid, setUserUuid] = useState('')
  const [jobTitle, setJobTitle] = useState(member?.job_title ?? '')
  const [acceptsBookings, setAcceptsBookings] = useState(member?.accepts_bookings ?? true)
  const [windows, setWindows] = useState<Partial<AvailabilityWindow>[]>([])
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const candidates = useApi(
    (signal) => api.get<{ data: { candidates: Candidate[] } }>('v1/team/candidates', undefined, signal),
    [],
    member === null,
  )

  const detail = useApi(
    (signal) =>
      api.get<{ data: { member: TeamMember; availability: AvailabilityWindow[] } }>(
        `v1/team/${member?.member_uuid}`,
        undefined,
        signal,
      ),
    [member?.member_uuid],
    member !== null,
  )

  // Seed the editor from what is stored, once.
  const loaded = detail.data?.data.availability
  const [seeded, setSeeded] = useState(false)
  if (loaded && !seeded) {
    setWindows(loaded)
    setSeeded(true)
  }

  async function save(): Promise<void> {
    setSaving(true)
    setError(null)

    const payload = {
      job_title: jobTitle,
      accepts_bookings: acceptsBookings,
      availability: windows.filter(
        (window) =>
          window.day_of_week !== undefined &&
          window.starts_minute !== undefined &&
          window.ends_minute !== undefined &&
          window.ends_minute > window.starts_minute,
      ),
    }

    try {
      if (member === null) {
        if (userUuid === '') {
          setError('Choose somebody from your company.')
          return
        }
        await api.post('v1/team', { user_uuid: userUuid, ...payload })
      } else {
        await api.put(`v1/team/${member.member_uuid}`, payload)
      }
      onSaved()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : (err as Error).message)
    } finally {
      setSaving(false)
    }
  }

  function addWindow(day: number): void {
    setWindows([
      ...windows,
      { day_of_week: day, starts_minute: 540, ends_minute: 1020, kind: 'available', bo_id: 0 },
    ])
  }

  return (
    <Drawer
      title={member === null ? 'Add somebody to the team' : (member.label ?? 'Team member')}
      subtitle="Working hours, buffers and which calendar their appointments go to."
      onClose={onClose}
      wide
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button tone="primary" onClick={save} busy={saving}>
            Save
          </Button>
        </>
      }
    >
      {error !== null && (
        <Notice tone="danger" title="This could not be saved">
          {error}
        </Notice>
      )}

      {member === null && (
        <Field label="Person" hint="Read live from Aicountly Manage — Appointments does not keep its own staff list.">
          {candidates.loading ? (
            <LoadingRows rows={1} height={42} />
          ) : candidates.error !== null ? (
            <Notice tone="warning" title="Aicountly Manage did not answer">
              {candidates.error.message}
            </Notice>
          ) : (
            <select className="appt-select" value={userUuid} onChange={(event) => setUserUuid(event.target.value)}>
              <option value="">Choose somebody</option>
              {(candidates.data?.data.candidates ?? []).map((candidate) => (
                <option key={candidate.user_uuid} value={candidate.user_uuid} disabled={candidate.already_added}>
                  {candidate.label}
                  {candidate.already_added ? ' — already on the team' : ''}
                </option>
              ))}
            </select>
          )}
        </Field>
      )}

      <Field label="Role" hint="Shown to clients when they choose a practitioner.">
        <input
          className="appt-input"
          value={jobTitle}
          onChange={(event) => setJobTitle(event.target.value)}
          placeholder="Senior consultant"
        />
      </Field>

      <label className="appt-row-tight">
        <input
          type="checkbox"
          checked={acceptsBookings}
          onChange={(event) => setAcceptsBookings(event.target.checked)}
        />
        <span>Accepting new appointments</span>
      </label>

      <fieldset style={{ border: 0, padding: 0, margin: 0 }}>
        <legend className="appt-eyebrow">Working hours</legend>

        <p style={{ color: 'var(--muted)', fontSize: 12.5, marginTop: 0, lineHeight: 1.6 }}>
          When this person is open to bookings. Whether a particular slot is already taken is Aicountly
          Calendar's answer and is checked live every time somebody looks — these hours decide what is
          offered, not what is free.
        </p>

        {member !== null && detail.loading ? (
          <LoadingRows rows={3} height={48} />
        ) : (
          <div className="appt-stack" style={{ gap: 14 }}>
            {DAYS.map((day, index) => {
              const dayWindows = windows
                .map((window, position) => ({ window, position }))
                .filter((entry) => entry.window.day_of_week === index)

              return (
                <div key={day}>
                  <div className="appt-row" style={{ justifyContent: 'space-between', marginBottom: 6 }}>
                    <strong style={{ fontSize: 13, fontWeight: 650 }}>{day}</strong>
                    <Button small tone="ghost" onClick={() => addWindow(index)}>
                      <Plus size={13} aria-hidden />
                      Add window
                    </Button>
                  </div>

                  {dayWindows.length === 0 ? (
                    <p style={{ color: 'var(--muted)', fontSize: 12, margin: 0 }}>
                      Closed — no appointments offered.
                    </p>
                  ) : (
                    <div className="appt-stack" style={{ gap: 6 }}>
                      {dayWindows.map(({ window, position }) => (
                        <div key={position} className="appt-row" style={{ gap: 8 }}>
                          <input
                            className="appt-input"
                            type="time"
                            style={{ maxWidth: 130 }}
                            value={minutesToTime(window.starts_minute ?? 540)}
                            onChange={(event) => {
                              const next = [...windows]
                              next[position] = { ...window, starts_minute: timeToMinutes(event.target.value) }
                              setWindows(next)
                            }}
                          />
                          <span style={{ color: 'var(--muted)' }}>to</span>
                          <input
                            className="appt-input"
                            type="time"
                            style={{ maxWidth: 130 }}
                            value={minutesToTime(window.ends_minute ?? 1020)}
                            onChange={(event) => {
                              const next = [...windows]
                              next[position] = { ...window, ends_minute: timeToMinutes(event.target.value) }
                              setWindows(next)
                            }}
                          />
                          <select
                            className="appt-select"
                            style={{ maxWidth: 140 }}
                            value={window.kind ?? 'available'}
                            onChange={(event) => {
                              const next = [...windows]
                              next[position] = { ...window, kind: event.target.value as 'available' | 'blocked' }
                              setWindows(next)
                            }}
                          >
                            <option value="available">Open</option>
                            <option value="blocked">Blocked</option>
                          </select>
                          <Button
                            small
                            tone="ghost"
                            onClick={() => setWindows(windows.filter((_, entry) => entry !== position))}
                          >
                            Remove
                          </Button>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              )
            })}
          </div>
        )}
      </fieldset>
    </Drawer>
  )
}

function minutesToTime(minutes: number): string {
  const hours = Math.floor(minutes / 60)
  const rest = minutes % 60
  return `${String(hours).padStart(2, '0')}:${String(rest).padStart(2, '0')}`
}

function timeToMinutes(value: string): number {
  const [hours, minutes] = value.split(':').map(Number)
  return (hours || 0) * 60 + (minutes || 0)
}
