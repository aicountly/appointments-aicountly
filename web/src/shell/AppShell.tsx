/**
 * The application frame: brand, navigation, company context, search, and the
 * product area everything else renders into.
 *
 * THE LOGO IS THE SHIPPED ONE. `public/apps/calendar.png` stands in until the
 * Appointments app icon lands in the launcher bundle; it is used as it is —
 * not redrawn, not recoloured, not replaced with initials or an emoji —
 * because a trademark is not a placeholder.
 */

import { useEffect, useState } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { CalendarPlus, LogOut, Menu, Search, ShieldAlert } from 'lucide-react'
import { useAuth } from '../auth/AuthProvider'
import { AppLauncher } from '../components/AppLauncher'
import { useAppointments } from '../context/AppointmentsContext'
import { NAV } from './navConfig'
import { Button, EmptyState, Notice } from '../ui'
import { CompanyPicker } from './CompanyPicker'
import { NewAppointmentDrawer } from '../components/NewAppointmentDrawer'
import { FindSlotDrawer } from '../components/FindSlotDrawer'
import { GlobalSearch } from '../components/GlobalSearch'
import './app-shell.css'

function initials(name: string | null | undefined): string {
  if (!name) return '—'

  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('')
}

/**
 * The screen for somebody who is signed in but has not been given anything.
 *
 * Without this, a user with no Appointments access sees a sidebar with two
 * links and a page of refused panels, which reads as a broken app rather than
 * as an administrative step nobody has taken yet. It names who can fix it,
 * because the person seeing this screen cannot.
 */
function NoAccess() {
  return (
    <div className="appt-ui">
      <div className="appt-card" style={{ maxWidth: 560, margin: '3rem auto' }}>
        <div className="appt-card-body" style={{ textAlign: 'center' }}>
          <ShieldAlert size={28} aria-hidden style={{ color: 'var(--warning)' }} />
          <h1 style={{ fontSize: '1.15rem', margin: '0.75rem 0 0.4rem' }}>You have no Appointments access yet</h1>
          <p style={{ color: 'var(--muted)', margin: 0, lineHeight: 1.6 }}>
            Your sign-in worked and you can open this company, but nobody has given you an Appointments
            permission profile. Ask the company owner to add you under <strong>Settings → Access</strong> in
            this app — Appointments permissions are set here, not in Manage.
          </p>
        </div>
      </div>
    </div>
  )
}

