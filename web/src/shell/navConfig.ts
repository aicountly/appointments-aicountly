/**
 * The left navigation.
 *
 * Two groups, separated. The first five are the dashboards — the same five the
 * backend serves at /v1/dashboards/{view} — and everything below the separator
 * is the product's working surfaces.
 *
 * `permission` hides an item somebody cannot use. That is a COURTESY: the
 * backend asserts every permission before the query, so hiding a link saves a
 * wasted click and nothing more.
 *
 * `feature` hides an item this deployment cannot do at all. Different thing:
 * a waitlist page with no waitlist is not a permissions problem, it is a page
 * about something that does not exist here.
 */

import {
  BarChart3,
  CalendarDays,
  CalendarRange,
  ClipboardList,
  Clock,
  Gauge,
  Globe,
  Heart,
  LayoutDashboard,
  ListChecks,
  MapPin,
  MessageSquare,
  Radio,
  Settings as SettingsIcon,
  ShieldCheck,
  Sparkles,
  Users,
  Wrench,
  type LucideIcon,
} from 'lucide-react'

export interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  exact?: boolean
  permission?: string
  feature?: string
  /** Renders a divider above this item. */
  separator?: boolean
}

export const NAV: NavItem[] = [
  { to: '/', label: 'Overview', icon: LayoutDashboard, exact: true, permission: 'appointments.dashboard.view' },
  { to: '/live', label: 'Live Operations', icon: Radio, permission: 'appointments.dashboard.view' },
  { to: '/capacity', label: 'Capacity', icon: Gauge, permission: 'appointments.dashboard.view' },
  { to: '/client-experience', label: 'Client Experience', icon: Heart, permission: 'appointments.dashboard.view' },
  { to: '/intelligence', label: 'Intelligence', icon: Sparkles, permission: 'appointments.dashboard.view' },

  { to: '/calendar', label: 'Calendar', icon: CalendarDays, separator: true, permission: 'appointments.booking.view' },
  { to: '/appointments', label: 'Appointments', icon: CalendarRange, permission: 'appointments.booking.view' },
  { to: '/clients', label: 'Clients', icon: Users, permission: 'appointments.clients.view' },
  { to: '/services', label: 'Services', icon: ClipboardList, permission: 'appointments.booking.view' },
  { to: '/team', label: 'Team & Availability', icon: Clock, permission: 'appointments.booking.view' },
  { to: '/locations', label: 'Locations & Resources', icon: MapPin, permission: 'appointments.booking.view' },
  { to: '/waitlist', label: 'Waitlist', icon: ListChecks, permission: 'appointments.booking.view', feature: 'waitlist' },
  { to: '/booking-pages', label: 'Booking Pages', icon: Globe, permission: 'appointments.booking.view', feature: 'public_booking' },
  { to: '/forms', label: 'Forms', icon: Wrench, permission: 'appointments.booking.view' },
  { to: '/reminders', label: 'Reminders', icon: MessageSquare, permission: 'appointments.booking.view' },
  { to: '/reports', label: 'Reports', icon: BarChart3, permission: 'appointments.reports.view' },

  { to: '/integrations', label: 'Integrations', icon: ShieldCheck, separator: true, permission: 'appointments.dashboard.view' },
  { to: '/settings', label: 'Settings', icon: SettingsIcon, permission: 'appointments.dashboard.view' },
]

/**
 * The five dashboards, as the tab strip and the route table both read them.
 *
 * Titles and subtitles live here rather than in five components so that
 * renaming a dashboard is one edit.
 */
export const DASHBOARDS = [
  {
    id: 'overview',
    path: '/',
    api: 'overview',
    label: 'Overview',
    title: 'Appointment Command Center',
    subtitle: 'Real-time insights. Smoother days. Happier clients.',
  },
  {
    id: 'live',
    path: '/live',
    api: 'live',
    label: 'Live Operations',
    title: 'Live Operations Dashboard',
    subtitle: 'Manage real-time operations and keep your day on track.',
  },
  {
    id: 'capacity',
    path: '/capacity',
    api: 'capacity',
    label: 'Capacity',
    title: 'Availability & Capacity Intelligence',
    subtitle: 'Understand where you have capacity and optimise your schedule.',
  },
  {
    id: 'client-experience',
    path: '/client-experience',
    api: 'client-experience',
    label: 'Client Experience',
    title: 'Client Experience & Engagement',
    subtitle: 'Track how clients book, confirm, attend and come back.',
  },
  {
    id: 'intelligence',
    path: '/intelligence',
    api: 'intelligence',
    label: 'Intelligence',
    title: 'Business Intelligence & Growth',
    subtitle: 'Track performance, trends and opportunities for growth.',
  },
] as const

export type DashboardId = (typeof DASHBOARDS)[number]['id']

/**
 * Where a drilldown or an insight action goes.
 *
 * The server sends a route NAME and a set of filters — never a URL. Nothing
 * arriving from an API, or from anything that influenced one, can point a
 * browser at an address this application did not choose.
 */
export const ROUTES: Record<string, string> = {
  overview: '/',
  live: '/live',
  capacity: '/capacity',
  client_experience: '/client-experience',
  intelligence: '/intelligence',
  appointments: '/appointments',
  clients: '/clients',
  services: '/services',
  team: '/team',
  locations: '/locations',
  waitlist: '/waitlist',
  booking_pages: '/booking-pages',
  forms: '/forms',
  reminders: '/reminders',
  reports: '/reports',
  integrations: '/integrations',
  settings: '/settings',
  calendar: '/calendar',
  find_slot: '/appointments?find=1',
}

export function resolveRoute(route: string | null | undefined, params?: Record<string, string | number>): string | null {
  if (!route) return null

  const base = ROUTES[route]
  if (!base) return null

  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params ?? {})) {
    if (value === null || value === undefined || value === '') continue
    search.set(key, String(value))
  }

  if (!search.toString()) return base
  return base.includes('?') ? `${base}&${search}` : `${base}?${search}`
}
