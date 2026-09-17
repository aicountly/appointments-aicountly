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
 * The team, and when each of them takes appointments.
 *
 * ## A team member is not an employee record
 *
 * Manage owns people. A row here is a `user_uuid` plus the
 * appointment-specific settings: which services they deliver, which hours they
 * take bookings in, how long their buffers are. No name, no email, no phone —
 * those are read from Manage on the request that needs them, so somebody
 * renamed there is renamed here without Appointments being told.
 *
 * ## Working hours are not free/busy
 *
 * This says "Dr Sharma takes appointments Monday to Friday, 9–1 and 2–6".
 * Whether 10:30 next Tuesday is already taken is Calendar's answer, read live
 * by AvailabilityService. Both are needed and neither substitutes for the other
 * — which is why `calendar_subscriber_uuid` is on the row: it is the link
 * between this product's idea of a practitioner and Calendar's.
 */
final class TeamController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter('appointments.booking.view');

        $rows = Db::all(
            'SELECT m.*,
                    (SELECT COUNT(*) FROM appointment_service_staff ss WHERE ss.member_uuid = m.member_uuid) AS service_count,
                    (SELECT COUNT(*) FROM appointment_team_availability a WHERE a.member_uuid = m.member_uuid) AS window_count
               FROM appointment_team_members m
              WHERE m.cmp_id = :cmp
                ' . (Http::param('include_inactive') !== null ? '' : 'AND m.is_active = TRUE') . '
              ORDER BY m.display_label NULLS LAST, m.created_at',
            ['cmp' => $ctx->cmpId],
        );

        $names = self::manageNames($auth, $ctx);

        Http::data([
            'members' => array_map(static function (array $row) use ($names): array {
                $userUuid = (string) $row['user_uuid'];

                return self::shape($row) + [
                    'label' => $names[$userUuid]
                        ?? (trim((string) ($row['display_label'] ?? '')) ?: 'Team member'),
                    // Whether Manage could confirm this person. A member whose
                    // name is missing has usually been removed from the company
                    // in Manage, which is worth showing rather than hiding.
                    'confirmed_in_manage' => isset($names[$userUuid]),
                ];
            }, $rows),
            'names_available' => $names !== [],
        ]);
    }

    /**
     * People in this company who could be added to the team.
     *
     * Read live from Manage. This is what makes "add somebody" a list of real
     * colleagues rather than a box to paste a uuid into.
     */
    public static function candidates(): void
    {
        [$auth, $ctx] = self::enter('appointments.team.manage');

        $result = (new ManageClient())->withSession($auth->sesKey())->companyMembers($ctx->cmpId);

        if (!$result['ok']) {
            Http::error(503, 'manage_unavailable', 'Aicountly Manage is temporarily unavailable, so the list of people cannot be loaded.', [
                'retryable' => true,
            ]);
        }

        $existing = [];
        foreach (Db::all('SELECT user_uuid FROM appointment_team_members WHERE cmp_id = :cmp', ['cmp' => $ctx->cmpId]) as $row) {
            $existing[(string) $row['user_uuid']] = true;
        }

        $rows = $result['body']['data'] ?? $result['body']['members'] ?? $result['body'] ?? [];
        $candidates = [];

        foreach (is_array($rows) ? $rows : [] as $person) {
            if (!is_array($person)) {
                continue;
            }
            $uuid = trim((string) ($person['uuid_aictly'] ?? $person['user_uuid'] ?? $person['uuid'] ?? ''));
            if ($uuid === '') {
                continue;
            }
            $candidates[] = [
                'user_uuid'    => $uuid,
                'label'        => trim((string) ($person['name'] ?? $person['full_name'] ?? $person['user_name'] ?? '')) ?: $uuid,
                'email'        => $person['email'] ?? null,
                'already_added' => isset($existing[$uuid]),
            ];
        }

        Http::data(['candidates' => $candidates]);
    }

    public static function show(string $memberUuid): void
    {
        [$auth, $ctx] = self::enter('appointments.booking.view');

        $row = Db::first(
            'SELECT * FROM appointment_team_members WHERE member_uuid = :id AND cmp_id = :cmp',
            ['id' => $memberUuid, 'cmp' => $ctx->cmpId],
        );

        if ($row === null) {
            Http::notFound('That team member does not exist.');
        }

        $names = self::manageNames($auth, $ctx);

        Http::data([
            'member'   => self::shape($row) + [
                'label' => $names[(string) $row['user_uuid']]
                    ?? (trim((string) ($row['display_label'] ?? '')) ?: 'Team member'),
            ],
            'availability' => Db::all(
                'SELECT * FROM appointment_team_availability
                  WHERE member_uuid = :id AND cmp_id = :cmp
                  ORDER BY day_of_week, starts_minute',
                ['id' => $memberUuid, 'cmp' => $ctx->cmpId],
            ),
            'services' => Db::all(
                'SELECT ss.service_uuid, ss.priority, ss.duration_override_minutes, s.name, s.duration_minutes
                   FROM appointment_service_staff ss
                   JOIN appointment_services s ON s.service_uuid = ss.service_uuid
                  WHERE ss.member_uuid = :id AND ss.cmp_id = :cmp
                  ORDER BY s.name',
                ['id' => $memberUuid, 'cmp' => $ctx->cmpId],
            ),
        ]);
    }

    public static function create(): void
    {
        [$auth, $ctx] = self::enter('appointments.team.manage');

        $body = Http::body();
        $userUuid = trim((string) ($body['user_uuid'] ?? ''));

        if ($userUuid === '') {
            Http::validationFailed('user_uuid is required — pick somebody from the company.');
        }

        $duplicate = Db::first(
            'SELECT member_uuid FROM appointment_team_members WHERE cmp_id = :cmp AND user_uuid = :user',
            ['cmp' => $ctx->cmpId, 'user' => $userUuid],
        );

        if ($duplicate !== null) {
            Http::conflict('That person is already on the team.', ['member_uuid' => $duplicate['member_uuid']]);
        }

        $memberUuid = Uuid::v4();

        Db::insert('appointment_team_members', [
            'member_uuid' => $memberUuid,
            'cmp_id'      => $ctx->cmpId,
            'user_uuid'   => $userUuid,
            // Defaults to the person's own calendar, which is almost always
            // right: a practitioner's appointments belong in the diary they
            // already look at.
            'calendar_subscriber_uuid' => trim((string) ($body['calendar_subscriber_uuid'] ?? '')) ?: $userUuid,
            'display_label'   => self::trimOrNull($body['display_label'] ?? null),
            'job_title'       => self::trimOrNull($body['job_title'] ?? null),
            'accepts_bookings' => (bool) ($body['accepts_bookings'] ?? true),
            'bookable_online' => (bool) ($body['bookable_online'] ?? true),
            'timezone'        => trim((string) ($body['timezone'] ?? '')) ?: \Aicountly\Api\Domain\Settings::for($ctx)['timezone'],
        ], 'member_uuid');

        if (isset($body['availability'])) {
            self::replaceAvailability($ctx, $memberUuid, (array) $body['availability']);
        }

        Audit::record($ctx, $auth, 'team_member.added', 'team_member', $memberUuid, null, ['user_uuid' => $userUuid]);

        Http::data([
            'member' => self::shape(Db::first(
                'SELECT * FROM appointment_team_members WHERE member_uuid = :id',
                ['id' => $memberUuid],
            ) ?? []),
        ], 201);
    }

    public static function update(string $memberUuid): void
    {
        [$auth, $ctx] = self::enter('appointments.team.manage');

        $existing = Db::first(
            'SELECT * FROM appointment_team_members WHERE member_uuid = :id AND cmp_id = :cmp',
            ['id' => $memberUuid, 'cmp' => $ctx->cmpId],
        );

        if ($existing === null) {
            Http::notFound('That team member does not exist.');
        }

        $body = Http::body();
        $values = [];

        foreach ([
            'display_label', 'job_title', 'accepts_bookings', 'bookable_online',
            'buffer_before_mins', 'buffer_after_mins', 'max_daily_appointments',
            'timezone', 'is_active', 'calendar_subscriber_uuid',
        ] as $field) {
            if (array_key_exists($field, $body)) {
                $values[$field] = $body[$field] === '' ? null : $body[$field];
            }
        }

        if ($values !== []) {
            $values['updated_at'] = Clock::sql(Clock::now());
            Db::update('appointment_team_members', $values, ['member_uuid' => $memberUuid, 'cmp_id' => $ctx->cmpId]);
        }

        if (array_key_exists('availability', $body)) {
            self::replaceAvailability($ctx, $memberUuid, (array) $body['availability']);
        }

        Audit::record($ctx, $auth, 'team_member.updated', 'team_member', $memberUuid, [
            'accepts_bookings' => $existing['accepts_bookings'],
            'is_active'        => $existing['is_active'],
        ], $values);

        Http::data([
            'member' => self::shape(Db::first(
                'SELECT * FROM appointment_team_members WHERE member_uuid = :id AND cmp_id = :cmp',
                ['id' => $memberUuid, 'cmp' => $ctx->cmpId],
            ) ?? []),
        ]);
    }

    /**
     * Replace somebody's whole working-hours pattern.
     *
     * Wholesale rather than per-window: an availability editor sends the week
     * it wants, and reconciling individual windows client-side is how a
     * Tuesday ends up with two overlapping openings nobody meant.
     *
     * @param list<mixed> $windows
     */
    private static function replaceAvailability(\Aicountly\Api\Context $ctx, string $memberUuid, array $windows): void
    {
        Db::transaction(function () use ($ctx, $memberUuid, $windows): void {
            Db::run(
                'DELETE FROM appointment_team_availability WHERE member_uuid = :id AND cmp_id = :cmp',
                ['id' => $memberUuid, 'cmp' => $ctx->cmpId],
            );

            foreach ($windows as $window) {
                if (!is_array($window)) {
                    continue;
                }

                $dow = (int) ($window['day_of_week'] ?? -1);
                $from = (int) ($window['starts_minute'] ?? -1);
                $to = (int) ($window['ends_minute'] ?? -1);

                if ($dow < 0 || $dow > 6 || $from < 0 || $to <= $from || $to > 1440) {
                    continue;
                }

                Db::insert('appointment_team_availability', [
                    'availability_uuid' => Uuid::v4(),
                    'cmp_id'         => $ctx->cmpId,
                    'member_uuid'    => $memberUuid,
                    'bo_id'          => (int) ($window['bo_id'] ?? 0),
                    'day_of_week'    => $dow,
                    'starts_minute'  => $from,
                    'ends_minute'    => $to,
                    'kind'           => ($window['kind'] ?? 'available') === 'blocked' ? 'blocked' : 'available',
                    'effective_from' => self::trimOrNull($window['effective_from'] ?? null),
                    'effective_to'   => self::trimOrNull($window['effective_to'] ?? null),
                ], 'availability_uuid');
            }
        });
    }

    /** @return array<string, string> */
    private static function manageNames(\Aicountly\Api\Auth $auth, \Aicountly\Api\Context $ctx): array
    {
        if ($auth->isService() || $auth->sesKey() === '') {
            return [];
        }

        try {
            $result = (new ManageClient())->withSession($auth->sesKey())->companyMembers($ctx->cmpId);
        } catch (\Throwable) {
            return [];
        }

        if (!$result['ok']) {
            return [];
        }

        $rows = $result['body']['data'] ?? $result['body']['members'] ?? $result['body'] ?? [];
        $out = [];

        foreach (is_array($rows) ? $rows : [] as $person) {
            if (!is_array($person)) {
                continue;
            }
            $uuid = trim((string) ($person['uuid_aictly'] ?? $person['user_uuid'] ?? $person['uuid'] ?? ''));
            $name = trim((string) ($person['name'] ?? $person['full_name'] ?? $person['user_name'] ?? ''));
            if ($uuid !== '' && $name !== '') {
                $out[$uuid] = $name;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function shape(array $row): array
    {
        return [
            'member_uuid'       => (string) ($row['member_uuid'] ?? ''),
            'user_uuid'         => (string) ($row['user_uuid'] ?? ''),
            'calendar_subscriber_uuid' => (string) ($row['calendar_subscriber_uuid'] ?? ''),
            'display_label'     => $row['display_label'] ?? null,
            'job_title'         => $row['job_title'] ?? null,
            'accepts_bookings'  => (bool) ($row['accepts_bookings'] ?? true),
            'bookable_online'   => (bool) ($row['bookable_online'] ?? true),
            'buffer_before_mins' => $row['buffer_before_mins'] === null ? null : (int) $row['buffer_before_mins'],
            'buffer_after_mins' => $row['buffer_after_mins'] === null ? null : (int) $row['buffer_after_mins'],
            'max_daily_appointments' => $row['max_daily_appointments'] === null ? null : (int) $row['max_daily_appointments'],
            'timezone'          => (string) ($row['timezone'] ?? 'Asia/Kolkata'),
            'is_active'         => (bool) ($row['is_active'] ?? true),
            'service_count'     => isset($row['service_count']) ? (int) $row['service_count'] : null,
            'window_count'      => isset($row['window_count']) ? (int) $row['window_count'] : null,
        ];
    }

    private static function trimOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
