<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\BookingService;
use Aicountly\Api\Domain\Settings;
use Aicountly\Api\Features;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

/**
 * Session, settings, booking policies and reminder rules.
 *
 * `session` is the first call the frontend makes and the only one it cannot
 * work without: it says who the user is, what they may do, which features this
 * deployment has, and what the appointment vocabulary is. Everything the UI
 * would otherwise hardcode — the status list, the modes, the channels — comes
 * from here, so adding a status is a backend change and not a hunt through
 * components.
 */
final class SettingsController extends Controller
{
    public static function session(): void
    {
        $auth = Auth::require();
        $ctx = Context::fromRequest();
        $ctx->assertAllowed($auth);

        $settings = Settings::for($ctx);

        Http::data([
            'user' => [
                'uuid'     => $auth->uuid,
                'name'     => $auth->displayName(),
                'kind'     => $auth->kind,
                'is_owner' => $auth->accessType() === 1,
            ],
            'company'     => $ctx->asQuery(),
            'permissions' => Permissions::granted($ctx, $auth),
            'settings'    => $settings,
            // Deployment-wide flags AND the company's own switches, already
            // combined — a UI that had to reason about both would get it wrong.
            'features'    => self::featureMap($ctx),
            'vocabulary'  => self::vocabulary(),
        ]);
    }

    public static function permissions(): void
    {
        [$auth, $ctx] = self::enter();

        Http::data([
            'catalogue' => Permissions::CATALOG,
            'granted'   => Permissions::granted($ctx, $auth),
            'grantable' => Permissions::grantable($ctx, $auth),
        ]);
    }

    public static function show(): void
    {
        [, $ctx] = self::enter('appointments.dashboard.view');

        Http::data([
            'settings' => Settings::for($ctx),
            'features' => self::featureMap($ctx),
            'rules'    => Db::all(
                'SELECT * FROM appointment_booking_rules WHERE cmp_id = :cmp ORDER BY is_default DESC, name',
                ['cmp' => $ctx->cmpId],
            ),
            'reminder_rules' => self::reminderRules($ctx),
        ]);
    }

    public static function update(): void
    {
        [$auth, $ctx] = self::enter('appointments.settings.manage');

        $body = Http::body();
        $before = Settings::for($ctx);
        $errors = [];

        if (array_key_exists('timezone', $body)) {
            $tz = trim((string) $body['timezone']);
            // Checked against the real zone database, because an invalid zone
            // here would shift every slot on every screen.
            if ($tz === '' || !in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                $errors['timezone'] = 'That is not a recognised timezone.';
            }
        }

        if (array_key_exists('slot_granularity_minutes', $body)) {
            if (!in_array((int) $body['slot_granularity_minutes'], [5, 10, 15, 20, 30, 60], true)) {
                $errors['slot_granularity_minutes'] = 'Slot granularity must be 5, 10, 15, 20, 30 or 60 minutes.';
            }
        }

        if (array_key_exists('hold_duration_seconds', $body)) {
            $hold = (int) $body['hold_duration_seconds'];
            if ($hold < 60 || $hold > 1800) {
                // A hold long enough to be a reservation makes a popular
                // practitioner look fully booked to everybody else.
                $errors['hold_duration_seconds'] = 'A slot hold must be between 1 and 30 minutes.';
            }
        }

        if (array_key_exists('day_starts_minute', $body) || array_key_exists('day_ends_minute', $body)) {
            $start = (int) ($body['day_starts_minute'] ?? $before['day_starts_minute']);
            $end = (int) ($body['day_ends_minute'] ?? $before['day_ends_minute']);
            if ($end <= $start) {
                $errors['day_ends_minute'] = 'The business day must end after it starts.';
            }
        }

        if ($errors !== []) {
            Http::validationFailed('Check the settings.', $errors);
        }

        $after = Settings::update($ctx, $body);

        Audit::record($ctx, $auth, 'settings.updated', 'settings', (string) $ctx->cmpId, $before, $after);

        Http::data(['settings' => $after, 'features' => self::featureMap($ctx)]);
    }

