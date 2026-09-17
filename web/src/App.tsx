import { useEffect } from 'react'
import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { useAuth } from './auth/AuthProvider'
import { AppointmentsProvider } from './context/AppointmentsContext'
import { AppShell } from './shell/AppShell'
import { OverviewDashboard } from './dashboards/Overview'
import { LiveOperationsDashboard } from './dashboards/LiveOperations'
import { CapacityDashboard } from './dashboards/Capacity'
import { ClientExperienceDashboard } from './dashboards/ClientExperience'
import { IntelligenceDashboard } from './dashboards/Intelligence'
import { AppointmentsPage } from './pages/Appointments'
import { CalendarPage } from './pages/Calendar'
import { ClientDetailPage, ClientsPage } from './pages/Clients'
import { IntegrationsPage } from './pages/Integrations'
import { PublicBookingPage } from './pages/PublicBooking'
import { ServicesPage } from './pages/Services'
import { SettingsPage } from './pages/Settings'
import { BookingPagesPage, FormsPage, LocationsPage, RemindersPage } from './pages/Simple'
import { TeamPage } from './pages/Team'
import { WaitlistPage } from './pages/Waitlist'
import SignIn from './pages/SignIn'
import { initAnalytics, trackPageView } from './utils/analytics'
import './App.css'

initAnalytics()

/**
 * Routes.
 *
 * TWO TREES, and the split is the point. `/b/:slug` is the public booking page:
 * no session, no shell, no company. Everything else is the product and requires
 * a portal sign-in. Keeping them apart in one place is how it stays obvious
 * which URLs a stranger can reach.
 */
export default function App() {
  return (
    <BrowserRouter>
      <PageViews />
      <Routes>
        {/* Public — no session, no shell. */}
        <Route path="/b/:slug" element={<PublicBookingPage />} />

        {/* The product. */}
        <Route path="/*" element={<AuthenticatedApp />} />
      </Routes>
    </BrowserRouter>
  )
}

function AuthenticatedApp() {
  const { status } = useAuth()

  if (status === 'signed-out') return <SignIn />

  if (status === 'loading') {
    return (
      <main className="screen">
        <div className="panel">
          <p className="message">Signing you in…</p>
        </div>
      </main>
    )
  }

  return (
    <AppointmentsProvider>
      <Routes>
        <Route element={<AppShell />}>
          <Route index element={<OverviewDashboard />} />
          <Route path="live" element={<LiveOperationsDashboard />} />
          <Route path="capacity" element={<CapacityDashboard />} />
          <Route path="client-experience" element={<ClientExperienceDashboard />} />
          <Route path="intelligence" element={<IntelligenceDashboard />} />

          <Route path="calendar" element={<CalendarPage />} />
          <Route path="appointments" element={<AppointmentsPage />} />
          <Route path="clients" element={<ClientsPage />} />
          <Route path="clients/:contactUuid" element={<ClientDetailPage />} />
          <Route path="services" element={<ServicesPage />} />
          <Route path="team" element={<TeamPage />} />
          <Route path="locations" element={<LocationsPage />} />
          <Route path="waitlist" element={<WaitlistPage />} />
          <Route path="booking-pages" element={<BookingPagesPage />} />
          <Route path="forms" element={<FormsPage />} />
          <Route path="reminders" element={<RemindersPage />} />
          <Route path="reports" element={<IntelligenceDashboard />} />
          <Route path="integrations" element={<IntegrationsPage />} />
          <Route path="settings" element={<SettingsPage />} />

          {/* The portal lands here after sign-in; AuthProvider has already
              consumed the token by the time this renders. */}
          <Route path="auth/callback" element={<Navigate to="/" replace />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>
      </Routes>
    </AppointmentsProvider>
  )
}

/** GA4 page views, on every navigation rather than only the first. */
function PageViews() {
  const location = useLocation()

  useEffect(() => {
    trackPageView(location.pathname, document.title)
  }, [location.pathname])

  return null
}
