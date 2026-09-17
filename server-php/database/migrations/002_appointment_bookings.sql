-- ---------------------------------------------------------------------------
-- Bookings — the appointment itself, and the short-lived holds that stop two
-- people taking the same slot.
--
-- READ THE TIME COLUMNS CAREFULLY. `starts_at` and `ends_at` are here, and they
-- are NOT a copy of the Calendar event. They are the booking's INTENT: what was
-- agreed when it was made, what the reminder timer counts down to, what the
-- cancellation window is measured against. Calendar holds the event and remains
-- the authority on whether the practitioner has since moved it; every screen
-- that shows "when is this appointment" reads Calendar.
--
-- The distinction earns its keep the moment a practitioner drags the event
-- half an hour later in Google. The Calendar event moves. The booking's agreed
-- time did not — nobody told the client — and the product needs to be able to
-- say so rather than quietly agreeing with whichever copy it read last.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS appointment_bookings (
    booking_uuid        UUID PRIMARY KEY,
    cmp_id              INTEGER      NOT NULL,
    bo_id               INTEGER      NOT NULL DEFAULT 0,

    -- Human-facing reference: AP-1042. Unique per company.
    reference           TEXT         NOT NULL,

    service_uuid        UUID         NOT NULL REFERENCES appointment_services (service_uuid),
    member_uuid         UUID         NULL REFERENCES appointment_team_members (member_uuid),
    resource_uuid       UUID         NULL REFERENCES appointment_resources (resource_uuid),

    -- Aicountly Contacts owns the person. This is a reference.
    contact_uuid        TEXT         NULL,
    -- What the client typed on a public booking page before they were matched
    -- to a contact. Kept only until the contact exists, and only because a
    -- booking with no way to reach the client is not a booking.
    client_name         TEXT         NOT NULL DEFAULT '',
    client_email        TEXT         NOT NULL DEFAULT '',
    client_phone        TEXT         NOT NULL DEFAULT '',

    -- The agreed time. See the note above: intent, not the event.
    starts_at           TIMESTAMPTZ  NOT NULL,
    ends_at             TIMESTAMPTZ  NOT NULL,
    timezone            VARCHAR(64)  NOT NULL DEFAULT 'Asia/Kolkata',

    status              VARCHAR(24)  NOT NULL DEFAULT 'PENDING'
                        CHECK (status IN (
                            'DRAFT', 'PENDING', 'CONFIRMED', 'ARRIVED', 'IN_PROGRESS',
                            'COMPLETED', 'RESCHEDULED', 'CANCELLED', 'NO_SHOW'
                        )),

    mode                VARCHAR(24)  NOT NULL DEFAULT 'IN_PERSON'
                        CHECK (mode IN ('IN_PERSON', 'PHONE', 'AICOUNTLY_CONNECT', 'EXTERNAL_VIDEO')),

    -- Proven by the credential that created it, never taken from the body.
    booking_source      VARCHAR(32)  NOT NULL DEFAULT 'STAFF_BOOKING'
                        CHECK (booking_source IN (
                            'ONLINE_BOOKING', 'STAFF_BOOKING', 'RECEPTIONIST', 'CRM',
                            'SALES', 'POS', 'API_INTEGRATION', 'WAITLIST_OFFER', 'OTHER'
                        )),

    -- ------------------------------------------------------------------
    -- References to other products. Each is a uuid and nothing more: no
    -- copied event times, no gateway data, no call transcripts, no invoice
    -- lines. The owning product stays canonical for all of it.
    -- ------------------------------------------------------------------
    calendar_event_uuid TEXT         NULL,
    -- Which subscriber's calendar the event was written to. Needed to read it
    -- back, since Calendar scopes events per subscriber.
    calendar_subscriber_uuid TEXT    NULL,
    -- Set when the Calendar write failed and the booking is being retried, so a
    -- booking without an event is visible rather than silently timeless.
    calendar_sync_state VARCHAR(24)  NOT NULL DEFAULT 'pending'
                        CHECK (calendar_sync_state IN ('pending', 'synced', 'failed', 'not_required')),
    calendar_sync_error TEXT         NULL,

    payment_request_uuid TEXT        NULL,
    payment_status      VARCHAR(24)  NULL,
    receptionist_session_uuid TEXT   NULL,
    connect_room_uuid   TEXT         NULL,
    connect_join_url    TEXT         NULL,
    crm_account_uuid    TEXT         NULL,
    billing_document_uuid TEXT       NULL,

    -- ------------------------------------------------------------------
    -- Appointment workflow, all ours.
    -- ------------------------------------------------------------------
    confirmed_at        TIMESTAMPTZ  NULL,
    confirmed_by_uuid   TEXT         NULL,
    arrived_at          TIMESTAMPTZ  NULL,
    started_at          TIMESTAMPTZ  NULL,
    completed_at        TIMESTAMPTZ  NULL,
    cancelled_at        TIMESTAMPTZ  NULL,
    cancelled_by_uuid   TEXT         NULL,
    cancellation_reason TEXT         NULL,
    no_show_marked_at   TIMESTAMPTZ  NULL,

    -- Rescheduling keeps the chain rather than editing in place, so "moved
    -- three times" is a fact the dashboard can show and a no-show risk
    -- indicator can use.
    rescheduled_from_uuid UUID       NULL,
    reschedule_count    SMALLINT     NOT NULL DEFAULT 0,

    notes               TEXT         NOT NULL DEFAULT '',
    internal_notes      TEXT         NOT NULL DEFAULT '',

    -- A booking made outside the rules, and who allowed it.
    rules_overridden    BOOLEAN      NOT NULL DEFAULT FALSE,
    override_reason     TEXT         NULL,

    created_by_uuid     TEXT         NULL,
    created_by_kind     VARCHAR(16)  NOT NULL DEFAULT 'user',
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CHECK (ends_at > starts_at)
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_appt_bookings_reference ON appointment_bookings (cmp_id, reference);
-- The index every dashboard and every day view uses.
CREATE INDEX IF NOT EXISTS idx_appt_bookings_window ON appointment_bookings (cmp_id, starts_at, status);
CREATE INDEX IF NOT EXISTS idx_appt_bookings_member ON appointment_bookings (cmp_id, member_uuid, starts_at);
CREATE INDEX IF NOT EXISTS idx_appt_bookings_contact ON appointment_bookings (cmp_id, contact_uuid, starts_at DESC);
CREATE INDEX IF NOT EXISTS idx_appt_bookings_service ON appointment_bookings (cmp_id, service_uuid, starts_at);
-- Finding the bookings whose calendar event never got written.
CREATE INDEX IF NOT EXISTS idx_appt_bookings_calendar_pending ON appointment_bookings (cmp_id, calendar_sync_state)
    WHERE calendar_sync_state IN ('pending', 'failed');

-- --- Booking metadata: the answers to a form, and anything else per booking -

CREATE TABLE IF NOT EXISTS appointment_booking_metadata (
    booking_uuid        UUID         NOT NULL REFERENCES appointment_bookings (booking_uuid) ON DELETE CASCADE,
    cmp_id              INTEGER      NOT NULL,
    -- form_answers | preparation | consent | source_detail
    kind                VARCHAR(32)  NOT NULL,
    payload             JSONB        NOT NULL DEFAULT '{}'::jsonb,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    PRIMARY KEY (booking_uuid, kind)
);

-- --- Attendees: the extra people on a group or team booking ----------------

CREATE TABLE IF NOT EXISTS appointment_booking_attendees (
    attendee_uuid       UUID PRIMARY KEY,
    booking_uuid        UUID         NOT NULL REFERENCES appointment_bookings (booking_uuid) ON DELETE CASCADE,
    cmp_id              INTEGER      NOT NULL,
    contact_uuid        TEXT         NULL,
    display_name        TEXT         NOT NULL DEFAULT '',
    email               TEXT         NOT NULL DEFAULT '',
    attendance          VARCHAR(16)  NOT NULL DEFAULT 'expected'
                        CHECK (attendance IN ('expected', 'attended', 'no_show', 'cancelled')),
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_appt_attendees_booking ON appointment_booking_attendees (booking_uuid);

-- --- Slot holds: the two-minute promise ------------------------------------
--
-- Two clients open the same 3pm. One starts filling in the form. Without a hold
-- the second one also books it and somebody gets a phone call. Calendar has no
-- concept of a tentative claim that expires, and inventing one there would put
-- booking-flow state into the product that owns diaries — so the hold lives
-- here, where the booking coordination is, and Calendar stays canonical for the
-- event that eventually gets written.
--
-- A hold is deliberately short. It is not a reservation, it is the length of a
-- checkout.

CREATE TABLE IF NOT EXISTS appointment_slot_holds (
    hold_uuid           UUID PRIMARY KEY,
    cmp_id              INTEGER      NOT NULL,
    bo_id               INTEGER      NOT NULL DEFAULT 0,

    service_uuid        UUID         NOT NULL REFERENCES appointment_services (service_uuid) ON DELETE CASCADE,
    member_uuid         UUID         NULL REFERENCES appointment_team_members (member_uuid) ON DELETE CASCADE,
    resource_uuid       UUID         NULL REFERENCES appointment_resources (resource_uuid) ON DELETE CASCADE,

    starts_at           TIMESTAMPTZ  NOT NULL,
    ends_at             TIMESTAMPTZ  NOT NULL,

    -- Who is holding it: a session fingerprint, a receptionist call, a public
    -- booking token. Never a name.
    held_by             TEXT         NOT NULL,
    held_by_kind        VARCHAR(16)  NOT NULL DEFAULT 'user',

    expires_at          TIMESTAMPTZ  NOT NULL,
    -- Set when the hold became a booking, so the row is a record rather than a
    -- gap in the audit trail.
    consumed_by_booking UUID         NULL,
    released_at         TIMESTAMPTZ  NULL,

    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CHECK (ends_at > starts_at)
);

-- The lookup that runs on every availability request: live holds in a window.
CREATE INDEX IF NOT EXISTS idx_appt_holds_live ON appointment_slot_holds (cmp_id, starts_at, expires_at)
    WHERE consumed_by_booking IS NULL AND released_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_appt_holds_member ON appointment_slot_holds (cmp_id, member_uuid, starts_at);

-- --- Idempotency: the same request twice is the same booking once ----------
--
-- A client on a bad connection taps Book twice. Receptionist retries a timed-out
-- call. Both must produce one appointment, so the key is recorded with the
-- result and replayed rather than re-executed.

CREATE TABLE IF NOT EXISTS appointment_idempotency_keys (
    idempotency_key     TEXT         NOT NULL,
    cmp_id              INTEGER      NOT NULL,
    scope               VARCHAR(48)  NOT NULL,
    -- The response that was sent the first time, replayed verbatim.
    response_status     SMALLINT     NOT NULL,
    response_body       JSONB        NOT NULL,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    PRIMARY KEY (cmp_id, scope, idempotency_key)
);

CREATE INDEX IF NOT EXISTS idx_appt_idempotency_age ON appointment_idempotency_keys (created_at);
