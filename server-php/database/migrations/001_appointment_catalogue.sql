-- ---------------------------------------------------------------------------
-- Aicountly Appointments — the catalogue: what can be booked, by whom, where.
--
-- WHAT IS NOT HERE, AND WILL NOT BE:
--
--   calendar events        Aicountly Calendar owns every event and every
--                          start/end time. An appointment row carries
--                          calendar_event_uuid and nothing else about time.
--   contacts               Aicountly Contacts owns client identity. We keep
--                          contact_uuid and appointment-shaped preferences.
--   staff / employees      Manage owns people. A team row is a uuid plus
--                          appointment settings, never a second employee master.
--   branches               Manage owns branches. A location row is bo_id plus
--                          appointment settings.
--   payments               Aicountly Pay owns money. We keep a policy and a
--                          payment_request_uuid.
--
-- Every table is scoped by cmp_id (company) and, where a thing belongs to one
-- branch, bo_id. bo_id = 0 means "the whole company".
-- ---------------------------------------------------------------------------

-- --- Services: the thing a client books ------------------------------------

CREATE TABLE IF NOT EXISTS appointment_services (
    service_uuid        UUID PRIMARY KEY,
    cmp_id              INTEGER      NOT NULL,
    bo_id               INTEGER      NOT NULL DEFAULT 0,

    name                TEXT         NOT NULL,
    description         TEXT         NOT NULL DEFAULT '',
    category            TEXT         NOT NULL DEFAULT 'general',
    -- A colour the business chose, so a week view is readable at a glance.
    colour              VARCHAR(9)   NULL,

    -- The appointment's own length. Buffers are separate because a 60 minute
    -- consultation with a 15 minute write-up is a 60 minute appointment that
    -- occupies 75 — telling a client it is 75 minutes is wrong, and booking the
    -- next one at +60 is also wrong.
    duration_minutes    INTEGER      NOT NULL CHECK (duration_minutes BETWEEN 5 AND 1440),
    buffer_before_mins  INTEGER      NOT NULL DEFAULT 0 CHECK (buffer_before_mins BETWEEN 0 AND 240),
    buffer_after_mins   INTEGER      NOT NULL DEFAULT 0 CHECK (buffer_after_mins BETWEEN 0 AND 240),

    -- How far ahead a client must book, and how far ahead they may. Both in
    -- minutes so "two hours' notice" and "three months ahead" use one unit.
    min_notice_minutes  INTEGER      NOT NULL DEFAULT 120 CHECK (min_notice_minutes >= 0),
    booking_horizon_days INTEGER     NOT NULL DEFAULT 60 CHECK (booking_horizon_days BETWEEN 1 AND 730),

    -- More than one client in the same slot: a class, a group session.
    capacity            INTEGER      NOT NULL DEFAULT 1 CHECK (capacity BETWEEN 1 AND 500),

    -- IN_PERSON | PHONE | AICOUNTLY_CONNECT | EXTERNAL_VIDEO, as a list of what
    -- this service supports. The booking picks one of them.
    modes               JSONB        NOT NULL DEFAULT '["IN_PERSON"]'::jsonb,

    -- Display price only. What was actually charged is Pay's, and what was
    -- invoiced is Billing's.
    price_minor         BIGINT       NULL CHECK (price_minor IS NULL OR price_minor >= 0),
    currency            VARCHAR(3)   NOT NULL DEFAULT 'INR',

    -- Policy: how much must be paid before the slot is held, as a reference to
    -- the rules below. Actual collection is Pay's.
    deposit_required    BOOLEAN      NOT NULL DEFAULT FALSE,
    deposit_minor       BIGINT       NULL CHECK (deposit_minor IS NULL OR deposit_minor >= 0),

    form_uuid           UUID         NULL,
    cancellation_rule_uuid UUID      NULL,
    no_show_rule_uuid   UUID         NULL,
    reminder_rule_uuid  UUID         NULL,

    is_active           BOOLEAN      NOT NULL DEFAULT TRUE,
    is_bookable_online  BOOLEAN      NOT NULL DEFAULT TRUE,
    sort_order          INTEGER      NOT NULL DEFAULT 0,

    created_by_uuid     TEXT         NULL,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_appt_services_scope ON appointment_services (cmp_id, bo_id, is_active);
CREATE UNIQUE INDEX IF NOT EXISTS idx_appt_services_name ON appointment_services (cmp_id, lower(name)) WHERE is_active;

-- --- Team: appointment settings for a person Manage already knows -----------

CREATE TABLE IF NOT EXISTS appointment_team_members (
    member_uuid         UUID PRIMARY KEY,
    cmp_id              INTEGER      NOT NULL,

    -- The person, per Manage and the portal. NOT a copy of them: no name, no
    -- email, no phone. Those are read live and are already right.
    user_uuid           TEXT         NOT NULL,

    -- The calendar this person's appointments are written to. Normally the same
    -- as user_uuid — it is separate because a shared resource diary (a room
    -- calendar) is also a subscriber, and because a business may run a shared
    -- front-desk calendar for casual staff.
    calendar_subscriber_uuid TEXT    NOT NULL,

    -- What they are called in the booking UI when Manage has no display name
    -- yet. A fallback, not a master.
    display_label       TEXT         NULL,
    job_title           TEXT         NULL,

    accepts_bookings    BOOLEAN      NOT NULL DEFAULT TRUE,
    bookable_online     BOOLEAN      NOT NULL DEFAULT TRUE,

    -- Per-person overrides. NULL means "use the service's".
    buffer_before_mins  INTEGER      NULL CHECK (buffer_before_mins IS NULL OR buffer_before_mins >= 0),
    buffer_after_mins   INTEGER      NULL CHECK (buffer_after_mins IS NULL OR buffer_after_mins >= 0),
    max_daily_appointments INTEGER   NULL CHECK (max_daily_appointments IS NULL OR max_daily_appointments > 0),

    timezone            VARCHAR(64)  NOT NULL DEFAULT 'Asia/Kolkata',

    is_active           BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_appt_team_person ON appointment_team_members (cmp_id, user_uuid);
CREATE INDEX IF NOT EXISTS idx_appt_team_active ON appointment_team_members (cmp_id, is_active, accepts_bookings);

-- --- Working hours: when a team member is open to bookings -----------------
--
-- NOT the same thing as free/busy. This says "Dr Sharma takes appointments
-- Monday to Friday, 9 to 1 and 2 to 6"; Calendar says whether 10:30 next
-- Tuesday is already taken. Both are needed and neither replaces the other.

CREATE TABLE IF NOT EXISTS appointment_team_availability (
    availability_uuid   UUID PRIMARY KEY,
    cmp_id              INTEGER      NOT NULL,
    member_uuid         UUID         NOT NULL REFERENCES appointment_team_members (member_uuid) ON DELETE CASCADE,
    bo_id               INTEGER      NOT NULL DEFAULT 0,

    -- 0 = Sunday … 6 = Saturday, matching PostgreSQL's EXTRACT(DOW).
    day_of_week         SMALLINT     NOT NULL CHECK (day_of_week BETWEEN 0 AND 6),
    -- Minutes from midnight in the member's timezone. Integers, not TIME,
    -- because slot arithmetic is addition and a TIME column makes it a cast.
    starts_minute       SMALLINT     NOT NULL CHECK (starts_minute BETWEEN 0 AND 1440),
    ends_minute         SMALLINT     NOT NULL CHECK (ends_minute BETWEEN 0 AND 1440),

    -- A window can be an opening or a closing: lunch is a block inside the day.
    kind                VARCHAR(16)  NOT NULL DEFAULT 'available'
                        CHECK (kind IN ('available', 'blocked')),

    -- A date range makes this a temporary pattern: summer hours, maternity
    -- cover, a locum for three weeks. NULL on both = the standing pattern.
    effective_from      DATE         NULL,
    effective_to        DATE         NULL,

    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CHECK (ends_minute > starts_minute)
);

CREATE INDEX IF NOT EXISTS idx_appt_availability_member ON appointment_team_availability (cmp_id, member_uuid, day_of_week);

-- --- Which staff may deliver which service ---------------------------------

CREATE TABLE IF NOT EXISTS appointment_service_staff (
    service_uuid        UUID         NOT NULL REFERENCES appointment_services (service_uuid) ON DELETE CASCADE,
    member_uuid         UUID         NOT NULL REFERENCES appointment_team_members (member_uuid) ON DELETE CASCADE,
    cmp_id              INTEGER      NOT NULL,

    -- Some people are the first choice for a service and some are cover.
    -- "Any available" prefers the first.
    priority            SMALLINT     NOT NULL DEFAULT 0,
    -- A service can take longer with a trainee than with a senior.
    duration_override_minutes INTEGER NULL CHECK (duration_override_minutes IS NULL OR duration_override_minutes > 0),

    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    PRIMARY KEY (service_uuid, member_uuid)
);

CREATE INDEX IF NOT EXISTS idx_appt_service_staff_member ON appointment_service_staff (cmp_id, member_uuid);

-- --- Locations: appointment settings for a Manage branch -------------------

CREATE TABLE IF NOT EXISTS appointment_locations_config (
    location_uuid       UUID PRIMARY KEY,
    cmp_id              INTEGER      NOT NULL,
    -- The Manage branch. Name, address and contact details are read from
    -- Manage; nothing about the branch itself is stored here.
    bo_id               INTEGER      NOT NULL,

    accepts_bookings    BOOLEAN      NOT NULL DEFAULT TRUE,
    bookable_online     BOOLEAN      NOT NULL DEFAULT TRUE,
    timezone            VARCHAR(64)  NOT NULL DEFAULT 'Asia/Kolkata',
    -- Directions, parking, "second floor, ask at reception" — the sentence that
    -- goes into a confirmation message. Appointment-shaped, so it lives here.
    arrival_instructions TEXT        NOT NULL DEFAULT '',

    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_appt_locations_branch ON appointment_locations_config (cmp_id, bo_id);

-- --- Resources: rooms, cabins, chairs, equipment ---------------------------

CREATE TABLE IF NOT EXISTS appointment_resources (
    resource_uuid       UUID PRIMARY KEY,
    cmp_id              INTEGER      NOT NULL,
    bo_id               INTEGER      NOT NULL DEFAULT 0,

    name                TEXT         NOT NULL,
    kind                VARCHAR(32)  NOT NULL DEFAULT 'room'
                        CHECK (kind IN ('room', 'cabin', 'chair', 'equipment', 'vehicle', 'other')),
    capacity            INTEGER      NOT NULL DEFAULT 1 CHECK (capacity >= 1),

    -- A resource can have its own calendar in Aicountly Calendar, which is how
    -- a room's occupancy stays visible to everyone. When it does, bookings
    -- against it are conflict-checked there like a person's.
    calendar_subscriber_uuid TEXT    NULL,

    -- Cleaning, sanitisation, resetting between clients.
    turnaround_minutes  INTEGER      NOT NULL DEFAULT 0 CHECK (turnaround_minutes >= 0),

    is_active           BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_appt_resources_scope ON appointment_resources (cmp_id, bo_id, is_active);

CREATE TABLE IF NOT EXISTS appointment_service_resources (
    service_uuid        UUID         NOT NULL REFERENCES appointment_services (service_uuid) ON DELETE CASCADE,
    resource_uuid       UUID         NOT NULL REFERENCES appointment_resources (resource_uuid) ON DELETE CASCADE,
    cmp_id              INTEGER      NOT NULL,
    -- A service that cannot happen without a room is required; a service that
    -- would like the good chair is not.
    is_required         BOOLEAN      NOT NULL DEFAULT TRUE,

    PRIMARY KEY (service_uuid, resource_uuid)
);

CREATE TABLE IF NOT EXISTS appointment_service_locations (
    service_uuid        UUID         NOT NULL REFERENCES appointment_services (service_uuid) ON DELETE CASCADE,
    bo_id               INTEGER      NOT NULL,
    cmp_id              INTEGER      NOT NULL,

    PRIMARY KEY (service_uuid, bo_id)
);