    /**
     * Save a booking policy.
     *
     * One default per company, enforced by a partial unique index — so
     * promoting a policy demotes the previous one in the same transaction
     * rather than leaving two defaults and a coin toss over which applies.
     */
    public static function saveRule(): void
    {
        [$auth, $ctx] = self::enter('appointments.settings.manage');

        $body = Http::body();
        $name = trim((string) ($body['name'] ?? ''));

        if ($name === '') {
            Http::validationFailed('A policy name is required.');
        }

        $ruleUuid = trim((string) ($body['rule_uuid'] ?? ''));
        $creating = !Uuid::isValid($ruleUuid);

        if (!$creating) {
            $exists = Db::first(
                'SELECT rule_uuid FROM appointment_booking_rules WHERE rule_uuid = :id AND cmp_id = :cmp',
                ['id' => $ruleUuid, 'cmp' => $ctx->cmpId],
            );
            if ($exists === null) {
                Http::notFound('That policy does not exist.');
            }
        } else {
            $ruleUuid = Uuid::v4();
        }

        $values = ['name' => substr($name, 0, 200)];

        foreach ([
            'cancellation_notice_hours', 'reschedule_notice_hours', 'max_reschedules',
            'no_show_after_minutes', 'late_cancellation_fee_minor', 'no_show_fee_minor',
            'no_show_prepay_threshold', 'allow_client_cancellation', 'allow_client_reschedule',
            'requires_confirmation', 'auto_release_unconfirmed_hours',
        ] as $field) {
            if (array_key_exists($field, $body)) {
                $values[$field] = $body[$field] === '' ? null : $body[$field];
            }
        }

        $makeDefault = (bool) ($body['is_default'] ?? false);

        Db::transaction(function () use ($creating, $ruleUuid, $ctx, $values, $makeDefault): void {
            if ($makeDefault) {
                Db::run(
                    'UPDATE appointment_booking_rules SET is_default = FALSE WHERE cmp_id = :cmp',
                    ['cmp' => $ctx->cmpId],
                );
            }

            $values['is_default'] = $makeDefault;
            $values['updated_at'] = Clock::sql(Clock::now());

            if ($creating) {
                Db::insert('appointment_booking_rules', $values + ['rule_uuid' => $ruleUuid, 'cmp_id' => $ctx->cmpId], 'rule_uuid');
            } else {
                Db::update('appointment_booking_rules', $values, ['rule_uuid' => $ruleUuid, 'cmp_id' => $ctx->cmpId]);
            }
        });

        Audit::record($ctx, $auth, $creating ? 'rule.created' : 'rule.updated', 'booking_rule', $ruleUuid, null, $values);

        Http::data([
            'rule' => Db::first(
                'SELECT * FROM appointment_booking_rules WHERE rule_uuid = :id AND cmp_id = :cmp',
                ['id' => $ruleUuid, 'cmp' => $ctx->cmpId],
            ),
        ], $creating ? 201 : 200);
    }

    public static function reminderRulesIndex(): void
    {
        [, $ctx] = self::enter('appointments.booking.view');

        Http::data([
            'rules'    => self::reminderRules($ctx),
            'channels' => \Aicountly\Api\Clients\MessagingClient::CHANNELS,
            'messaging' => [
                'configured' => Features::enabled('MESSAGING'),
                'reason'     => Features::explain('MESSAGING'),
            ],
        ]);
    }

    /**
     * Save a reminder rule and its steps.
     *
     * `offset_minutes` 0 is the confirmation sent at the moment of booking;
     * anything else is minutes BEFORE the appointment.
     */
    public static function saveReminderRule(): void
    {
        [$auth, $ctx] = self::enter('appointments.reminders.manage');

        $body = Http::body();
        $name = trim((string) ($body['name'] ?? ''));

        if ($name === '') {
            Http::validationFailed('A name is required.');
        }

        $ruleUuid = trim((string) ($body['reminder_rule_uuid'] ?? ''));
        $creating = !Uuid::isValid($ruleUuid);

        if (!$creating) {
            $exists = Db::first(
                'SELECT reminder_rule_uuid FROM appointment_reminder_rules WHERE reminder_rule_uuid = :id AND cmp_id = :cmp',
                ['id' => $ruleUuid, 'cmp' => $ctx->cmpId],
            );
            if ($exists === null) {
                Http::notFound('That reminder rule does not exist.');
            }
        } else {
            $ruleUuid = Uuid::v4();
        }

        $steps = [];
        foreach ((array) ($body['steps'] ?? []) as $step) {
            if (!is_array($step)) {
                continue;
            }
            $channel = strtolower(trim((string) ($step['channel'] ?? '')));
            if (!in_array($channel, \Aicountly\Api\Clients\MessagingClient::CHANNELS, true)) {
                Http::validationFailed('Unknown reminder channel "' . $channel . '".');
            }
            $offset = max(0, (int) ($step['offset_minutes'] ?? 1440));
            if ($offset > 60 * 24 * 30) {
                Http::validationFailed('A reminder cannot be scheduled more than 30 days before the appointment.');
            }
            $steps[] = [
                'offset_minutes'      => $offset,
                'channel'             => $channel,
                'template_key'        => substr(trim((string) ($step['template_key'] ?? 'appointment_reminder')), 0, 100),
                'only_if_unconfirmed' => (bool) ($step['only_if_unconfirmed'] ?? false),
                'is_active'           => (bool) ($step['is_active'] ?? true),
            ];
        }

        $makeDefault = (bool) ($body['is_default'] ?? false);

        Db::transaction(function () use ($creating, $ruleUuid, $ctx, $name, $body, $steps, $makeDefault): void {
            if ($makeDefault) {
                Db::run(
                    'UPDATE appointment_reminder_rules SET is_default = FALSE WHERE cmp_id = :cmp',
                    ['cmp' => $ctx->cmpId],
                );
            }

            $values = [
                'name'       => substr($name, 0, 200),
                'is_default' => $makeDefault,
                'is_active'  => (bool) ($body['is_active'] ?? true),
                'updated_at' => Clock::sql(Clock::now()),
            ];

            if ($creating) {
                Db::insert('appointment_reminder_rules', $values + [
                    'reminder_rule_uuid' => $ruleUuid,
                    'cmp_id'             => $ctx->cmpId,
                ], 'reminder_rule_uuid');
            } else {
                Db::update('appointment_reminder_rules', $values, ['reminder_rule_uuid' => $ruleUuid, 'cmp_id' => $ctx->cmpId]);
            }

            Db::run(
                'DELETE FROM appointment_reminder_steps WHERE reminder_rule_uuid = :id AND cmp_id = :cmp',
                ['id' => $ruleUuid, 'cmp' => $ctx->cmpId],
            );

            foreach ($steps as $step) {
                Db::insert('appointment_reminder_steps', $step + [
                    'step_uuid'          => Uuid::v4(),
                    'reminder_rule_uuid' => $ruleUuid,
                    'cmp_id'             => $ctx->cmpId,
                ], 'step_uuid');
            }
        });

        Audit::record($ctx, $auth, $creating ? 'reminder_rule.created' : 'reminder_rule.updated', 'reminder_rule', $ruleUuid, null, [
            'name'  => $name,
            'steps' => count($steps),
        ]);

        Http::data(['rules' => self::reminderRules($ctx)], $creating ? 201 : 200);
    }

