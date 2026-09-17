<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Features;
use Aicountly\Api\Support\Clock;

/**
 * One company's Appointments configuration, and the booking reference counter.
 *
 * Reading settings creates them. A company that has just been switched to for
 * the first time has no row, and the alternative to creating one is every
 * caller handling a null — which someone eventually will not. Creating it also
 * fires the trigger that gives the company a default booking rule and a default
 * reminder rule, so the first service anybody adds has a policy to inherit.
 */
final class Settings
{
    public const TABLE = 'appointment_feature_settings';

    /** @var array<int, array<string, mixed>> */
    private static array $memo = [];

    /** @return array<string, mixed> */
    public static function for(Context $ctx): array
    {
        if (isset(self::$memo[$ctx->cmpId])) {
            return self::$memo[$ctx->cmpId];
        }

        $row = Db::first('SELECT * FROM ' . self::TABLE . ' WHERE cmp_id = :cmp', ['cmp' => $ctx->cmpId]);

        if ($row === null) {
            // ON CONFLICT DO NOTHING rather than a check-then-insert: two
            // requests arriving together on a company's first ever page load
            // would otherwise race and one would fail on the primary key.
            Db::run(
                'INSERT INTO ' . self::TABLE . ' (cmp_id) VALUES (:cmp) ON CONFLICT (cmp_id) DO NOTHING',
                ['cmp' => $ctx->cmpId],
            );
            $row = Db::first('SELECT * FROM ' . self::TABLE . ' WHERE cmp_id = :cmp', ['cmp' => $ctx->cmpId]) ?? [];
        }

        return self::$memo[$ctx->cmpId] = self::shape($row);
    }

    /** @param array<string, mixed> $patch */
    public static function update(Context $ctx, array $patch): array
    {
        self::for($ctx);

        $allowed = [
            'timezone', 'currency', 'slot_granularity_minutes', 'hold_duration_seconds',
            'week_starts_on', 'day_starts_minute', 'day_ends_minute',
            'waitlist_enabled', 'public_booking_enabled', 'ai_insights_enabled',
            'auto_confirm_online', 'default_rule_uuid', 'default_reminder_rule_uuid',
            'reference_prefix',
        ];

        $values = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $patch)) {
                $values[$key] = $patch[$key];
            }
        }

        if ($values !== []) {
            $values['updated_at'] = Clock::sql(Clock::now());
            Db::update(self::TABLE, $values, ['cmp_id' => $ctx->cmpId]);
            unset(self::$memo[$ctx->cmpId]);
        }

        return self::for($ctx);
    }

    /**
     * The next human-facing reference: AP-1042.
     *
     * One row lock rather than MAX(reference) + 1 over the bookings table:
     * under two concurrent bookings the scan version hands out the same number
     * twice, and the unique index then fails the second booking in front of a
     * client who did nothing wrong.
     */
    public static function nextReference(Context $ctx): string
    {
        self::for($ctx);

        $row = Db::first(
            'UPDATE ' . self::TABLE . '
                SET booking_sequence = booking_sequence + 1
              WHERE cmp_id = :cmp
          RETURNING booking_sequence, reference_prefix',
            ['cmp' => $ctx->cmpId],
        );

        $sequence = (int) ($row['booking_sequence'] ?? 1);
        $prefix = trim((string) ($row['reference_prefix'] ?? 'AP')) ?: 'AP';

        // Start at 1001 so the first booking is not "AP-1", which reads like a
        // test record to anybody who receives it.
        return sprintf('%s-%d', $prefix, 1000 + $sequence);
    }

    /**
     * Whether a feature is on for this company.
     *
     * Both gates must agree: the deployment must have it configured (Features)
     * and the company must not have switched it off. A company cannot turn on
     * something this deployment cannot do — a switch that promises a waitlist
     * with no way to reach anybody is worse than no switch.
     */
    public static function featureEnabled(Context $ctx, string $flag): bool
    {
        if (!Features::enabled($flag)) {
            return false;
        }

        $settings = self::for($ctx);

        return match (strtoupper($flag)) {
            'WAITLIST'       => (bool) $settings['waitlist_enabled'],
            'PUBLIC_BOOKING' => (bool) $settings['public_booking_enabled'],
            'AI'             => (bool) $settings['ai_insights_enabled'],
            default          => true,
        };
    }

    public static function forget(?int $cmpId = null): void
    {
        if ($cmpId === null) {
            self::$memo = [];

            return;
        }
        unset(self::$memo[$cmpId]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        return [
            'cmp_id'                     => (int) ($row['cmp_id'] ?? 0),
            'timezone'                   => (string) ($row['timezone'] ?? 'Asia/Kolkata'),
            'currency'                   => (string) ($row['currency'] ?? 'INR'),
            'slot_granularity_minutes'   => (int) ($row['slot_granularity_minutes'] ?? 15),
            'hold_duration_seconds'      => (int) ($row['hold_duration_seconds'] ?? 300),
            'week_starts_on'             => (int) ($row['week_starts_on'] ?? 1),
            'day_starts_minute'          => (int) ($row['day_starts_minute'] ?? 540),
            'day_ends_minute'            => (int) ($row['day_ends_minute'] ?? 1080),
            'waitlist_enabled'           => (bool) ($row['waitlist_enabled'] ?? true),
            'public_booking_enabled'     => (bool) ($row['public_booking_enabled'] ?? true),
            'ai_insights_enabled'        => (bool) ($row['ai_insights_enabled'] ?? true),
            'auto_confirm_online'        => (bool) ($row['auto_confirm_online'] ?? true),
            'default_rule_uuid'          => $row['default_rule_uuid'] ?? null,
            'default_reminder_rule_uuid' => $row['default_reminder_rule_uuid'] ?? null,
            'reference_prefix'           => (string) ($row['reference_prefix'] ?? 'AP'),
        ];
    }
}
