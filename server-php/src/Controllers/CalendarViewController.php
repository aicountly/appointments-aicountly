<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Clients\CalendarClient;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\BookingService;
use Aicountly\Api\Domain\Settings;
use Aicountly\Api\Http;
use Aicountly\Api\Support\Clock;

/**
 * The calendar view inside Appointments.
 *
 * ## Appointments may draw a calendar; it may not own one
 *
 * This product has its own calendar UI — a week grid the business actually
 * works from — and every event in it comes from Aicountly Calendar on the
 * request that draws it. There is no local event table and no synchronisation.
 *
 * What comes back is two overlaid layers:
 *
 *  - APPOINTMENTS: this product's own bookings, with their status, service and
 *    client. Ours to describe.
 *  - BUSY: everything else on the practitioners' calendars, as times only.
 *    Somebody's dentist appointment blocks 3pm and that is all a practice
 *    calendar needs to know about it. Titles are not read and not shown, which
 *    is both a privacy position and the free/busy contract.
 *
 * ## When Calendar cannot be read
 *
 * The appointments layer still renders — it is ours — and the busy layer comes
 * back empty with `calendar_available: false`. The UI shows the day with a
 * banner saying the rest of the diary could not be loaded, which is honest. It
 * does NOT silently draw a day that looks emptier than it is.
 */
final class CalendarViewController extends Controller
{
    /** A calendar view wider than this is a report. */
    private const MAX_RANGE_DAYS = 45;

    public static function view(): void
    {
        [$auth, $ctx] = self::enter('appointments.booking.view');

        $settings = Settings::for($ctx);
        $zone = Clock::zone($settings['timezone']);
        $now = Clock::now();

        $from = Clock::parse(Http::param('from')) ?? Clock::startOfLocalDay($now, $zone);
        $to = Clock::parse(Http::param('to')) ?? $from->modify('+7 days');

        if ($to <= $from) {
            Http::validationFailed('The date range must end after it starts.');
        }
        if (($to->getTimestamp() - $from->getTimestamp()) > self::MAX_RANGE_DAYS * 86400) {
            Http::validationFailed('A calendar view can cover at most ' . self::MAX_RANGE_DAYS . ' days.');
        }

        $memberFilter = trim((string) (Http::param('member_uuid') ?? ''));

        $members = Db::all(
            'SELECT member_uuid, user_uuid, calendar_subscriber_uuid, display_label, job_title, timezone
               FROM appointment_team_members
              WHERE cmp_id = :cmp AND is_active = TRUE
                ' . ($memberFilter !== '' ? 'AND member_uuid = :member' : '') . '
              ORDER BY display_label NULLS LAST, created_at',
            $memberFilter !== ''
                ? ['cmp' => $ctx->cmpId, 'member' => $memberFilter]
                : ['cmp' => $ctx->cmpId],
        );

        Http::data([
            'window' => [
                'from'     => Clock::iso($from),
                'to'       => Clock::iso($to),
                'timezone' => $zone->getName(),
            ],
            'business_hours' => [
                'day_starts_minute' => (int) $settings['day_starts_minute'],
                'day_ends_minute'   => (int) $settings['day_ends_minute'],
                'week_starts_on'    => (int) $settings['week_starts_on'],
                'slot_minutes'      => (int) $settings['slot_granularity_minutes'],
            ],
            'members'      => array_map(static fn (array $m) => [
                'member_uuid' => (string) $m['member_uuid'],
                'label'       => trim((string) ($m['display_label'] ?? '')) ?: 'Team member',
                'job_title'   => $m['job_title'],
            ], $members),
            'appointments' => self::appointments($ctx, $from, $to, $zone, $memberFilter),
            'busy'         => self::busyLayer($auth, $members, $from, $to),
        ]);
    }

