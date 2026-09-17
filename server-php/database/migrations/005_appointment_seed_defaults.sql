-- ---------------------------------------------------------------------------
-- Structural defaults only — no sample data.
--
-- A booking product seeded with three fake services and a fictional therapist
-- looks impressive for ten minutes and then has to be found and deleted by
-- somebody who is not sure which rows are real. Nothing here creates a service,
-- a team member or a booking.
--
-- What it does do is make the foreign keys that services point at resolvable
-- once a company exists: the trigger below gives a brand-new company its
-- default booking rule and reminder rule the first time its settings row is
-- written, so the first service somebody creates has a policy to inherit.
-- ---------------------------------------------------------------------------

CREATE OR REPLACE FUNCTION appointment_seed_company_defaults()
RETURNS TRIGGER AS $$
DECLARE
    v_rule_uuid     UUID;
    v_reminder_uuid UUID;
    v_step_uuid     UUID;
BEGIN
    -- The standard policy: a day's notice to cancel, half a day to move,
    -- fifteen minutes' grace before it counts as a no-show. Conservative and
    -- easy to change; the point is that one exists.
    IF NOT EXISTS (SELECT 1 FROM appointment_booking_rules WHERE cmp_id = NEW.cmp_id) THEN
        v_rule_uuid := gen_random_uuid();
        INSERT INTO appointment_booking_rules (
            rule_uuid, cmp_id, name,
            cancellation_notice_hours, reschedule_notice_hours,
            no_show_after_minutes, is_default
        ) VALUES (
            v_rule_uuid, NEW.cmp_id, 'Standard policy', 24, 12, 15, TRUE
        );
        NEW.default_rule_uuid := v_rule_uuid;
    END IF;

    -- Confirmation on booking, a nudge the day before, a last one four hours
    -- out for anybody who still has not confirmed.
    IF NOT EXISTS (SELECT 1 FROM appointment_reminder_rules WHERE cmp_id = NEW.cmp_id) THEN
        v_reminder_uuid := gen_random_uuid();
        INSERT INTO appointment_reminder_rules (reminder_rule_uuid, cmp_id, name, is_default)
        VALUES (v_reminder_uuid, NEW.cmp_id, 'Standard reminders', TRUE);

        INSERT INTO appointment_reminder_steps (step_uuid, reminder_rule_uuid, cmp_id, offset_minutes, channel, template_key, only_if_unconfirmed)
        VALUES
            (gen_random_uuid(), v_reminder_uuid, NEW.cmp_id, 0,    'email',    'appointment_confirmation', FALSE),
            (gen_random_uuid(), v_reminder_uuid, NEW.cmp_id, 1440, 'whatsapp', 'appointment_reminder',     FALSE),
            (gen_random_uuid(), v_reminder_uuid, NEW.cmp_id, 240,  'sms',      'appointment_reminder',     TRUE);

        NEW.default_reminder_rule_uuid := v_reminder_uuid;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_appointment_seed_defaults ON appointment_feature_settings;

CREATE TRIGGER trg_appointment_seed_defaults
    BEFORE INSERT ON appointment_feature_settings
    FOR EACH ROW
    EXECUTE FUNCTION appointment_seed_company_defaults();
