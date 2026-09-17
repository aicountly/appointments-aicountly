/**
 * The wire shapes the Appointments API sends.
 *
 * Hand-written rather than generated, and kept deliberately close to what the
 * backend actually returns — including the fields that exist to say "we could
 * not find this out". `calendar_available`, `measured`, `unavailable_reason`
 * and `change_pct: null` are all load-bearing: a UI that treats them as
 * optional decoration ends up drawing a confident zero over an unknown.
 */

// ---------------------------------------------------------------------------
// Session and vocabulary
// ---------------------------------------------------------------------------

export type BookingStatus =
  | 'DRAFT'
  | 'PENDING'
  | 'CONFIRMED'
  | 'ARRIVED'
  | 'IN_PROGRESS'
  | 'COMPLETED'
  | 'RESCHEDULED'
  | 'CANCELLED'
  | 'NO_SHOW'

export type AppointmentMode = 'IN_PERSON' | 'PHONE' | 'AICOUNTLY_CONNECT' | 'EXTERNAL_VIDEO'

export interface FeatureState {
  enabled: boolean
  /** Why it is off, in words an administrator can act on. Null when it is on. */
  reason: string | null
}

export interface Vocabulary {
  statuses: { key: BookingStatus; label: string; next: BookingStatus[] }[]
  active_statuses: BookingStatus[]
  modes: { key: AppointmentMode; label: string }[]
  sources: { key: string; label: string }[]
  channels: string[]
  dayparts: string[]
}

export interface AppointmentSettings {
  cmp_id: number
  timezone: string
  currency: string
  slot_granularity_minutes: number
  hold_duration_seconds: number
  week_starts_on: number
  day_starts_minute: number
  day_ends_minute: number
  waitlist_enabled: boolean
  public_booking_enabled: boolean
  ai_insights_enabled: boolean
  auto_confirm_online: boolean
  default_rule_uuid: string | null
  default_reminder_rule_uuid: string | null
  reference_prefix: string
}

export interface SessionResponse {
  user: { uuid: string; name: string; kind: string; is_owner: boolean }
  company: { cmp_id: number; bo_id: number }
  permissions: string[]
  settings: AppointmentSettings
  features: Record<string, FeatureState>
  vocabulary: Vocabulary
}

// ---------------------------------------------------------------------------
// Dashboards
// ---------------------------------------------------------------------------

export interface Metric {
  id: string
  label: string
  value: number | null
  unit: 'count' | 'percent' | 'hours' | 'currency' | 'score'
  previous: number | null
  /** Null means there was nothing to compare against — never render it as 0%. */
  change_pct: number | null
  direction: 'up_is_good' | 'down_is_good' | 'neutral'
  note: string | null
  status: 'unavailable' | null
  unavailable_reason: string | null
  drilldown: { route: string; params: Record<string, string | number> } | null
}

export interface Insight {
  insight_uuid: string
  surface: string
  rule_key: string
  title: string
  body: string
  evidence: { kind: string; label: string; note?: string }[]
  rule_detail: Record<string, unknown>
  action: { route: string; label: string; params: Record<string, string | number> } | null
  /** 'rules' or 'ai' — which one wrote the PROSE. The figures were always ours. */
  origin: 'rules' | 'ai'
  generated_at: string
}

export interface Freshness {
  generated_at: string
  sources: { what: string; owner: string }[]
}

export interface PeriodInfo {
  preset: string
  label: string
  from: string
  to: string
  as_of: string
  timezone: string
  days: number
  compared_with: { from: string; to: string }
}

export interface DashboardResponse<TPanels = Record<string, unknown>> {
  view: string
  period: PeriodInfo
  currency: string
  metrics: Metric[]
  panels: TPanels
  insights: Insight[]
  freshness: Freshness
}

// --- Overview ---------------------------------------------------------------

export interface TimelineItem {
  kind: 'appointment' | 'available'
  booking_uuid?: string
  reference?: string
  starts_at: string | null
  local_time: string
  duration_minutes: number
  status: BookingStatus | 'AVAILABLE'
  mode?: AppointmentMode
  client_label?: string
  contact_uuid?: string | null
  service_name?: string
  member_uuid?: string | null
  member_label?: string | null
  attendee_count?: number
  has_form?: boolean
  calendar_sync_state?: string
  join_url?: string | null
}