    /**
     * This product's own bookings in the window.
     *
     * @return list<array<string, mixed>>
     */
    private static function appointments(
        \Aicountly\Api\Context $ctx,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeZone $zone,
        string $memberFilter,
    ): array {
        [$scope, $bind] = $ctx->scopeClause('b');
        $bind += ['from' => Clock::sql($from), 'to' => Clock::sql($to)];

        $memberSql = '';
        if ($memberFilter !== '') {
            $memberSql = ' AND b.member_uuid = :member';
            $bind['member'] = $memberFilter;
        }

        $rows = Db::all(
            'SELECT b.booking_uuid, b.reference, b.starts_at, b.ends_at, b.status, b.mode,
                    b.client_name, b.contact_uuid, b.member_uuid, b.bo_id, b.calendar_sync_state,
                    s.name AS service_name, s.colour,
                    m.display_label AS member_label,
                    r.name AS resource_name
               FROM ' . BookingService::TABLE . ' b
               JOIN appointment_services s ON s.service_uuid = b.service_uuid
          LEFT JOIN appointment_team_members m ON m.member_uuid = b.member_uuid
          LEFT JOIN appointment_resources r ON r.resource_uuid = b.resource_uuid
              WHERE ' . $scope . ' AND b.starts_at < :to AND b.ends_at > :from' . $memberSql . '
              ORDER BY b.starts_at',
            $bind,
        );

        return array_map(static function (array $row) use ($zone): array {
            $starts = Clock::parse((string) $row['starts_at']);
            $ends = Clock::parse((string) $row['ends_at']);

            return [
                'kind'         => 'appointment',
                'booking_uuid' => (string) $row['booking_uuid'],
                'reference'    => (string) $row['reference'],
                'starts_at'    => $starts !== null ? Clock::iso($starts) : null,
                'ends_at'      => $ends !== null ? Clock::iso($ends) : null,
                'local_date'   => $starts !== null ? $starts->setTimezone($zone)->format('Y-m-d') : null,
                'local_time'   => $starts !== null ? $starts->setTimezone($zone)->format('H:i') : null,
                'status'       => (string) $row['status'],
                'mode'         => (string) $row['mode'],
                'title'        => trim((string) $row['client_name']) ?: 'Client',
                'service_name' => (string) $row['service_name'],
                'colour'       => $row['colour'],
                'member_uuid'  => $row['member_uuid'],
                'member_label' => $row['member_label'],
                'resource_name' => $row['resource_name'],
                'contact_uuid' => $row['contact_uuid'],
                'calendar_sync_state' => (string) $row['calendar_sync_state'],
            ];
        }, $rows);
    }

    /**
     * Everything else on the team's calendars — times only.
     *
     * One free/busy call for the whole grid, and the booking layer's own events
     * are filtered out so an appointment does not appear twice.
     *
     * @param list<array<string, mixed>> $members
     * @return array<string, mixed>
     */
    private static function busyLayer(
        \Aicountly\Api\Auth $auth,
        array $members,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        if ($members === []) {
            return ['available' => true, 'message' => null, 'blocks' => []];
        }

        $client = new CalendarClient();

        if (!$client->configured()) {
            return [
                'available' => false,
                'message'   => 'Aicountly Calendar is not configured, so only Appointments bookings are shown.',
                'blocks'    => [],
            ];
        }

        $byCalendar = [];
        $calendars = [];
        foreach ($members as $member) {
            $uuid = trim((string) $member['calendar_subscriber_uuid']);
            if ($uuid !== '') {
                $calendars[$uuid] = true;
                $byCalendar[$uuid] = (string) $member['member_uuid'];
            }
        }

        $calendars = array_keys($calendars);
        if ($calendars === []) {
            return ['available' => true, 'message' => null, 'blocks' => []];
        }

        $result = $client->forSubscriber($calendars[0])->freeBusy($calendars, Clock::iso($from), Clock::iso($to));

        if (!$result['ok']) {
            return [
                'available' => false,
                'message'   => 'Aicountly Calendar is temporarily unavailable, so only Appointments bookings are shown.',
                'blocks'    => [],
            ];
        }

        $body = $result['body']['data'] ?? $result['body'] ?? [];
        $blocks = [];
        $partial = [];

        foreach ((array) ($body['subscribers'] ?? []) as $entry) {
            $subscriber = (string) ($entry['subscriber_uuid'] ?? '');
            if ($subscriber === '') {
                continue;
            }

            if (($entry['available'] ?? true) !== true) {
                $partial[] = $byCalendar[$subscriber] ?? $subscriber;
                continue;
            }

            foreach ((array) ($entry['busy'] ?? []) as $busy) {
                $blocks[] = [
                    'kind'        => 'busy',
                    'member_uuid' => $byCalendar[$subscriber] ?? null,
                    'starts_at'   => (string) ($busy['start_at'] ?? ''),
                    'ends_at'     => (string) ($busy['end_at'] ?? ''),
                    'all_day'     => (bool) ($busy['all_day'] ?? false),
                    'status'      => (string) ($busy['status'] ?? 'confirmed'),
                    // Where it came from, so a practitioner can tell their own
                    // Google entry from a booking. Never a title.
                    'source'      => (string) ($busy['source'] ?? 'aicountly_native'),
                    'event_id'    => (string) ($busy['event_id'] ?? ''),
                ];
            }
        }

        return [
            'available' => $partial === [],
            'message'   => $partial === []
                ? null
                : 'Some calendars could not be read, so this view may be missing busy time.',
            'blocks'    => $blocks,
        ];
    }
}