    // -----------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private static function reminderRules(Context $ctx): array
    {
        $rules = Db::all(
            'SELECT * FROM appointment_reminder_rules WHERE cmp_id = :cmp ORDER BY is_default DESC, name',
            ['cmp' => $ctx->cmpId],
        );

        if ($rules === []) {
            return [];
        }

        $steps = Db::all(
            'SELECT * FROM appointment_reminder_steps WHERE cmp_id = :cmp ORDER BY offset_minutes DESC',
            ['cmp' => $ctx->cmpId],
        );

        $byRule = [];
        foreach ($steps as $step) {
            $byRule[(string) $step['reminder_rule_uuid']][] = [
                'step_uuid'           => (string) $step['step_uuid'],
                'offset_minutes'      => (int) $step['offset_minutes'],
                'channel'             => (string) $step['channel'],
                'template_key'        => (string) $step['template_key'],
                'only_if_unconfirmed' => (bool) $step['only_if_unconfirmed'],
                'is_active'           => (bool) $step['is_active'],
                'label'               => self::offsetLabel((int) $step['offset_minutes']),
            ];
        }

        return array_map(static fn (array $rule) => [
            'reminder_rule_uuid' => (string) $rule['reminder_rule_uuid'],
            'name'               => (string) $rule['name'],
            'is_default'         => (bool) $rule['is_default'],
            'is_active'          => (bool) $rule['is_active'],
            'steps'              => $byRule[(string) $rule['reminder_rule_uuid']] ?? [],
        ], $rules);
    }

    private static function offsetLabel(int $minutes): string
    {
        if ($minutes === 0) {
            return 'Immediately on booking';
        }
        if ($minutes % 1440 === 0) {
            $days = intdiv($minutes, 1440);

            return $days . ' day' . ($days === 1 ? '' : 's') . ' before';
        }
        if ($minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);

            return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' before';
        }

        return $minutes . ' minutes before';
    }

    /** @return array<string, array{enabled: bool, reason: ?string}> */
    private static function featureMap(Context $ctx): array
    {
        $out = [];
        foreach (array_keys(Features::all()) as $flag) {
            $enabled = Settings::featureEnabled($ctx, $flag);
            $out[strtolower($flag)] = [
                'enabled' => $enabled,
                // The reason names the env key an administrator would set. It
                // never names a value.
                'reason'  => $enabled ? null : (Features::explain($flag) ?? 'Turned off for this company.'),
            ];
        }

        return $out;
    }

    /**
     * The appointment vocabulary, so the UI does not hardcode any of it.
     *
     * @return array<string, mixed>
     */
    private static function vocabulary(): array
    {
        return [
            'statuses'    => array_map(static fn (string $status) => [
                'key'   => $status,
                'label' => ucfirst(strtolower(str_replace('_', ' ', $status))),
                'next'  => BookingService::TRANSITIONS[$status],
            ], array_keys(BookingService::TRANSITIONS)),
            'active_statuses' => BookingService::ACTIVE_STATUSES,
            'modes'       => [
                ['key' => 'IN_PERSON',         'label' => 'In person'],
                ['key' => 'PHONE',             'label' => 'Phone'],
                ['key' => 'AICOUNTLY_CONNECT', 'label' => 'Aicountly Connect'],
                ['key' => 'EXTERNAL_VIDEO',    'label' => 'External video'],
            ],
            'sources'     => array_map(static fn (string $source) => [
                'key'   => $source,
                'label' => \Aicountly\Api\Dashboards\Dashboard::sourceLabel($source),
            ], [
                'ONLINE_BOOKING', 'STAFF_BOOKING', 'RECEPTIONIST', 'CRM',
                'SALES', 'POS', 'API_INTEGRATION', 'WAITLIST_OFFER', 'OTHER',
            ]),
            'channels'    => \Aicountly\Api\Clients\MessagingClient::CHANNELS,
            'dayparts'    => ['morning', 'afternoon', 'evening'],
        ];
    }
}