export function AppShell() {
  const { signOut } = useAuth()
  const { session, companies, companiesError, loading, error, can, feature, reload } = useAppointments()
  const location = useLocation()
  const navigate = useNavigate()

  const [navOpen, setNavOpen] = useState(false)
  const [findingSlot, setFindingSlot] = useState(false)
  const [searching, setSearching] = useState(false)

  // `null` closed, `{}` open with nothing chosen, or open pre-filled from Find
  // Slot. One piece of state rather than two, so the drawer cannot be open and
  // carrying a slot the user did not pick.
  const [booking, setBooking] = useState<
    { serviceUuid?: string; memberUuid?: string | null; startsAt?: string } | null
  >(null)

  // Navigating closes the drawer. Leaving it open over the page somebody just
  // asked for is the classic mobile-nav bug.
  useEffect(() => setNavOpen(false), [location.pathname])

  // ⌘K / Ctrl-K opens search. The one shortcut worth having on a screen
  // somebody works at all day.
  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault()
        setSearching(true)
      }
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [])

  const lockedOut = session !== null && !session.user.is_owner && session.permissions.length === 0

  const items = NAV.filter((item) => {
    if (item.permission && session !== null && !can(item.permission)) return false
    if (item.feature && session !== null && !feature(item.feature).enabled) return false
    return true
  })

  return (
    <div style={{ display: 'flex', minHeight: '100vh', background: 'var(--bg)' }}>
      {navOpen && (
        <button type="button" className="shell-scrim" aria-label="Close navigation" onClick={() => setNavOpen(false)} />
      )}

      <aside className="shell-sidebar" data-open={navOpen}>
        <div className="shell-brand">
          <img src="/apps/calendar.png" alt="" width={34} height={34} aria-hidden />
          <div style={{ minWidth: 0 }}>
            <strong>Aicountly</strong>
            <small>Appointments</small>
          </div>
        </div>

        <nav className="shell-nav" aria-label="Appointments">
          {items.map((item) => (
            <div key={item.to}>
              {item.separator && <div className="shell-nav-separator" />}
              <NavLink to={item.to} end={item.exact} className="shell-nav-item">
                <item.icon size={16} aria-hidden />
                <span>{item.label}</span>
              </NavLink>
            </div>
          ))}
        </nav>

        <div className="shell-sidebar-foot">
          <div className="shell-promo">
            <strong>Smarter appointments</strong>
            <span>Happier people</span>
          </div>
          <AppLauncher />
        </div>
      </aside>

      <div style={{ flex: 1, minWidth: 0, display: 'flex', flexDirection: 'column' }}>
        <header className="shell-topbar">
          <button
            type="button"
            className="shell-sidebar-toggle"
            aria-label="Open navigation"
            aria-expanded={navOpen}
            onClick={() => setNavOpen(true)}
          >
            <Menu size={18} aria-hidden />
          </button>

          <h1 className="shell-title">Aicountly Appointments</h1>

          <button type="button" className="shell-search" onClick={() => setSearching(true)}>
            <Search size={15} aria-hidden />
            <span>Search clients, appointments, services…</span>
            <kbd>⌘K</kbd>
          </button>

          <div className="shell-top-actions">
            {can('appointments.booking.view') && (
              <Button small onClick={() => setFindingSlot(true)}>
                Find slot
              </Button>
            )}
            {can('appointments.booking.create') && (
              <Button tone="primary" small onClick={() => setBooking({})}>
                <CalendarPlus size={15} aria-hidden />
                New appointment
              </Button>
            )}

            <CompanyPicker />

            <div className="shell-user" title={session?.user.name ?? ''}>
              <span aria-hidden>{initials(session?.user.name)}</span>
            </div>

            <Button tone="ghost" small onClick={signOut} title="Log out">
              <LogOut size={15} aria-hidden />
              <span className="appt-visually-hidden">Log out</span>
            </Button>
          </div>
        </header>

        <main style={{ flex: 1, minWidth: 0 }}>
          {companiesError !== null && companies.length === 0 ? (
            <div className="appt-ui">
              <Notice
                tone="danger"
                title="Your companies could not be loaded"
                action={
                  <Button small onClick={reload}>
                    Retry
                  </Button>
                }
              >
                {companiesError} Aicountly Manage owns the list of companies you can open, so Appointments
                cannot show anything until it answers.
              </Notice>
            </div>
          ) : error !== null ? (
            <div className="appt-ui">
              <Notice
                tone="danger"
                title="This company could not be opened"
                action={
                  <Button small onClick={reload}>
                    Retry
                  </Button>
                }
              >
                {error.message}
              </Notice>
            </div>
          ) : loading && session === null ? (
            <div className="appt-ui">
              <div className="appt-metric-row" aria-busy="true" aria-label="Loading">
                {Array.from({ length: 6 }, (_, index) => (
                  <div key={index} className="appt-skeleton" style={{ height: 118, borderRadius: 14 }} />
                ))}
              </div>
              <div className="appt-skeleton" style={{ height: 320, borderRadius: 16 }} />
            </div>
          ) : companies.length === 0 && session === null ? (
            <div className="appt-ui">
              <EmptyState title="No companies yet">
                Aicountly Manage has no company you can open. Create one in Manage, then come back.
              </EmptyState>
            </div>
          ) : lockedOut ? (
            <NoAccess />
          ) : (
            <Outlet />
          )}
        </main>
      </div>

      {booking !== null && (
        <NewAppointmentDrawer
          initial={booking}
          onClose={() => setBooking(null)}
          onBooked={(bookingUuid) => {
            setBooking(null)
            navigate(`/appointments?booking=${bookingUuid}`)
          }}
        />
      )}

      {findingSlot && (
        <FindSlotDrawer
          onClose={() => setFindingSlot(false)}
          onBook={(choice) => {
            // Handed straight to the booking drawer: holding and confirming
            // happen in one place, not two that could drift apart.
            setFindingSlot(false)
            setBooking(choice)
          }}
        />
      )}

      {searching && <GlobalSearch onClose={() => setSearching(false)} />}
    </div>
  )
}
