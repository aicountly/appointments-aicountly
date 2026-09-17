<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Clients\ManageClient;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

/**
 * Locations and resources: branches with appointment settings, and the rooms,
 * cabins, chairs and equipment inside them.
 *
 * ## Locations are Manage's branches, configured here
 *
 * The branch itself — its name, its address, its phone number — is Manage's. A
 * location row is a `bo_id` plus the appointment-shaped part: whether it takes
 * bookings, its timezone, and the "second floor, ask at reception" sentence
 * that goes into a confirmation message. That sentence has no home in Manage
 * and copying the address would mean a client sent to the old premises after
 * the clinic moved.
 *
 * ## Resources can have their own calendar
 *
 * When a room has a `calendar_subscriber_uuid`, bookings against it are
 * conflict-checked in Calendar like a person's — which is how a room stays
 * visible to everybody rather than only to this product.
 */
final class ResourcesController extends Controller
{
    /** @var list<string> */
    private const KINDS = ['room', 'cabin', 'chair', 'equipment', 'vehicle', 'other'];

    public static function locations(): void
    {
        [$auth, $ctx] = self::enter('appointments.booking.view');

        $configured = Db::all(
            'SELECT * FROM appointment_locations_config WHERE cmp_id = :cmp ORDER BY bo_id',
            ['cmp' => $ctx->cmpId],
        );

        $byBranch = [];
        foreach ($configured as $row) {
            $byBranch[(int) $row['bo_id']] = $row;
        }

        $branches = self::branches($auth, $ctx);

        $locations = [];
        foreach ($branches as $boId => $branch) {
            $config = $byBranch[$boId] ?? null;
            $locations[] = [
                'bo_id' => $boId,
                // Live from Manage. Absent when Manage did not answer, which is
                // visible rather than filled in from a stale copy.
                'name'  => $branch['name'],
                'address' => $branch['address'],
                'configured' => $config !== null,
                'accepts_bookings' => $config === null ? false : (bool) $config['accepts_bookings'],
                'bookable_online'  => $config === null ? false : (bool) $config['bookable_online'],
                'timezone'         => $config['timezone'] ?? \Aicountly\Api\Domain\Settings::for($ctx)['timezone'],
                'arrival_instructions' => (string) ($config['arrival_instructions'] ?? ''),
                'location_uuid'    => $config['location_uuid'] ?? null,
            ];
        }

        // A branch Manage no longer reports but which still has appointment
        // configuration. Shown so somebody can clean it up rather than
        // wondering why bookings arrive for a location that is not listed.
        foreach ($byBranch as $boId => $config) {
            if (!isset($branches[$boId])) {
                $locations[] = [
                    'bo_id'      => $boId,
                    'name'       => null,
                    'address'    => null,
                    'configured' => true,
                    'accepts_bookings' => (bool) $config['accepts_bookings'],
                    'bookable_online'  => (bool) $config['bookable_online'],
                    'timezone'   => (string) $config['timezone'],
                    'arrival_instructions' => (string) $config['arrival_instructions'],
                    'location_uuid' => (string) $config['location_uuid'],
                    'orphaned'   => true,
                ];
            }
        }

        Http::data(['locations' => $locations, 'branch_names_available' => $branches !== []]);
    }

    public static function saveLocation(): void
    {
        [$auth, $ctx] = self::enter('appointments.resources.manage');

        $body = Http::body();
        $boId = (int) ($body['bo_id'] ?? 0);

        if ($boId <= 0) {
            Http::validationFailed('bo_id is required — pick a branch.');
        }

        $existing = Db::first(
            'SELECT * FROM appointment_locations_config WHERE cmp_id = :cmp AND bo_id = :bo',
            ['cmp' => $ctx->cmpId, 'bo' => $boId],
        );

        $values = [
            'accepts_bookings' => (bool) ($body['accepts_bookings'] ?? true),
            'bookable_online'  => (bool) ($body['bookable_online'] ?? true),
            'timezone'         => trim((string) ($body['timezone'] ?? '')) ?: \Aicountly\Api\Domain\Settings::for($ctx)['timezone'],
            'arrival_instructions' => substr(trim((string) ($body['arrival_instructions'] ?? '')), 0, 2000),
            'updated_at'       => Clock::sql(Clock::now()),
        ];

        if ($existing === null) {
            Db::insert('appointment_locations_config', $values + [
                'location_uuid' => Uuid::v4(),
                'cmp_id'        => $ctx->cmpId,
                'bo_id'         => $boId,
            ], 'location_uuid');
        } else {
            Db::update('appointment_locations_config', $values, ['cmp_id' => $ctx->cmpId, 'bo_id' => $boId]);
        }

        Audit::record($ctx, $auth, 'location.configured', 'location', (string) $boId, $existing, $values);

        Http::data([
            'location' => Db::first(
                'SELECT * FROM appointment_locations_config WHERE cmp_id = :cmp AND bo_id = :bo',
                ['cmp' => $ctx->cmpId, 'bo' => $boId],
            ),
        ], $existing === null ? 201 : 200);
    }

    public static function index(): void
    {
        [, $ctx] = self::enter('appointments.booking.view');

        $rows = Db::all(
            'SELECT r.*,
                    (SELECT COUNT(*) FROM appointment_service_resources sr
                      WHERE sr.resource_uuid = r.resource_uuid) AS service_count
               FROM appointment_resources r
              WHERE r.cmp_id = :cmp
                AND (:bo = 0 OR r.bo_id = :bo OR r.bo_id = 0)
                ' . (Http::param('include_inactive') !== null ? '' : 'AND r.is_active = TRUE') . '
              ORDER BY r.kind, r.name',
            ['cmp' => $ctx->cmpId, 'bo' => $ctx->boId],
        );

        Http::data([
            'resources' => array_map([self::class, 'shapeResource'], $rows),
            'kinds'     => self::KINDS,
        ]);
    }

