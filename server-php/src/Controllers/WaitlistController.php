<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\Settings;
use Aicountly\Api\Domain\WaitlistService;
use Aicountly\Api\Http;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

/**
 * The waitlist: who is waiting, who matches a freed slot, and offering it.
 *
 * ## Matching is explainable
 *
 * `matches` returns the reasons each client matched — the service, the
 * practitioner they asked for, the daypart, how long they have waited. A
 * receptionist sees WHY before they ring somebody, which is the difference
 * between a useful list and a ranked mystery. See WaitlistService.
 *
 * ## One offer at a time
 *
 * Offering the same slot to three people and giving it to whoever answers first
 * turns a waitlist into a lottery and a complaint. An offer holds the slot for
 * one client for two hours; when it lapses the sweep returns them to `waiting`
 * and the next match can be offered.
 *
 * ## Telling them is somebody else's job
 *
 * This endpoint records the offer. The message goes through the messaging
 * provider or Receptionist — neither is reimplemented here.
 */
final class WaitlistController extends Controller
{
    public static function index(): void
    {
        [, $ctx] = self::enter('appointments.booking.view');

        // Lapsed offers and expired entries are tidied on read rather than by a
        // cron, so the list is never showing an offer that ran out twenty
        // minutes ago.
        $swept = (new WaitlistService($ctx))->sweep();

        $params = Http::listParams(['created_at', 'priority', 'earliest_date'], 'priority');
        $where = ['w.cmp_id = :cmp'];
        $bind = ['cmp' => $ctx->cmpId];

        $status = strtolower(trim((string) (Http::param('status') ?? '')));
        if (in_array($status, ['waiting', 'offered', 'booked', 'expired', 'withdrawn'], true)) {
            $where[] = 'w.status = :status';
            $bind['status'] = $status;
        }

        $serviceUuid = trim((string) (Http::param('service_uuid') ?? ''));
        if ($serviceUuid !== '' && Uuid::isValid($serviceUuid)) {
            $where[] = 'w.service_uuid = :service';
            $bind['service'] = $serviceUuid;
        }

        if ($params['q'] !== '') {
            $where[] = '(w.client_name ILIKE :q OR w.client_phone ILIKE :q OR w.client_email ILIKE :q)';
            $bind['q'] = '%' . $params['q'] . '%';
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) Db::scalar(
            'SELECT COUNT(*) FROM ' . WaitlistService::TABLE . ' w WHERE ' . $whereSql,
            $bind,
        );

        $rows = Db::all(
            'SELECT w.*, s.name AS service_name, m.display_label AS member_label
               FROM ' . WaitlistService::TABLE . ' w
               JOIN appointment_services s ON s.service_uuid = w.service_uuid
          LEFT JOIN appointment_team_members m ON m.member_uuid = w.preferred_member_uuid
              WHERE ' . $whereSql . '
              ORDER BY w.' . $params['sort'] . ' ' . $params['order'] . ', w.created_at ASC
              LIMIT ' . $params['limit'] . ' OFFSET ' . $params['offset'],
            $bind,
        );

        Http::list(
            array_map([self::class, 'shape'], $rows),
            $total,
            $params['limit'],
            $params['offset'],
            [
                'enabled'  => Settings::featureEnabled($ctx, 'WAITLIST'),
                'demand'   => (new WaitlistService($ctx))->demandByService(),
                'swept'    => $swept,
            ],
        );
    }

    public static function create(): void
    {
        [$auth, $ctx] = self::enter('appointments.waitlist.manage');

        if (!Settings::featureEnabled($ctx, 'WAITLIST')) {
            Http::error(409, 'waitlist_disabled', 'The waitlist is turned off for this company.');
        }

        $body = Http::body();
        $errors = [];

        $serviceUuid = trim((string) ($body['service_uuid'] ?? ''));
        if (!Uuid::isValid($serviceUuid)) {
            $errors['service_uuid'] = 'A service is required.';
        }

        $earliest = trim((string) ($body['earliest_date'] ?? ''));
        $latest = trim((string) ($body['latest_date'] ?? ''));
        if ($earliest === '' || $latest === '') {
            $errors['earliest_date'] = 'A date range is required.';
        } elseif ($latest < $earliest) {
            $errors['latest_date'] = 'The last date must not be before the first.';
        }

        // Somebody with no phone and no email cannot be told a slot came free,
        // which makes the entry a row nobody can act on.
        if (trim((string) ($body['client_phone'] ?? '')) === '' && trim((string) ($body['client_email'] ?? '')) === '') {
            $errors['client_phone'] = 'A phone number or an email address is required so the client can be offered a slot.';
        }

        if ($errors !== []) {
            Http::validationFailed('Check the waitlist entry.', $errors);
        }

        $entry = (new WaitlistService($ctx))->add($body + ['created_by_uuid' => $auth->uuid]);

        Audit::record($ctx, $auth, 'waitlist.added', 'waitlist', (string) ($entry['waitlist_uuid'] ?? ''), null, [
            'service_uuid' => $serviceUuid,
        ]);

        Http::data(['entry' => self::shape($entry)], 201);
    }