export interface AttentionItem {
  key: string
  count: number
  title: string
  detail: string
  tone: 'danger' | 'warning' | 'info' | 'neutral'
  action: { route: string; params: Record<string, string> }
}

export interface BookingSource {
  source: string
  label: string
  total: number
  share: number
}

export interface OverviewPanels {
  schedule: { items: TimelineItem[]; appointment_count: number; calendar_available: boolean }
  attention: AttentionItem[]
  sources: BookingSource[]
  calendar: { available: boolean; message: string | null }
}

// --- Live operations --------------------------------------------------------

export interface HealthComponent {
  key: string
  label: string
  count: number
  share: number
  penalty: number
}

export interface ScheduleHealth {
  score: number
  band: 'good' | 'watch' | 'attention' | 'clear'
  appointments: number
  components: HealthComponent[]
  on_time_share: number
}

export interface TeamStatusRow {
  member_uuid: string
  label: string
  job_title: string | null
  state: 'available' | 'in_appointment' | 'break' | 'running_late'
  detail: string
  until: string | null
  late_by_minutes: number | null
  appointments_today: number
}

export interface ResourceStatusRow {
  resource_uuid: string
  name: string
  kind: string
  state: 'available' | 'occupied' | 'turnaround'
  detail: string
  until: string | null
  booking_uuid?: string
}

export interface WaitlistMatch {
  waitlist_uuid: string
  client_label: string
  contact_uuid: string | null
  score: number
  reasons: { key: string; label: string }[]
  waiting_days: number
  priority: number
  preferred_member_uuid: string | null
}

export interface LiveAppointmentRow {
  booking_uuid: string
  reference: string
  starts_at: string | null
  local_time: string
  status: BookingStatus
  mode: AppointmentMode
  client_label: string
  client_phone: string
  contact_uuid: string | null
  service_name: string
  member_label: string | null
  resource_name: string | null
  late_by_minutes: number | null
  calendar_sync_state: string
  join_url: string | null
  actions: { key: string; label: string; primary?: boolean }[]
}

export interface LivePanels {
  flow: { stages: { key: string; label: string; count: number; tone?: string }[]; being_booked: number }
  health: ScheduleHealth
  team: TeamStatusRow[]
  resources: ResourceStatusRow[]
  quick_fill: {
    available: boolean
    reason: string | null
    slot: {
      starts_at: string
      local_time: string
      duration_minutes: number
      service_uuid: string
      service_name: string
      member_uuid: string | null
    } | null
    matches: WaitlistMatch[]
  }
  appointments: LiveAppointmentRow[]
  refresh_seconds: number
}

// --- Capacity ---------------------------------------------------------------

export interface HeatmapCell {
  day_of_week: number
  load: number | null
  booked_minutes: number
  /** Nobody works this band on this day. Not "quiet" — not a cell that means anything. */
  closed: boolean
  intensity: 'low' | 'moderate' | 'high' | 'very_high' | 'full' | null
}

export interface CapacityPanels {
  heatmap: {
    bands: { label: string; cells: HeatmapCell[] }[]
    legend: { key: string; label: string }[]
    timezone: string
  }
  team: {
    member_uuid: string
    label: string
    job_title: string | null
    booked_hours: number
    available_hours: number
    appointments: number
    utilisation: number
  }[]
  services: { service_uuid: string; name: string; bookings: number; share: number }[]
  opportunities: {
    available: boolean
    message: string | null
    items: {
      starts_at: string
      ends_at: string
      local_label: string
      service_uuid: string
      service_name: string
      member_uuid: string | null
      member_label: string | null
      duration_minutes: number
      reason: string
      waitlist_matches: WaitlistMatch[]
    }[]
  }
  locations: {
    locations: {
      bo_id: number
      label: string
      appointments: number
      booked_hours: number
      available_hours: number
      utilisation: number
    }[]
    names_available: boolean
  }
  calendar_integration: {
    available: boolean
    state: string
    message: string | null
    conflicts: number | null
    external_connected: number | null
    external_accounts: { provider: string; status: string; label: string | null }[]
    last_sync_at: string | null
    primary_calendar?: string
  }
}