    public static function create(): void
    {
        [$auth, $ctx] = self::enter('appointments.resources.manage');

        $body = Http::body();
        $name = trim((string) ($body['name'] ?? ''));

        if ($name === '') {
            Http::validationFailed('A name is required.');
        }

        $kind = strtolower(trim((string) ($body['kind'] ?? 'room')));
        if (!in_array($kind, self::KINDS, true)) {
            Http::validationFailed('Kind must be one of: ' . implode(', ', self::KINDS) . '.');
        }

        $resourceUuid = Uuid::v4();

        Db::insert('appointment_resources', [
            'resource_uuid' => $resourceUuid,
            'cmp_id'        => $ctx->cmpId,
            'bo_id'         => (int) ($body['bo_id'] ?? $ctx->boId),
            'name'          => substr($name, 0, 200),
            'kind'          => $kind,
            'capacity'      => max(1, (int) ($body['capacity'] ?? 1)),
            'calendar_subscriber_uuid' => self::trimOrNull($body['calendar_subscriber_uuid'] ?? null),
            'turnaround_minutes' => max(0, min(240, (int) ($body['turnaround_minutes'] ?? 0))),
        ], 'resource_uuid');

        Audit::record($ctx, $auth, 'resource.created', 'resource', $resourceUuid, null, ['name' => $name, 'kind' => $kind]);

        Http::data([
            'resource' => self::shapeResource(Db::first(
                'SELECT * FROM appointment_resources WHERE resource_uuid = :id',
                ['id' => $resourceUuid],
            ) ?? []),
        ], 201);
    }

    public static function update(string $resourceUuid): void
    {
        [$auth, $ctx] = self::enter('appointments.resources.manage');

        $existing = Db::first(
            'SELECT * FROM appointment_resources WHERE resource_uuid = :id AND cmp_id = :cmp',
            ['id' => $resourceUuid, 'cmp' => $ctx->cmpId],
        );

        if ($existing === null) {
            Http::notFound('That resource does not exist.');
        }

        $body = Http::body();
        $values = [];

        foreach (['name', 'capacity', 'calendar_subscriber_uuid', 'turnaround_minutes', 'is_active', 'bo_id'] as $field) {
            if (array_key_exists($field, $body)) {
                $values[$field] = $body[$field] === '' ? null : $body[$field];
            }
        }

        if (array_key_exists('kind', $body)) {
            $kind = strtolower(trim((string) $body['kind']));
            if (!in_array($kind, self::KINDS, true)) {
                Http::validationFailed('Kind must be one of: ' . implode(', ', self::KINDS) . '.');
            }
            $values['kind'] = $kind;
        }

        if ($values !== []) {
            $values['updated_at'] = Clock::sql(Clock::now());
            Db::update('appointment_resources', $values, ['resource_uuid' => $resourceUuid, 'cmp_id' => $ctx->cmpId]);
        }

        Audit::record($ctx, $auth, 'resource.updated', 'resource', $resourceUuid, [
            'name' => $existing['name'], 'is_active' => $existing['is_active'],
        ], $values);

        Http::data([
            'resource' => self::shapeResource(Db::first(
                'SELECT * FROM appointment_resources WHERE resource_uuid = :id AND cmp_id = :cmp',
                ['id' => $resourceUuid, 'cmp' => $ctx->cmpId],
            ) ?? []),
        ]);
    }

    // -----------------------------------------------------------------------

    /**
     * Branches from Manage.
     *
     * @return array<int, array{name: ?string, address: ?string}>
     */
    private static function branches(\Aicountly\Api\Auth $auth, \Aicountly\Api\Context $ctx): array
    {
        if ($auth->isService() || $auth->sesKey() === '') {
            return [];
        }

        try {
            $result = (new ManageClient())->withSession($auth->sesKey())->companyInfo($ctx->cmpId);
        } catch (\Throwable) {
            return [];
        }

        if (!$result['ok']) {
            return [];
        }

        $body = $result['body']['data'] ?? $result['body'] ?? [];
        $branches = $body['branches'] ?? $body['bo'] ?? [];
        $out = [];

        foreach (is_array($branches) ? $branches : [] as $branch) {
            if (!is_array($branch)) {
                continue;
            }
            $boId = (int) ($branch['bo_id'] ?? $branch['id'] ?? 0);
            if ($boId <= 0) {
                continue;
            }
            $out[$boId] = [
                'name'    => trim((string) ($branch['bo_name'] ?? $branch['name'] ?? '')) ?: null,
                'address' => trim((string) ($branch['address'] ?? $branch['bo_address'] ?? '')) ?: null,
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function shapeResource(array $row): array
    {
        return [
            'resource_uuid' => (string) ($row['resource_uuid'] ?? ''),
            'name'          => (string) ($row['name'] ?? ''),
            'kind'          => (string) ($row['kind'] ?? 'room'),
            'capacity'      => (int) ($row['capacity'] ?? 1),
            'bo_id'         => (int) ($row['bo_id'] ?? 0),
            'calendar_subscriber_uuid' => $row['calendar_subscriber_uuid'] ?? null,
            'turnaround_minutes' => (int) ($row['turnaround_minutes'] ?? 0),
            'is_active'     => (bool) ($row['is_active'] ?? true),
            'service_count' => isset($row['service_count']) ? (int) $row['service_count'] : null,
        ];
    }

    private static function trimOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