    /**
     * Who would take this slot.
     *
     * The slot is described by the caller rather than looked up: whoever is
     * asking has just released it and knows exactly what it is, and
     * re-deriving it would cost a Calendar round trip for no new information.
     */
    public static function matches(): void
    {
        [, $ctx] = self::enter('appointments.booking.view');

        $serviceUuid = trim((string) (Http::param('service_uuid') ?? ''));
        $startsAt = Clock::parse(Http::param('starts_at'));

        if (!Uuid::isValid($serviceUuid) || $startsAt === null) {
            Http::validationFailed('service_uuid and starts_at are required.');
        }

        $matches = (new WaitlistService($ctx))->matchesForSlot(
            $serviceUuid,
            Http::param('member_uuid'),
            Http::intParam('bo_id', $ctx->boId) ?? 0,
            $startsAt,
            Http::intParam('limit', 5) ?? 5,
        );

        Http::data([
            'matches' => $matches,
            'slot'    => ['starts_at' => Clock::iso($startsAt), 'service_uuid' => $serviceUuid],
            // Said explicitly so the UI can label the list honestly.
            'method'  => 'Matched on service, practitioner preference, time of day, weekday and waiting time.',
        ]);
    }

    public static function offer(string $waitlistUuid): void
    {
        [$auth, $ctx] = self::enter('appointments.waitlist.manage');

        $body = Http::body();
        $startsAt = Clock::parse($body['starts_at'] ?? null);

        if ($startsAt === null) {
            Http::validationFailed('starts_at is required — say which slot is being offered.');
        }

        $result = (new WaitlistService($ctx))->offer($waitlistUuid, $startsAt);

        if (!$result['ok']) {
            Http::conflict((string) $result['reason']);
        }

        Audit::record($ctx, $auth, 'waitlist.offered', 'waitlist', $waitlistUuid, null, [
            'starts_at' => Clock::iso($startsAt),
        ]);

        Http::data([
            'entry' => self::shape($result['entry'] ?? []),
            // The offer is recorded here; reaching the client is the messaging
            // provider's or Receptionist's job.
            'next_step' => 'The slot is held for this client. Contact them through the channel they prefer.',
        ]);
    }

    public static function withdraw(string $waitlistUuid): void
    {
        [$auth, $ctx] = self::enter('appointments.waitlist.manage');

        $service = new WaitlistService($ctx);
        $entry = $service->row($waitlistUuid);

        if ($entry === null) {
            Http::notFound('That waitlist entry does not exist.');
        }

        $service->withdraw($waitlistUuid);

        Audit::record($ctx, $auth, 'waitlist.withdrawn', 'waitlist', $waitlistUuid, ['status' => $entry['status']], ['status' => 'withdrawn']);

        Http::data(['entry' => self::shape($service->row($waitlistUuid) ?? [])]);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function shape(array $row): array
    {
        return [
            'waitlist_uuid' => (string) ($row['waitlist_uuid'] ?? ''),
            'status'        => (string) ($row['status'] ?? 'waiting'),
            'client'        => [
                'contact_uuid' => $row['contact_uuid'] ?? null,
                'name'         => (string) ($row['client_name'] ?? ''),
                'phone'        => (string) ($row['client_phone'] ?? ''),
                'email'        => (string) ($row['client_email'] ?? ''),
            ],
            'service'       => [
                'service_uuid' => (string) ($row['service_uuid'] ?? ''),
                'name'         => $row['service_name'] ?? null,
            ],
            'preferred_member' => $row['preferred_member_uuid'] === null ? null : [
                'member_uuid' => (string) $row['preferred_member_uuid'],
                'label'       => $row['member_label'] ?? null,
            ],
            'earliest_date' => substr((string) ($row['earliest_date'] ?? ''), 0, 10),
            'latest_date'   => substr((string) ($row['latest_date'] ?? ''), 0, 10),
            'daypart'       => (string) ($row['daypart'] ?? 'any'),
            'weekdays'      => is_array($row['weekdays'] ?? null) ? $row['weekdays'] : Db::jsonColumn($row['weekdays'] ?? null),
            'priority'      => (int) ($row['priority'] ?? 0),
            'notes'         => (string) ($row['notes'] ?? ''),
            'bo_id'         => (int) ($row['bo_id'] ?? 0),
            'offer'         => $row['offered_at'] === null ? null : [
                'offered_at'  => (string) $row['offered_at'],
                'expires_at'  => $row['offer_expires_at'] ?? null,
                'slot_start'  => $row['offered_slot_start'] ?? null,
            ],
            'booked_booking_uuid' => $row['booked_booking_uuid'] ?? null,
            'expires_at'    => $row['expires_at'] ?? null,
            'created_at'    => $row['created_at'] ?? null,
        ];
    }
}
