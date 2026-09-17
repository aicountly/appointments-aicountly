-- ---------------------------------------------------------------------------
-- Policies, forms, reminders, the waitlist and client preferences.
--
-- These are the tables that make Appointments a product rather than a diary
-- with a web front end. Every one of them is Appointments-owned: nothing here
-- duplicates Calendar, Contacts, Pay or CRM.
-- ---------------------------------------------------------------------------

-- --- Booking rules: one policy set a service can point at -------------------

CREATE TABLE IF NOT EXISTS appointment_booking_rules (
    rule_uuid           UUID PRIMARY KEY,
    cmp_id              INTEGER      NOT NULL,
    name                TEXT         NOT NULL,

    -- CANCELLATION
    cancellation_notice_hours INTEGER NOT NULL DEFAULT 24 CHECK (cancellation_notice_hours >= 0),
    -- Charged when a client cancels inside the notice period. A POLICY. The
    -- money is Pay's; this is the number Pay is asked for.
    late_cancellation_fee_minor BIGINT NULL CHECK (late_cancellation_fee_minor IS NULL OR late_cancellation_fee_minor >= 0),
    allow_client_cancellation BOOLEAN NOT NULL DEFAULT TRUE,

    -- RESCHEDULING
    reschedule_notice_hours   INTEGER NOT NULL DEFAULT 12 CHECK (reschedule_notice_hours >= 0),
    max_reschedules           SMALLINT NOT NULL DEFAULT 3 CHECK (max_reschedules >= 0),
    allow_client_reschedule   BOOLEAN NOT NULL DEFAULT TRUE,

    -- NO-SHOW
    no_show_after_minutes     INTEGER NOT NULL DEFAULT 15 CHECK (no_show_after_minutes >= 0),
    no_show_fee_minor         BIGINT  NULL CHECK (no_show_fee_minor IS NULL OR no_show_fee_minor >= 0),
    -- After this many no-shows a client must prepay. Policy only; enforcement
    -- asks Pay when the time comes.
    no_show_prepay_threshold  SMALLINT NULL CHECK (no_show_prepay_threshold IS NULL OR no_show_prepay_threshold > 0),

    -- CONFIRMATION
    -- A booking that needs a human yes before it counts as confirmed.
    requires_confirmation     BOOLEAN NOT NULL DEFAULT FALSE,
    -- A PENDING booking that nobody confirmed within this many hours is
    -- released back to the diary rather than blocking it forever.
    auto_release_unconfirmed_hours INTEGER NULL CHECK (auto_release_unconfirmed_hours IS NULL OR auto_release_unconfirmed_hours > 0),

    is_default          BOOLEAN      NOT NULL DEFAULT FALSE,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_appt_rules_company ON appointment_booking_rules (cmp_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_appt_rules_default ON appointment_booking_rules (cmp_id) WHERE is_default;

-- --- Forms: what a client is asked when they book ---------------------------

CREATE TABLE IF NOT EXISTS appointment_forms (
    form_uuid           UUID PRIMARY KEY,
    cmp_id              INTEGER      NOT NULL,
    name                TEXT         NOT NULL,
    description         TEXT         NOT NULL DEFAULT '',
    -- Answers to a form marked sensitive are shown only to somebody holding
    -- appointments.clients.manage, and never on a public or shared screen.
    is_sensitive        BOOLEAN      NOT NULL DEFAULT FALSE,
    is_active           BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_appt_forms_company ON appointment_forms (cmp_id, is_active);

CREATE TABLE IF NOT EXISTS appointment_form_fields (
    field_uuid          UUID PRIMARY KEY,
    form_uuid           UUID         NOT NULL REFERENCES appointment_forms (form_uuid) ON DELETE CASCADE,
    cmp_id              INTEGER      NOT NULL,

    label               TEXT         NOT NULL,
    help_text           TEXT         NOT NULL DEFAULT '',
    field_type          VARCHAR(24)  NOT NULL
                        CHECK (field_type IN (
                            'text', 'textarea', 'select', 'radio', 'checkbox',
                            'date', 'number', 'phone', 'email', 'consent'
                        )),
    -- select / radio / checkbox choices.
    options             JSONB        NOT NULL DEFAULT '[]'::jsonb,
    is_required         BOOLEAN      NOT NULL DEFAULT FALSE,
    sort_order          INTEGER      NOT NULL DEFAULT 0,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_appt_form_fields ON appointment_form_fields (form_uuid, sort_order);

-- --- Reminder rules and the reminders they produce --------------------------
--
-- APPOINTMENTS OWNS ITS OWN TIMERS. There is no central automation engine in
-- this fleet to hand a reminder to, and there should not be: a reminder is a
-- fact about an appointment, and the product that knows the appointment moved
-- is the one that must move the reminder.
--
-- Delivery is somebody else's — see Clients/MessagingClient.php.

CREATE TABLE IF NOT EXISTS appointment_reminder_rules (
    reminder_rule_uuid  UUID PRIMARY KEY,
    cmp_id              INTEGER      NOT NULL,
    name                TEXT         NOT NULL,
    is_default          BOOLEAN      NOT NULL DEFAULT FALSE,
    is_active           BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_appt_reminder_rules_default ON appointment_reminder_rules (cmp_id) WHERE is_default;

CREATE TABLE IF NOT EXISTS appointment_reminder_steps (
    step_uuid           UUID PRIMARY KEY,
    reminder_rule_uuid  UUID         NOT NULL REFERENCES appointment_reminder_rules (reminder_rule_uuid) ON DELETE CASCADE,
    cmp_id              INTEGER      NOT NULL,

    -- 0 = at the moment of booking (the confirmation). Otherwise minutes
    -- BEFORE the appointment starts.
    offset_minutes      INTEGER      NOT NULL DEFAULT 1440 CHECK (offset_minutes >= 0),
    channel             VARCHAR(16)  NOT NULL
                        CHECK (channel IN ('email', 'sms', 'whatsapp', 'voice')),
    template_key        TEXT         NOT NULL DEFAULT 'appointment_reminder',
    -- Skip this step for a booking that is already confirmed.
    only_if_unconfirmed BOOLEAN      NOT NULL DEFAULT FALSE,
    is_active           BOOLEAN      NOT NULL DEFAULT TRUE,

    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_appt_reminder_steps ON appointment_reminder_steps (reminder_rule_uuid, offset_minutes);

CREATE TABLE IF NOT EXISTS appointment_reminders (
    reminder_uuid       UUID PRIMARY KEY,
    cmp_id              INTEGER      NOT NULL,
    booking_uuid        UUID         NOT NULL REFERENCES appointment_bookings (booking_uuid) ON DELETE CASCADE,
    step_uuid           UUID         NULL,

    channel             VARCHAR(16)  NOT NULL,
    scheduled_for       TIMESTAMPTZ  NOT NULL,

    status              VARCHAR(24)  NOT NULL DEFAULT 'scheduled'
                        CHECK (status IN ('scheduled', 'sent', 'delivered', 'failed', 'skipped', 'not_sent')),
    -- Why a reminder was not sent, in words. "Messaging not connected" is the
    -- common one and it must be visible rather than silently absent.
    status_detail       TEXT         NULL,
    -- The messaging product's id, so delivery can be looked up there. Not a
    -- copy of the message.
    message_reference   TEXT         NULL,

    -- What the client did afterwards, which is the only reason to measure
    -- reminders at all.
    outcome             VARCHAR(24)  NULL
                        CHECK (outcome IS NULL OR outcome IN ('confirmed', 'rescheduled', 'cancelled', 'no_action')),
    outcome_at          TIMESTAMPTZ  NULL,

    sent_at             TIMESTAMPTZ  NULL,
    attempts            SMALLINT     NOT NULL DEFAULT 0,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- The due-reminder sweep. Partial, because the table is mostly history.
CREATE INDEX IF NOT EXISTS idx_appt_reminders_due ON appointment_reminders (scheduled_for)
    WHERE status = 'scheduled';
CREATE INDEX IF NOT EXISTS idx_appt_reminders_booking ON appointment_reminders (booking_uuid);
CREATE INDEX IF NOT EXISTS idx_appt_reminders_window ON appointment_reminders (cmp_id, created_at);

-- --- Waitlist ---------------------------------------------------------------

CREATE TABLE IF NOT EXISTS appointment_waitlist (
    waitlist_uuid       UUID PRIMARY KEY,
    cmp_id              INTEGER      NOT NULL,
    bo_id               INTEGER      NOT NULL DEFAULT 0,

    contact_uuid        TEXT         NULL,
    client_name         TEXT         NOT NULL DEFAULT '',
    client_phone        TEXT         NOT NULL DEFAULT '',
    client_email        TEXT         NOT NULL DEFAULT '',

    service_uuid        UUID         NOT NULL REFERENCES appointment_services (service_uuid) ON DELETE CASCADE,
    -- NULL = anybody.
    preferred_member_uuid UUID       NULL REFERENCES appointment_team_members (member_uuid) ON DELETE SET NULL,

    earliest_date       DATE         NOT NULL,
    latest_date         DATE         NOT NULL,
    -- morning | afternoon | evening | any — matched against the slot's local
    -- start time.
    daypart             VARCHAR(16)  NOT NULL DEFAULT 'any'
                        CHECK (daypart IN ('morning', 'afternoon', 'evening', 'any')),
    -- Which weekdays suit. Empty = any.
    weekdays            JSONB        NOT NULL DEFAULT '[]'::jsonb,

    priority            SMALLINT     NOT NULL DEFAULT 0,
    notes               TEXT         NOT NULL DEFAULT '',

    status              VARCHAR(24)  NOT NULL DEFAULT 'waiting'
                        CHECK (status IN ('waiting', 'offered', 'booked', 'expired', 'withdrawn')),
    offered_at          TIMESTAMPTZ  NULL,
    offer_expires_at    TIMESTAMPTZ  NULL,
    offered_slot_start  TIMESTAMPTZ  NULL,
    booked_booking_uuid UUID         NULL,
    expires_at          TIMESTAMPTZ  NULL,

    created_by_uuid     TEXT         NULL,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CHECK (latest_date >= earliest_date)
);

CREATE INDEX IF NOT EXISTS idx_appt_waitlist_matching ON appointment_waitlist (cmp_id, service_uuid, status, earliest_date);
CREATE INDEX IF NOT EXISTS idx_appt_waitlist_contact ON appointment_waitlist (cmp_id, contact_uuid);

-- --- Client appointment preferences ----------------------------------------
--
-- Keyed on contact_uuid, which Contacts owns. Everything IN here is
-- appointment-shaped and belongs nowhere else: which practitioner they ask for,
-- which channel they actually answer, how many times they have not turned up.

CREATE TABLE IF NOT EXISTS appointment_client_preferences (
    cmp_id              INTEGER      NOT NULL,
    contact_uuid        TEXT         NOT NULL,

    preferred_member_uuid UUID       NULL REFERENCES appointment_team_members (member_uuid) ON DELETE SET NULL,
    preferred_bo_id     INTEGER      NULL,
    preferred_daypart   VARCHAR(16)  NULL,
    preferred_channel   VARCHAR(16)  NULL
                        CHECK (preferred_channel IS NULL OR preferred_channel IN ('email', 'sms', 'whatsapp', 'voice')),
    preferred_language  VARCHAR(16)  NULL,
    timezone            VARCHAR(64)  NULL,

    -- Counters maintained by this product from its own bookings. Not derived
    -- from anybody else's data and not a CRM health score.
    total_bookings      INTEGER      NOT NULL DEFAULT 0,
    completed_count     INTEGER      NOT NULL DEFAULT 0,
    no_show_count       INTEGER      NOT NULL DEFAULT 0,
    late_cancel_count   INTEGER      NOT NULL DEFAULT 0,
    last_booking_at     TIMESTAMPTZ  NULL,

    notes               TEXT         NOT NULL DEFAULT '',
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    PRIMARY KEY (cmp_id, contact_uuid)
);

-- --- Public booking pages ---------------------------------------------------

CREATE TABLE IF NOT EXISTS appointment_booking_pages (
    page_uuid           UUID PRIMARY KEY,
    cmp_id              INTEGER      NOT NULL,
    bo_id               INTEGER      NOT NULL DEFAULT 0,

    -- The public path segment: /b/{slug}. Never an internal id, so a booking
    -- link cannot be enumerated into somebody else's company.
    slug                TEXT         NOT NULL,
    kind                VARCHAR(16)  NOT NULL DEFAULT 'business'
                        CHECK (kind IN ('business', 'service', 'staff')),

    -- For a service or staff page.
    service_uuid        UUID         NULL REFERENCES appointment_services (service_uuid) ON DELETE CASCADE,
    member_uuid         UUID         NULL REFERENCES appointment_team_members (member_uuid) ON DELETE CASCADE,

    headline            TEXT         NOT NULL DEFAULT '',
    intro               TEXT         NOT NULL DEFAULT '',
    brand_colour        VARCHAR(9)   NULL,
    logo_url            TEXT         NULL,
    terms               TEXT         NOT NULL DEFAULT '',
    timezone            VARCHAR(64)  NOT NULL DEFAULT 'Asia/Kolkata',
    locale              VARCHAR(16)  NOT NULL DEFAULT 'en-IN',

    -- Which services this page offers. Empty = every online-bookable service.
    service_uuids       JSONB        NOT NULL DEFAULT '[]'::jsonb,

    require_client_phone BOOLEAN     NOT NULL DEFAULT TRUE,
    require_client_email BOOLEAN     NOT NULL DEFAULT TRUE,

    is_published        BOOLEAN      NOT NULL DEFAULT FALSE,
    published_at        TIMESTAMPTZ  NULL,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- Slugs are global, not per company: two companies cannot both own /b/clinic.
CREATE UNIQUE INDEX IF NOT EXISTS idx_appt_pages_slug ON appointment_booking_pages (lower(slug));
CREATE INDEX IF NOT EXISTS idx_appt_pages_company ON appointment_booking_pages (cmp_id, is_published);

-- --- Public booking rate limiting -------------------------------------------
--
-- The public endpoints have no session to rate-limit against, so the limit is
-- kept here against a hashed client fingerprint. Hashed because an IP address
-- sitting in a product database for a year is a liability with no benefit.

CREATE TABLE IF NOT EXISTS appointment_public_rate_limits (
    fingerprint         TEXT         NOT NULL,
    window_start        TIMESTAMPTZ  NOT NULL,
    scope               VARCHAR(32)  NOT NULL,
    hits                INTEGER      NOT NULL DEFAULT 1,

    PRIMARY KEY (fingerprint, scope, window_start)
);

CREATE INDEX IF NOT EXISTS idx_appt_rate_limit_age ON appointment_public_rate_limits (window_start);