// --- Client experience ------------------------------------------------------

export interface FunnelStage {
  key: string
  label: string
  detail: string
  count: number | null
  /** False means nothing reports this stage — the bar is hatched, not zero. */
  measured: boolean
  unmeasured_reason?: string | null
  share: number | null
}

export interface ClientExperiencePanels {
  funnel: { stages: FunnelStage[]; base: number }
  reminders: {
    configured: boolean
    unavailable_reason: string | null
    channels: {
      channel: string
      label: string
      total: number
      delivered: number
      not_sent: number
      failed: number
      delivered_rate: number
    }[]
    outcomes: { outcome: string; label: string; total: number; share: number }[]
    total: number
    attribution_note: string
  }
  no_show: {
    rate: number
    previous: number
    trend: { week: string; rate: number; no_shows: number; expected: number }[]
    no_shows: number
    factors: { key: string; label: string; share: number; count: number }[]
    caveat: string
    indicators: { key: string; label: string; weight: number }[]
  }
  preparation: {
    total: number
    items: { key: string; label: string; count: number; share: number; tone: string }[]
  }
  top_clients: {
    contact_uuid: string
    label: string
    phone: string
    email: string
    appointments: number
    completed: number
    no_shows: number
    segment: 'new' | 'returning'
    usual_service: string | null
    preferred_slot: string
    last_appointment: string | null
    member_since: string
  }[]
}

// --- Intelligence -----------------------------------------------------------

export interface IntelligencePanels {
  demand_trend: {
    bucket: string
    series: { key: string; label: string }[]
    points: {
      at: string
      appointments: number
      bookings: number
      completions: number
      cancellations: number
    }[]
  }
  sources: BookingSource[]
  receptionist: {
    configured: boolean
    unavailable_reason: string | null
    deep_link: string | null
    attributed: { booked: number; rescheduled: number; cancelled: number }
    initiated: number | null
  }
  services: {
    service_uuid: string
    name: string
    bookings: number
    completions: number
    utilisation: number
    growth: number | null
    previous: number
  }[]
  staff: {
    member_uuid: string
    label: string
    job_title: string | null
    appointments: number
    completed: number
    no_shows: number
    utilisation: number
    rebook_rate: number
  }[]
  payments: {
    configured: boolean
    unavailable_reason: string | null
    connect_action: { route: string; label: string } | null
    currency: string
    deposit_required_minor: number
    collected_minor: number | null
    pending_minor: number | null
    refunded_minor: number | null
    appointment_value_minor: number | null
    benefits: string[]
  }
}

// ---------------------------------------------------------------------------
// Bookings, services, team
// ---------------------------------------------------------------------------

export interface Booking {
  booking_uuid: string
  reference: string
  status: BookingStatus
  mode: AppointmentMode
  booking_source: string
  starts_at: string | null
  ends_at: string | null
  timezone: string
  duration_minutes: number | null
  service: { service_uuid: string; name: string; form_uuid: string | null }
  member: { member_uuid: string; label: string | null } | null
  resource: { resource_uuid: string; name: string | null } | null
  client: { contact_uuid: string | null; name: string; email: string; phone: string }
  bo_id: number
  notes: string
  internal_notes: string
  /** Ids belonging to other products. Never their data. */
  references: {
    calendar_event_uuid: string | null
    payment_request_uuid: string | null
    connect_room_uuid: string | null
    connect_join_url: string | null
    receptionist_session_uuid: string | null
    crm_account_uuid: string | null
    billing_document_uuid: string | null
  }
  calendar: { state: string; error: string | null }
  payment_status: string | null
  lifecycle: {
    confirmed_at: string | null
    arrived_at: string | null
    started_at: string | null
    completed_at: string | null
    cancelled_at: string | null
    no_show_marked_at: string | null
    cancellation_reason: string | null
    reschedule_count: number
    rescheduled_from: string | null
  }
  rules_overridden: boolean
  override_reason: string | null
  created_at: string | null
}

