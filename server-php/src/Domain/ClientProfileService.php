<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Support\Clock;

/**
 * The appointment-shaped part of knowing a client.
 *
 * Keyed on `contact_uuid`, which Contacts owns. What lives here is what
 * Contacts has no business holding and CRM would be wrong to infer: which
 * practitioner this person asks for, which channel they actually answer, and
 * how many times they have not turned up.
 *
 * ## The counters
 *
 * Maintained from THIS product's own bookings, incrementally, as outcomes are
 * recorded. Not a nightly rollup and not a CRM health score — a number
 * Appointments can explain by pointing at its own rows.
 *
 * `no_show_count` in particular is used by the no-show risk indicators, and a
 * risk indicator built on a figure nobody can trace is a risk indicator nobody
 * should act on.
 */
final class ClientProfileService
{
    public const TABLE = 'appointment_client_preferences';

    /** @return array<string, mixed> */
    public static function for(Context $ctx, string $contactUuid): array
    {
        $row = Db::first(
            'SELECT * FROM ' . self::TABLE . ' WHERE cmp_id = :cmp AND contact_uuid = :contact',
            ['cmp' => $ctx->cmpId, 'contact' => $contactUuid],
        );

        return $row ?? [
            'cmp_id'                => $ctx->cmpId,
            'contact_uuid'          => $contactUuid,
            'preferred_member_uuid' => null,
            'preferred_bo_id'       => null,
            'preferred_daypart'     => null,
            'preferred_channel'     => null,
            'preferred_language'    => null,
            'timezone'              => null,
            'total_bookings'        => 0,
            'completed_count'       => 0,
            'no_show_count'         => 0,
            'late_cancel_count'     => 0,
            'last_booking_at'       => null,
            'notes'                 => '',
        ];
    }

    /** @param array<string, mixed> $patch */
    public static function update(Context $ctx, string $contactUuid, array $patch): array
    {
        self::ensure($ctx, $contactUuid);

        $allowed = [
            'preferred_member_uuid', 'preferred_bo_id', 'preferred_daypart',
            'preferred_channel', 'preferred_language', 'timezone', 'notes',
        ];

        $values = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $patch)) {
                $values[$key] = $patch[$key] === '' ? null : $patch[$key];
            }
        }

        if ($values !== []) {
            $values['updated_at'] = Clock::sql(Clock::now());
            Db::update(self::TABLE, $values, ['cmp_id' => $ctx->cmpId, 'contact_uuid' => $contactUuid]);
        }

        return self::for($ctx, $contactUuid);
    }

    /** Called when a booking is made. */
    public static function recordBooking(Context $ctx, string $contactUuid): void
    {
        self::ensure($ctx, $contactUuid);

        Db::run(
            'UPDATE ' . self::TABLE . '
                SET total_bookings = total_bookings + 1,
                    last_booking_at = :now,
                    updated_at = :now
              WHERE cmp_id = :cmp AND contact_uuid = :contact',
            ['now' => Clock::sql(Clock::now()), 'cmp' => $ctx->cmpId, 'contact' => $contactUuid],
        );
    }

    /** COMPLETED or NO_SHOW. */
    public static function recordOutcome(Context $ctx, string $contactUuid, string $outcome): void
    {
        self::ensure($ctx, $contactUuid);

        $column = match (strtoupper($outcome)) {
            'COMPLETED' => 'completed_count',
            'NO_SHOW'   => 'no_show_count',
            default     => null,
        };

        if ($column === null) {
            return;
        }

        Db::run(
            'UPDATE ' . self::TABLE . '
                SET ' . $column . ' = ' . $column . ' + 1, updated_at = :now
              WHERE cmp_id = :cmp AND contact_uuid = :contact',
            ['now' => Clock::sql(Clock::now()), 'cmp' => $ctx->cmpId, 'contact' => $contactUuid],
        );
    }

    public static function recordLateCancellation(Context $ctx, string $contactUuid): void
    {
        self::ensure($ctx, $contactUuid);

        Db::run(
            'UPDATE ' . self::TABLE . '
                SET late_cancel_count = late_cancel_count + 1, updated_at = :now
              WHERE cmp_id = :cmp AND contact_uuid = :contact',
            ['now' => Clock::sql(Clock::now()), 'cmp' => $ctx->cmpId, 'contact' => $contactUuid],
        );
    }

    private static function ensure(Context $ctx, string $contactUuid): void
    {
        Db::run(
            'INSERT INTO ' . self::TABLE . ' (cmp_id, contact_uuid)
             VALUES (:cmp, :contact)
             ON CONFLICT (cmp_id, contact_uuid) DO NOTHING',
            ['cmp' => $ctx->cmpId, 'contact' => $contactUuid],
        );
    }
}
