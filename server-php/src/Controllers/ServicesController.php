<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\BookingService;
use Aicountly\Api\Http;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

/**
 * Services: what can be booked, for how long, by whom, where.
 *
 * ## Duration and buffers are three different numbers
 *
 * `duration_minutes` is what the client is told. `buffer_before_mins` and
 * `buffer_after_mins` are what the diary loses either side. A 60-minute
 * consultation with a 15-minute write-up is a 60-minute appointment that
 * occupies 75, and conflating them either lies to the client or double-books
 * the practitioner.
 *
 * ## Deactivating is not deleting
 *
 * A service with appointments against it cannot be deleted, because deleting it
 * would take the history of what those appointments were with it. It is marked
 * inactive, which stops it being bookable and leaves the record intact.
 */
final class ServicesController extends Controller
{
    /** @var list<string> */
    private const MODES = ['IN_PERSON', 'PHONE', 'AICOUNTLY_CONNECT', 'EXTERNAL_VIDEO'];

    public static function index(): void
    {
        [, $ctx] = self::enter('appointments.booking.view');

        $includeInactive = Http::param('include_inactive') !== null;

        $rows = Db::all(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM appointment_service_staff ss WHERE ss.service_uuid = s.service_uuid) AS staff_count,
                    (SELECT COUNT(*) FROM ' . BookingService::TABLE . ' b
                      WHERE b.service_uuid = s.service_uuid AND b.starts_at >= NOW()) AS upcoming_count
               FROM appointment_services s
              WHERE s.cmp_id = :cmp
                AND (:bo = 0 OR s.bo_id = :bo OR s.bo_id = 0)
                ' . ($includeInactive ? '' : 'AND s.is_active = TRUE') . '
              ORDER BY s.sort_order, s.name',
            ['cmp' => $ctx->cmpId, 'bo' => $ctx->boId],
        );

