-- ---------------------------------------------------------------------------
-- Access, audit, settings and the AI insight cache.
-- ---------------------------------------------------------------------------

-- --- Permission profiles ----------------------------------------------------

CREATE TABLE IF NOT EXISTS appointment_permission_profiles (
    profile_id          BIGSERIAL PRIMARY KEY,
    cmp_id              INTEGER      NOT NULL,
    name                TEXT         NOT NULL,
    description         TEXT         NOT NULL DEFAULT '',
    -- A list of permission strings from Permissions::CATALOG.
    permissions         JSONB        NOT NULL DEFAULT '[]'::jsonb,
    is_active           BOOLEAN      NOT NULL DEFAULT TRUE,
    created_by_uuid     TEXT         NULL,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_appt_profiles_name ON appointment_permission_profiles (cmp_id, lower(name));

CREATE TABLE IF NOT EXISTS appointment_permission_assignments (
    assignment_id       BIGSERIAL PRIMARY KEY,
    cmp_id              INTEGER      NOT NULL,
    -- The person, per the portal. No name, no email: Manage has those.
    user_uuid           TEXT         NOT NULL,
    profile_id          BIGINT       NOT NULL REFERENCES appointment_permission_profiles (profile_id) ON DELETE CASCADE,
    assigned_by_uuid    TEXT         NULL,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_appt_assignments ON appointment_permission_assignments (cmp_id, user_uuid, profile_id);
CREATE INDEX IF NOT EXISTS idx_appt_assignments_user ON appointment_permission_assignments (cmp_id, user_uuid);

-- --- Audit ------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS appointment_audit_log (
    audit_id            BIGSERIAL PRIMARY KEY,
    cmp_id              INTEGER      NOT NULL,
    bo_id               INTEGER      NOT NULL DEFAULT 0,

    actor_uuid          TEXT         NOT NULL,
    actor_kind          VARCHAR(16)  NOT NULL DEFAULT 'user',
    source_app          VARCHAR(32)  NOT NULL DEFAULT 'appointments',

    action              TEXT         NOT NULL,
    entity_type         TEXT         NOT NULL,
    entity_id           TEXT         NULL,
    before_state        JSONB        NULL,
    after_state         JSONB        NULL,
    reason              TEXT         NULL,
    ip_address          TEXT         NULL,

    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_appt_audit_entity ON appointment_audit_log (cmp_id, entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_appt_audit_recent ON appointment_audit_log (cmp_id, created_at DESC);

-- --- Company settings -------------------------------------------------------

CREATE TABLE IF NOT EXISTS appointment_feature_settings (
    cmp_id              INTEGER PRIMARY KEY,

    timezone            VARCHAR(64)  NOT NULL DEFAULT 'Asia/Kolkata',
    currency            VARCHAR(3)   NOT NULL DEFAULT 'INR',
    -- The grid bookable slots are aligned to. 15 means 9:00, 9:15, 9:30 —
    -- offering 9:07 is technically free and practically useless.
    slot_granularity_minutes SMALLINT NOT NULL DEFAULT 15
                        CHECK (slot_granularity_minutes IN (5, 10, 15, 20, 30, 60)),
    -- How long a slot stays held while somebody completes a booking.
    hold_duration_seconds INTEGER    NOT NULL DEFAULT 300
                        CHECK (hold_duration_seconds BETWEEN 60 AND 1800),

    -- The business week, for the dashboards and the capacity heatmap.
    week_starts_on      SMALLINT     NOT NULL DEFAULT 1 CHECK (week_starts_on BETWEEN 0 AND 6),
    day_starts_minute   SMALLINT     NOT NULL DEFAULT 540,
    day_ends_minute     SMALLINT     NOT NULL DEFAULT 1080,

    -- Per-company switches, layered on top of the deployment-wide feature
    -- flags in src/Features.php. A company can turn a feature OFF; it cannot
    -- turn on something the deployment has not configured.
    waitlist_enabled        BOOLEAN  NOT NULL DEFAULT TRUE,
    public_booking_enabled  BOOLEAN  NOT NULL DEFAULT TRUE,
    ai_insights_enabled     BOOLEAN  NOT NULL DEFAULT TRUE,
    auto_confirm_online     BOOLEAN  NOT NULL DEFAULT TRUE,

    default_rule_uuid   UUID         NULL,
    default_reminder_rule_uuid UUID  NULL,

    -- The counter behind AP-1042. Kept here so it is one row lock, not a scan
    -- of the bookings table.
    booking_sequence    BIGINT       NOT NULL DEFAULT 0,
    reference_prefix    VARCHAR(8)   NOT NULL DEFAULT 'AP',

    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CHECK (day_ends_minute > day_starts_minute)
);

-- --- AI insights ------------------------------------------------------------
--
-- Appointments runs its own AI. There is no shared agent runtime in this fleet
-- and there is not going to be one — the product that knows what a no-show
-- means is the product that should be reasoning about them.
--
-- What is cached here is the OUTPUT of an insight: the text, the rule that
-- produced it, the records it cited. Never a prompt, never a key, never a model
-- response verbatim from somewhere else. Cached because an insight costs a model
-- call and a dashboard is refreshed far more often than the data underneath it
-- changes.

CREATE TABLE IF NOT EXISTS appointment_ai_insights (
    insight_uuid        UUID PRIMARY KEY,
    cmp_id              INTEGER      NOT NULL,
    bo_id               INTEGER      NOT NULL DEFAULT 0,

    -- Which dashboard asked: overview | live | capacity | client_experience | intelligence
    surface             VARCHAR(32)  NOT NULL,
    -- The deterministic rule that fired. An insight with no rule is not an
    -- insight, it is a model talking.
    rule_key            VARCHAR(64)  NOT NULL,

    title               TEXT         NOT NULL,
    body                TEXT         NOT NULL DEFAULT '',
    -- The records behind it, so "why this?" can be answered without a second
    -- model call.
    evidence            JSONB        NOT NULL DEFAULT '[]'::jsonb,
    -- The thresholds and counts the rule used.
    rule_detail         JSONB        NOT NULL DEFAULT '{}'::jsonb,
    -- A route name this product owns plus filters. NEVER a URL from a model.
    action_route        VARCHAR(64)  NULL,
    action_params       JSONB        NOT NULL DEFAULT '{}'::jsonb,
    action_label        TEXT         NULL,

    -- 'rules' or 'ai'. The dashboard labels it, because a reader is entitled to
    -- know whether a sentence was written by arithmetic or by a model.
    origin              VARCHAR(16)  NOT NULL DEFAULT 'rules'
                        CHECK (origin IN ('rules', 'ai')),

    -- The window the insight describes, so a cached one is not reused for a
    -- different period.
    period_key          VARCHAR(64)  NOT NULL DEFAULT '',
    generated_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    expires_at          TIMESTAMPTZ  NOT NULL,
    dismissed_at        TIMESTAMPTZ  NULL,
    dismissed_by_uuid   TEXT         NULL
);

CREATE INDEX IF NOT EXISTS idx_appt_insights_live ON appointment_ai_insights (cmp_id, surface, expires_at)
    WHERE dismissed_at IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_appt_insights_dedupe
    ON appointment_ai_insights (cmp_id, bo_id, surface, rule_key, period_key);