export interface Service {
  service_uuid: string
  name: string
  description: string
  category: string
  colour: string | null
  duration_minutes: number
  buffer_before_mins: number
  buffer_after_mins: number
  min_notice_minutes: number
  booking_horizon_days: number
  capacity: number
  modes: AppointmentMode[]
  price_minor: number | null
  currency: string
  deposit_required: boolean
  deposit_minor: number | null
  form_uuid: string | null
  cancellation_rule_uuid: string | null
  reminder_rule_uuid: string | null
  is_active: boolean
  is_bookable_online: boolean
  sort_order: number
  bo_id: number
  staff_count: number | null
  upcoming_count: number | null
}

export interface TeamMember {
  member_uuid: string
  user_uuid: string
  calendar_subscriber_uuid: string
  display_label: string | null
  job_title: string | null
  accepts_bookings: boolean
  bookable_online: boolean
  buffer_before_mins: number | null
  buffer_after_mins: number | null
  max_daily_appointments: number | null
  timezone: string
  is_active: boolean
  service_count: number | null
  window_count: number | null
  label?: string
  confirmed_in_manage?: boolean
}

export interface AvailabilityWindow {
  availability_uuid: string
  member_uuid: string
  bo_id: number
  day_of_week: number
  starts_minute: number
  ends_minute: number
  kind: 'available' | 'blocked'
  effective_from: string | null
  effective_to: string | null
}

export interface Slot {
  starts_at: string
  ends_at: string
  local_time: string
  local_date: string
  daypart: string
  member_uuid: string | null
  member_label: string
  bo_id: number
  resource_uuids: string[]
  duration_minutes: number
}

export interface SlotsResponse {
  slots: Slot[]
  window: { from: string; to: string; timezone: string }
  service: {
    service_uuid: string
    name: string
    duration_minutes: number
    modes: AppointmentMode[]
    deposit_required: boolean
  }
  members: { member_uuid: string; display_label: string | null; job_title: string | null }[]
  calendar_available: boolean
}

export interface SlotHold {
  hold_uuid: string
  expires_at: string
  expires_in_seconds: number
  starts_at: string
  ends_at: string
}

export interface WaitlistEntry {
  waitlist_uuid: string
  status: 'waiting' | 'offered' | 'booked' | 'expired' | 'withdrawn'
  client: { contact_uuid: string | null; name: string; phone: string; email: string }
  service: { service_uuid: string; name: string | null }
  preferred_member: { member_uuid: string; label: string | null } | null
  earliest_date: string
  latest_date: string
  daypart: string
  weekdays: number[]
  priority: number
  notes: string
  bo_id: number
  offer: { offered_at: string; expires_at: string | null; slot_start: string | null } | null
  booked_booking_uuid: string | null
  expires_at: string | null
  created_at: string | null
}

export interface Integration {
  key: string
  name: string
  status: 'connected' | 'available' | 'setup_required' | 'coming_soon' | 'unavailable' | 'error'
  summary: string
  detail: string | null
  admin_hint: string | null
  required: boolean
  owns: string[]
}

export interface CalendarViewResponse {
  window: { from: string; to: string; timezone: string }
  business_hours: {
    day_starts_minute: number
    day_ends_minute: number
    week_starts_on: number
    slot_minutes: number
  }
  members: { member_uuid: string; label: string; job_title: string | null }[]
  appointments: {
    kind: 'appointment'
    booking_uuid: string
    reference: string
    starts_at: string | null
    ends_at: string | null
    local_date: string | null
    local_time: string | null
    status: BookingStatus
    mode: AppointmentMode
    title: string
    service_name: string
    colour: string | null
    member_uuid: string | null
    member_label: string | null
    resource_name: string | null
    contact_uuid: string | null
    calendar_sync_state: string
  }[]
  /** Everything else on the team's calendars — times only, never titles. */
  busy: {
    available: boolean
    message: string | null
    blocks: {
      kind: 'busy'
      member_uuid: string | null
      starts_at: string
      ends_at: string
      all_day: boolean
      status: string
      source: string
      event_id: string
    }[]
  }
}