        Http::data(['services' => array_map([self::class, 'shape'], $rows)]);
    }

    public static function show(string $serviceUuid): void
    {
        [, $ctx] = self::enter('appointments.booking.view');

        $row = Db::first(
            'SELECT * FROM appointment_services WHERE service_uuid = :id AND cmp_id = :cmp',
            ['id' => $serviceUuid, 'cmp' => $ctx->cmpId],
        );

        if ($row === null) {
            Http::notFound('That service does not exist.');
        }

        Http::data([
            'service'   => self::shape($row),
            'staff'     => Db::all(
                'SELECT ss.member_uuid, ss.priority, ss.duration_override_minutes,
                        m.display_label, m.job_title, m.is_active
                   FROM appointment_service_staff ss
                   JOIN appointment_team_members m ON m.member_uuid = ss.member_uuid
                  WHERE ss.service_uuid = :id AND ss.cmp_id = :cmp
                  ORDER BY ss.priority DESC',
                ['id' => $serviceUuid, 'cmp' => $ctx->cmpId],
            ),
            'resources' => Db::all(
                'SELECT sr.resource_uuid, sr.is_required, r.name, r.kind
                   FROM appointment_service_resources sr
                   JOIN appointment_resources r ON r.resource_uuid = sr.resource_uuid
                  WHERE sr.service_uuid = :id AND sr.cmp_id = :cmp',
                ['id' => $serviceUuid, 'cmp' => $ctx->cmpId],
            ),
            'locations' => array_map(
                static fn (array $r) => (int) $r['bo_id'],
                Db::all(
                    'SELECT bo_id FROM appointment_service_locations WHERE service_uuid = :id AND cmp_id = :cmp',
                    ['id' => $serviceUuid, 'cmp' => $ctx->cmpId],
                ),
            ),
        ]);
    }

    public static function create(): void
    {
        [$auth, $ctx] = self::enter('appointments.services.manage');

        $body = Http::body();
        $errors = self::validate($body, true);
        if ($errors !== []) {
            Http::validationFailed('Check the service details.', $errors);
        }

        $serviceUuid = Uuid::v4();

        Db::transaction(function () use ($serviceUuid, $ctx, $body): void {
            Db::insert('appointment_services', self::writable($body, $ctx) + [
                'service_uuid'    => $serviceUuid,
                'cmp_id'          => $ctx->cmpId,
                'created_by_uuid' => null,
            ], 'service_uuid');

            self::syncRelations($ctx, $serviceUuid, $body);
        });

        $row = Db::first(
            'SELECT * FROM appointment_services WHERE service_uuid = :id',
            ['id' => $serviceUuid],
        ) ?? [];

        Audit::record($ctx, $auth, 'service.created', 'service', $serviceUuid, null, ['name' => $body['name'] ?? null]);

        Http::data(['service' => self::shape($row)], 201);
    }

    public static function update(string $serviceUuid): void
    {
        [$auth, $ctx] = self::enter('appointments.services.manage');

        $existing = Db::first(
            'SELECT * FROM appointment_services WHERE service_uuid = :id AND cmp_id = :cmp',
            ['id' => $serviceUuid, 'cmp' => $ctx->cmpId],
        );

        if ($existing === null) {
            Http::notFound('That service does not exist.');
        }

        $body = Http::body();
        $errors = self::validate($body, false);
        if ($errors !== []) {
            Http::validationFailed('Check the service details.', $errors);
        }

        Db::transaction(function () use ($serviceUuid, $ctx, $body): void {
            $values = self::writable($body, $ctx, false);
            if ($values !== []) {
                $values['updated_at'] = Clock::sql(Clock::now());
                Db::update('appointment_services', $values, ['service_uuid' => $serviceUuid, 'cmp_id' => $ctx->cmpId]);
            }
            self::syncRelations($ctx, $serviceUuid, $body);
        });

        $row = Db::first(
            'SELECT * FROM appointment_services WHERE service_uuid = :id AND cmp_id = :cmp',
            ['id' => $serviceUuid, 'cmp' => $ctx->cmpId],
        ) ?? [];

        Audit::record($ctx, $auth, 'service.updated', 'service', $serviceUuid, [
            'name'             => $existing['name'],
            'duration_minutes' => $existing['duration_minutes'],
            'is_active'        => $existing['is_active'],
        ], [
            'name'             => $row['name'] ?? null,
            'duration_minutes' => $row['duration_minutes'] ?? null,
            'is_active'        => $row['is_active'] ?? null,
        ]);

        Http::data(['service' => self::shape($row)]);
    }

    /**
     * Retire a service.
     *
     * Deliberately not a DELETE. A service with appointments behind it is part
     * of the record of what happened; removing the row would leave those
     * appointments describing something nobody can name.
     */
    public static function deactivate(string $serviceUuid): void
    {
        [$auth, $ctx] = self::enter('appointments.services.manage');

        $existing = Db::first(
            'SELECT service_uuid, name, is_active FROM appointment_services
              WHERE service_uuid = :id AND cmp_id = :cmp',
            ['id' => $serviceUuid, 'cmp' => $ctx->cmpId],
        );

        if ($existing === null) {
            Http::notFound('That service does not exist.');
        }

        $upcoming = (int) Db::scalar(
            'SELECT COUNT(*) FROM ' . BookingService::TABLE . "
              WHERE cmp_id = :cmp AND service_uuid = :id
                AND starts_at >= NOW() AND status IN ('PENDING', 'CONFIRMED')",
            ['cmp' => $ctx->cmpId, 'id' => $serviceUuid],
        );

        Db::update('appointment_services', [
            'is_active'  => false,
            'updated_at' => Clock::sql(Clock::now()),
        ], ['service_uuid' => $serviceUuid, 'cmp_id' => $ctx->cmpId]);

        Audit::record($ctx, $auth, 'service.deactivated', 'service', $serviceUuid, ['is_active' => true], ['is_active' => false]);

        Http::data([
            'service_uuid' => $serviceUuid,
            'is_active'    => false,
            // Said plainly, because turning a service off does not cancel the
            // appointments already in the diary and somebody has to decide
            // what happens to them.
            'upcoming_appointments' => $upcoming,
            'note' => $upcoming > 0
                ? $upcoming . ' upcoming appointment' . ($upcoming === 1 ? '' : 's')
                    . ' still use this service. They are unaffected and will go ahead.'
                : null,
        ]);
    }

    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $body
     * @return array<string, string>
     */
    private static function validate(array $body, bool $creating): array
    {
        $errors = [];

        if ($creating || array_key_exists('name', $body)) {
            if (trim((string) ($body['name'] ?? '')) === '') {
                $errors['name'] = 'A name is required.';
            }
        }

        if ($creating || array_key_exists('duration_minutes', $body)) {
            $duration = (int) ($body['duration_minutes'] ?? 0);
            if ($duration < 5 || $duration > 1440) {
                $errors['duration_minutes'] = 'Duration must be between 5 minutes and 24 hours.';
            }
        }

        foreach (['buffer_before_mins', 'buffer_after_mins'] as $field) {
            if (array_key_exists($field, $body) && ((int) $body[$field] < 0 || (int) $body[$field] > 240)) {
                $errors[$field] = 'Buffers must be between 0 and 240 minutes.';
            }
        }

        if (array_key_exists('modes', $body)) {
            $modes = array_map('strtoupper', array_filter((array) $body['modes'], 'is_string'));
            if ($modes === []) {
                $errors['modes'] = 'At least one appointment mode is required.';
            }
            foreach ($modes as $mode) {
                if (!in_array($mode, self::MODES, true)) {
                    $errors['modes'] = 'Modes must be from: ' . implode(', ', self::MODES) . '.';
                    break;
                }
            }
        }

        if (!empty($body['deposit_required'])) {
            $deposit = (int) ($body['deposit_minor'] ?? 0);
            if ($deposit <= 0) {
                // A service that requires a deposit of nothing is a service
                // whose booking flow will ask Pay for a zero-value payment.
                $errors['deposit_minor'] = 'A deposit amount is required when a deposit is required.';
            }
        }

        if (array_key_exists('capacity', $body) && ((int) $body['capacity'] < 1 || (int) $body['capacity'] > 500)) {
            $errors['capacity'] = 'Capacity must be between 1 and 500.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function writable(array $body, \Aicountly\Api\Context $ctx, bool $withDefaults = true): array
    {
        $fields = [
            'name', 'description', 'category', 'colour', 'duration_minutes',
            'buffer_before_mins', 'buffer_after_mins', 'min_notice_minutes',
            'booking_horizon_days', 'capacity', 'price_minor', 'currency',
            'deposit_required', 'deposit_minor', 'form_uuid',
            'cancellation_rule_uuid', 'no_show_rule_uuid', 'reminder_rule_uuid',
            'is_active', 'is_bookable_online', 'sort_order', 'bo_id',
        ];

        $values = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $body)) {
                $values[$field] = $body[$field] === '' ? null : $body[$field];
            }
        }

        if (array_key_exists('modes', $body)) {
            $values['modes'] = array_values(array_unique(
                array_map('strtoupper', array_filter((array) $body['modes'], 'is_string')),
            ));
        }

        if ($withDefaults) {
            $values += [
                'bo_id'    => $ctx->boId,
                'modes'    => ['IN_PERSON'],
                'currency' => \Aicountly\Api\Domain\Settings::for($ctx)['currency'],
            ];
        }

        // A deposit amount left behind on a service that no longer requires one
        // is a number that will confuse somebody later.
        if (array_key_exists('deposit_required', $body) && empty($body['deposit_required'])) {
            $values['deposit_minor'] = null;
        }

        return $values;
    }

    /** @param array<string, mixed> $body */
    private static function syncRelations(\Aicountly\Api\Context $ctx, string $serviceUuid, array $body): void
    {
        if (array_key_exists('staff', $body)) {
            Db::run(
                'DELETE FROM appointment_service_staff WHERE service_uuid = :id AND cmp_id = :cmp',
                ['id' => $serviceUuid, 'cmp' => $ctx->cmpId],
            );

            foreach ((array) $body['staff'] as $entry) {
                $memberUuid = is_array($entry) ? (string) ($entry['member_uuid'] ?? '') : (string) $entry;
                if (!Uuid::isValid($memberUuid)) {
                    continue;
                }
                Db::run(
                    'INSERT INTO appointment_service_staff (service_uuid, member_uuid, cmp_id, priority, duration_override_minutes)
                     VALUES (:service, :member, :cmp, :priority, :override)
                     ON CONFLICT (service_uuid, member_uuid) DO UPDATE
                        SET priority = EXCLUDED.priority,
                            duration_override_minutes = EXCLUDED.duration_override_minutes',
                    [
                        'service'  => $serviceUuid,
                        'member'   => $memberUuid,
                        'cmp'      => $ctx->cmpId,
                        'priority' => is_array($entry) ? (int) ($entry['priority'] ?? 0) : 0,
                        'override' => is_array($entry) && !empty($entry['duration_override_minutes'])
                            ? (int) $entry['duration_override_minutes']
                            : null,
                    ],
                );
            }
        }

        if (array_key_exists('resources', $body)) {
            Db::run(
                'DELETE FROM appointment_service_resources WHERE service_uuid = :id AND cmp_id = :cmp',
                ['id' => $serviceUuid, 'cmp' => $ctx->cmpId],
            );

            foreach ((array) $body['resources'] as $entry) {
                $resourceUuid = is_array($entry) ? (string) ($entry['resource_uuid'] ?? '') : (string) $entry;
                if (!Uuid::isValid($resourceUuid)) {
                    continue;
                }
                Db::run(
                    'INSERT INTO appointment_service_resources (service_uuid, resource_uuid, cmp_id, is_required)
                     VALUES (:service, :resource, :cmp, :required)
                     ON CONFLICT (service_uuid, resource_uuid) DO UPDATE SET is_required = EXCLUDED.is_required',
                    [
                        'service'  => $serviceUuid,
                        'resource' => $resourceUuid,
                        'cmp'      => $ctx->cmpId,
                        'required' => is_array($entry) ? (bool) ($entry['is_required'] ?? true) : true,
                    ],
                );
            }
        }

        if (array_key_exists('locations', $body)) {
            Db::run(
                'DELETE FROM appointment_service_locations WHERE service_uuid = :id AND cmp_id = :cmp',
                ['id' => $serviceUuid, 'cmp' => $ctx->cmpId],
            );

            foreach ((array) $body['locations'] as $boId) {
                $boId = (int) $boId;
                if ($boId <= 0) {
                    continue;
                }
                Db::run(
                    'INSERT INTO appointment_service_locations (service_uuid, bo_id, cmp_id)
                     VALUES (:service, :bo, :cmp) ON CONFLICT DO NOTHING',
                    ['service' => $serviceUuid, 'bo' => $boId, 'cmp' => $ctx->cmpId],
                );
            }
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function shape(array $row): array
    {
        return [
            'service_uuid'         => (string) ($row['service_uuid'] ?? ''),
            'name'                 => (string) ($row['name'] ?? ''),
            'description'          => (string) ($row['description'] ?? ''),
            'category'             => (string) ($row['category'] ?? 'general'),
            'colour'               => $row['colour'] ?? null,
            'duration_minutes'     => (int) ($row['duration_minutes'] ?? 0),
            'buffer_before_mins'   => (int) ($row['buffer_before_mins'] ?? 0),
            'buffer_after_mins'    => (int) ($row['buffer_after_mins'] ?? 0),
            'min_notice_minutes'   => (int) ($row['min_notice_minutes'] ?? 0),
            'booking_horizon_days' => (int) ($row['booking_horizon_days'] ?? 60),
            'capacity'             => (int) ($row['capacity'] ?? 1),
            'modes'                => Db::jsonColumn($row['modes'] ?? null),
            'price_minor'          => $row['price_minor'] === null ? null : (int) $row['price_minor'],
            'currency'             => (string) ($row['currency'] ?? 'INR'),
            'deposit_required'     => (bool) ($row['deposit_required'] ?? false),
            'deposit_minor'        => $row['deposit_minor'] === null ? null : (int) $row['deposit_minor'],
            'form_uuid'            => $row['form_uuid'] ?? null,
            'cancellation_rule_uuid' => $row['cancellation_rule_uuid'] ?? null,
            'reminder_rule_uuid'   => $row['reminder_rule_uuid'] ?? null,
            'is_active'            => (bool) ($row['is_active'] ?? true),
            'is_bookable_online'   => (bool) ($row['is_bookable_online'] ?? true),
            'sort_order'           => (int) ($row['sort_order'] ?? 0),
            'bo_id'                => (int) ($row['bo_id'] ?? 0),
            'staff_count'          => isset($row['staff_count']) ? (int) $row['staff_count'] : null,
            'upcoming_count'       => isset($row['upcoming_count']) ? (int) $row['upcoming_count'] : null,
        ];
    }
}
