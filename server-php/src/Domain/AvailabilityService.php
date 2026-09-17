<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Clients\CalendarClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Which slots a client can actually be offered.
 *
 * ## The two halves, and why neither can do the job alone
 *
 * APPOINTMENTS knows the rules: this service is 45 minutes with a 10 minute
 * write-up after it, Dr Sharma takes it on Tuesdays between 9 and 1, you need
 * two hours' notice, nobody may book more than 60 days out, and there is a
 * room that has to be free too.
 *
 * CALENDAR knows the truth: whether Dr Sharma is actually free at 10:30 next
 * Tuesday, including the dentist appointment she put in her own Google calendar
 * this morning that nothing in this product will ever know about.
 *
 * A slot is offerable only when BOTH agree. Computing rules without Calendar
 * offers times that are already taken; reading Calendar without rules offers
 * 8:15pm on a Sunday.
 *
 * ## One call, not one per row
 *
 * The free/busy request is made ONCE for the whole window and every staff
 * member in it. A week of slots across six practitioners is one HTTP call to
 * Calendar. Doing it per candidate slot is how a booking page takes nine
 * seconds to paint and how Calendar's pool fills up with our traffic.
 *
 * ## When Calendar cannot be reached
 *
 * No slots, and `calendar_available: false` so the screen can say why. NOT the
 * rule-only slots with a warning: somebody would book one. An appointment
 * product that double-books because a dependency had a bad minute has done
 * more damage than one that briefly says "availability is temporarily
 * unavailable".
 */
final class AvailabilityService
{
    /** A search wider than this is a report, not a booking flow. */
    private const MAX_WINDOW_DAYS = 62;

    /** Slots beyond this in one response are scrolled past, never clicked. */
    private const MAX_SLOTS = 500;

    public function __construct(
        private readonly Context $ctx,
        private readonly ?CalendarClient $calendar = null,
    ) {
    }

    /**
     * Offerable slots for a service between two moments.
     *
     * @param array{
     *     service_uuid: string,
     *     member_uuid?: ?string,
     *     bo_id?: ?int,
     *     from: DateTimeImmutable,
     *     to: DateTimeImmutable,
     *     daypart?: ?string,
     *     limit?: int,
     *     ignore_booking_uuid?: ?string
     * } $query
     *
     * @return array{
     *     slots: list<array<string, mixed>>,
     *     calendar_available: bool,
     *     calendar_message: ?string,
     *     service: array<string, mixed>,
     *     members: list<array<string, mixed>>,
     *     window: array{from: string, to: string, timezone: string}
     * }
     */
    public function findSlots(array $query): array
    {
        $service = $this->service((string) $query['service_uuid']);
        $settings = Settings::for($this->ctx);
        $zone = Clock::zone($settings['timezone']);

        [$from, $to] = $this->clampWindow($query['from'], $query['to'], (int) $service['booking_horizon_days']);

        $members = $this->eligibleMembers($service, $query['member_uuid'] ?? null, $query['bo_id'] ?? null);

        $empty = [
            'slots'              => [],
            'calendar_available' => true,
            'calendar_message'   => null,
            'service'            => $service,
            'members'            => $members,
            'window'             => [
                'from'     => Clock::iso($from),
                'to'       => Clock::iso($to),
                'timezone' => $settings['timezone'],
            ],
        ];

        if ($members === [] || $from >= $to) {
            return $empty;
        }

        // ------------------------------------------------------------------
        // 1. The rules: candidate slots from working hours, duration, buffers,
        //    notice and granularity. Cheap, local, and no use on its own.
        // ------------------------------------------------------------------
        $candidates = $this->candidateSlots($service, $members, $from, $to, $zone, $settings);
        if ($candidates === []) {
            return $empty;
        }

        // ------------------------------------------------------------------
        // 2. The truth: one free/busy call for every calendar involved.
        // ------------------------------------------------------------------
        $busy = $this->busyByCalendar($members, $from, $to, $service);
        if (!$busy['available']) {
            return [
                'slots'              => [],
                'calendar_available' => false,
                'calendar_message'   => $busy['message'],
                'service'            => $service,
                'members'            => $members,
                'window'             => $empty['window'],
            ];
        }

        // ------------------------------------------------------------------
        // 3. This product's own claims on the same time: bookings that have no
        //    calendar event yet, and live holds somebody is mid-checkout on.
        // ------------------------------------------------------------------
        $internal = $this->internalBlocks($from, $to, $query['ignore_booking_uuid'] ?? null);

        $daypart = $this->normaliseDaypart($query['daypart'] ?? null);
        $limit = max(1, min(self::MAX_SLOTS, (int) ($query['limit'] ?? self::MAX_SLOTS)));

        $offerable = [];
        foreach ($candidates as $candidate) {
            if ($daypart !== null && Clock::daypart($candidate['start'], $zone) !== $daypart) {
                continue;
            }

            $calendarUuid = $candidate['calendar_subscriber_uuid'];
            $blocks = array_merge(
                $busy['by_calendar'][$calendarUuid] ?? [],
                $internal['by_member'][$candidate['member_uuid']] ?? [],
            );

            // The room, if the service needs one, has to be free as well. A
            // therapist with a gap and no room is not a bookable slot.
            foreach ($candidate['resource_uuids'] as $resourceUuid) {
                $blocks = array_merge(
                    $blocks,
                    $busy['by_resource'][$resourceUuid] ?? [],
                    $internal['by_resource'][$resourceUuid] ?? [],
                );
            }

            if ($this->overlapsAny($candidate['occupies_from'], $candidate['occupies_to'], $blocks)) {
                continue;
            }

            $offerable[] = [
                'starts_at'       => Clock::iso($candidate['start']),
                'ends_at'         => Clock::iso($candidate['end']),
                'local_time'      => $candidate['start']->setTimezone($zone)->format('H:i'),
                'local_date'      => $candidate['start']->setTimezone($zone)->format('Y-m-d'),
                'daypart'         => Clock::daypart($candidate['start'], $zone),
                'member_uuid'     => $candidate['member_uuid'],
                'member_label'    => $candidate['member_label'],
                'bo_id'           => $candidate['bo_id'],
                'resource_uuids'  => $candidate['resource_uuids'],
                'duration_minutes' => $candidate['duration_minutes'],
            ];

            if (count($offerable) >= $limit) {
                break;
            }
        }

        return [
            'slots'              => $offerable,
            'calendar_available' => true,
            'calendar_message'   => null,
            'service'            => $service,
            'members'            => $members,
            'window'             => $empty['window'],
        ];
    }

    /**
     * Is this exact slot still free? The check taken immediately before a write.
     *
     * Availability is a photograph; by the time somebody has filled in a form
     * the world has moved. This asks Calendar again, with the long timeout,
     * because the cost of being wrong here is a double booking.
     *
     * @param list<string> $resourceUuids
     * @return array{free: bool, checked: bool, reason: ?string}
     */
    public function revalidate(
        string $serviceUuid,
        ?string $memberUuid,
        array $resourceUuids,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        ?string $ignoreBookingUuid = null,
        ?string $ignoreHoldUuid = null,
    ): array {
        $service = $this->service($serviceUuid);

        $occupiesFrom = $start->modify('-' . (int) $service['buffer_before_mins'] . ' minutes');
        $occupiesTo   = $end->modify('+' . (int) $service['buffer_after_mins'] . ' minutes');

        // 1. Calendar, first and hardest.
        $calendars = [];
        if ($memberUuid !== null) {
            $member = $this->member($memberUuid);
            if ($member !== null) {
                $calendars[] = (string) $member['calendar_subscriber_uuid'];
            }
        }
        foreach ($this->resources($resourceUuids) as $resource) {
            if (($resource['calendar_subscriber_uuid'] ?? null) !== null) {
                $calendars[] = (string) $resource['calendar_subscriber_uuid'];
            }
        }
        $calendars = array_values(array_unique(array_filter($calendars)));

        if ($calendars !== []) {
            $client = $this->calendarClient();
            if (!$client->configured()) {
                return ['free' => false, 'checked' => false, 'reason' => 'Calendar is not configured for this deployment.'];
            }

            $ignoreEvents = [];
            if ($ignoreBookingUuid !== null) {
                $existing = Db::first(
                    'SELECT calendar_event_uuid FROM ' . BookingService::TABLE . '
                      WHERE booking_uuid = :id AND cmp_id = :cmp',
                    ['id' => $ignoreBookingUuid, 'cmp' => $this->ctx->cmpId],
                );
                if (!empty($existing['calendar_event_uuid'])) {
                    $ignoreEvents[] = (string) $existing['calendar_event_uuid'];
                }
            }

            $result = $client
                ->forSubscriber($calendars[0])
                ->conflictCheck($calendars, Clock::iso($occupiesFrom), Clock::iso($occupiesTo), $ignoreEvents);

            if (!$result['ok']) {
                return [
                    'free'    => false,
                    'checked' => false,
                    'reason'  => 'Calendar availability could not be confirmed, so this slot cannot be booked right now.',
                ];
            }

            $body = $result['body']['data'] ?? $result['body'] ?? [];
            if (($body['checked'] ?? false) !== true) {
                return [
                    'free'    => false,
                    'checked' => false,
                    'reason'  => 'One of the calendars involved could not be read, so this slot cannot be booked right now.',
                ];
            }
            if (($body['free'] ?? false) !== true) {
                return ['free' => false, 'checked' => true, 'reason' => 'That time was taken while you were booking.'];
            }
        }

        // 2. Our own claims on it.
        $internal = $this->internalBlocks($occupiesFrom, $occupiesTo, $ignoreBookingUuid, $ignoreHoldUuid);
        $ourBlocks = $memberUuid !== null ? ($internal['by_member'][$memberUuid] ?? []) : [];
        foreach ($resourceUuids as $resourceUuid) {
            $ourBlocks = array_merge($ourBlocks, $internal['by_resource'][$resourceUuid] ?? []);
        }

        if ($this->overlapsAny($occupiesFrom, $occupiesTo, $ourBlocks)) {
            return ['free' => false, 'checked' => true, 'reason' => 'Somebody else is booking that slot right now.'];
        }

        return ['free' => true, 'checked' => true, 'reason' => null];
    }

    // =======================================================================
    // Rules
    // =======================================================================

    /**
     * Candidate slots from the working-hours pattern, before Calendar is asked.
     *
     * @param array<string, mixed>              $service
     * @param list<array<string, mixed>>        $members
     * @param array<string, mixed>              $settings
     * @return list<array<string, mixed>>
     */
    private function candidateSlots(
        array $service,
        array $members,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        DateTimeZone $companyZone,
        array $settings,
    ): array {
        $granularity = max(5, (int) $settings['slot_granularity_minutes']);
        $now = Clock::now();
        $earliest = $now->modify('+' . (int) $service['min_notice_minutes'] . ' minutes');
        $requiredResources = $this->requiredResourceUuids((string) $service['service_uuid']);

        $windows = $this->availabilityWindows(array_column($members, 'member_uuid'));

        $slots = [];
        foreach ($members as $member) {
            $memberZone = Clock::zone($member['timezone'] ?? null, $settings['timezone']);
            $duration = (int) ($member['duration_override_minutes'] ?: $service['duration_minutes']);
            $bufferBefore = $member['buffer_before_mins'] !== null
                ? (int) $member['buffer_before_mins']
                : (int) $service['buffer_before_mins'];
            $bufferAfter = $member['buffer_after_mins'] !== null
                ? (int) $member['buffer_after_mins']
                : (int) $service['buffer_after_mins'];

            $memberWindows = $windows[$member['member_uuid']] ?? [];
            if ($memberWindows === []) {
                // No working hours configured is "not bookable", not "always
                // bookable". The opposite reading would offer 3am.
                continue;
            }

            $day = Clock::startOfLocalDay($from, $memberZone);
            $guard = 0;
            while ($day < $to && $guard++ < self::MAX_WINDOW_DAYS + 2) {
                $dow = Clock::localDayOfWeek($day, $memberZone);
                $date = $day->setTimezone($memberZone)->format('Y-m-d');

                $open = [];
                $closed = [];
                foreach ($memberWindows as $window) {
                    if ((int) $window['day_of_week'] !== $dow || !$this->windowAppliesOn($window, $date)) {
                        continue;
                    }
                    if ($window['kind'] === 'blocked') {
                        $closed[] = $window;
                    } else {
                        $open[] = $window;
                    }
                }

                foreach ($open as $window) {
                    $cursor = (int) $window['starts_minute'];
                    $closes = (int) $window['ends_minute'];

                    while ($cursor + $duration <= $closes) {
                        $start = Clock::atLocalMinute($day, $cursor, $memberZone);
                        $end = $start->modify('+' . $duration . ' minutes');

                        if ($start < $earliest || $start < $from || $end > $to) {
                            $cursor += $granularity;
                            continue;
                        }
                        if ($this->minuteRangeBlocked($cursor, $cursor + $duration, $closed)) {
                            $cursor += $granularity;
                            continue;
                        }

                        $slots[] = [
                            'start'            => $start,
                            'end'              => $end,
                            // Buffers make a slot occupy more than it shows.
                            // A client is told 10:30–11:15; the diary is busy
                            // 10:20–11:25.
                            'occupies_from'    => $start->modify('-' . $bufferBefore . ' minutes'),
                            'occupies_to'      => $end->modify('+' . $bufferAfter . ' minutes'),
                            'member_uuid'      => (string) $member['member_uuid'],
                            'member_label'     => (string) ($member['display_label'] ?? ''),
                            'calendar_subscriber_uuid' => (string) $member['calendar_subscriber_uuid'],
                            'bo_id'            => (int) ($member['bo_id'] ?? $window['bo_id'] ?? 0),
                            'resource_uuids'   => $requiredResources,
                            'duration_minutes' => $duration,
                        ];

                        $cursor += $granularity;
                    }
                }

                $day = $day->setTimezone($memberZone)->modify('+1 day');
                $day = Clock::startOfLocalDay($day, $memberZone);
            }
        }

        // Earliest first, and within a moment the higher-priority practitioner,
        // so "any available" means "the person who normally does this".
        usort($slots, static function (array $a, array $b): int {
            $byTime = $a['start'] <=> $b['start'];

            return $byTime !== 0 ? $byTime : strcmp($a['member_uuid'], $b['member_uuid']);
        });

        return $slots;
    }

    /** @param list<array<string, mixed>> $blocked */
    private function minuteRangeBlocked(int $startMinute, int $endMinute, array $blocked): bool
    {
        foreach ($blocked as $block) {
            if ($startMinute < (int) $block['ends_minute'] && $endMinute > (int) $block['starts_minute']) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $window */
    private function windowAppliesOn(array $window, string $date): bool
    {
        $fromDate = $window['effective_from'] ?? null;
        $toDate = $window['effective_to'] ?? null;

        if ($fromDate !== null && $date < substr((string) $fromDate, 0, 10)) {
            return false;
        }
        if ($toDate !== null && $date > substr((string) $toDate, 0, 10)) {
            return false;
        }

        return true;
    }

    // =======================================================================
    // Calendar
    // =======================================================================

    /**
     * Every busy interval that matters, in one call.
     *
     * @param list<array<string, mixed>> $members
     * @param array<string, mixed>       $service
     * @return array{available: bool, message: ?string, by_calendar: array<string, list<array{0: DateTimeImmutable, 1: DateTimeImmutable}>>, by_resource: array<string, list<array{0: DateTimeImmutable, 1: DateTimeImmutable}>>}
     */
    private function busyByCalendar(array $members, DateTimeImmutable $from, DateTimeImmutable $to, array $service): array
    {
        $client = $this->calendarClient();

        $memberCalendars = [];
        foreach ($members as $member) {
            $uuid = trim((string) $member['calendar_subscriber_uuid']);
            if ($uuid !== '') {
                $memberCalendars[$uuid] = true;
            }
        }

        $resourceCalendars = [];
        foreach ($this->resources($this->requiredResourceUuids((string) $service['service_uuid'])) as $resource) {
            $uuid = trim((string) ($resource['calendar_subscriber_uuid'] ?? ''));
            if ($uuid !== '') {
                $resourceCalendars[$uuid] = (string) $resource['resource_uuid'];
            }
        }

        $all = array_values(array_unique(array_merge(array_keys($memberCalendars), array_keys($resourceCalendars))));

        if ($all === []) {
            return ['available' => true, 'message' => null, 'by_calendar' => [], 'by_resource' => []];
        }

        if (!$client->configured()) {
            return [
                'available'   => false,
                'message'     => 'Calendar is not configured for this deployment, so availability cannot be shown.',
                'by_calendar' => [],
                'by_resource' => [],
            ];
        }

        $result = $client->forSubscriber($all[0])->freeBusy($all, Clock::iso($from), Clock::iso($to));

        if (!$result['ok']) {
            return [
                'available'   => false,
                'message'     => 'Calendar availability is temporarily unavailable.',
                'by_calendar' => [],
                'by_resource' => [],
            ];
        }

        $body = $result['body']['data'] ?? $result['body'] ?? [];
        $byCalendar = [];
        $byResource = [];
        $partial = false;

        foreach ((array) ($body['subscribers'] ?? []) as $entry) {
            $uuid = (string) ($entry['subscriber_uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            if (($entry['available'] ?? true) !== true) {
                // One calendar that could not be read. Treated as unavailable
                // rather than free — see the class docblock.
                $partial = true;
                continue;
            }

            $intervals = [];
            foreach ((array) ($entry['busy'] ?? []) as $block) {
                $start = Clock::parse((string) ($block['start_at'] ?? ''));
                $end = Clock::parse((string) ($block['end_at'] ?? ''));
                if ($start === null || $end === null || $end <= $start) {
                    continue;
                }
                $intervals[] = [$start, $end];
            }

            $byCalendar[$uuid] = $intervals;
            if (isset($resourceCalendars[$uuid])) {
                $byResource[$resourceCalendars[$uuid]] = $intervals;
            }
        }

        if ($partial) {
            return [
                'available'   => false,
                'message'     => 'One or more calendars could not be read, so availability cannot be shown reliably.',
                'by_calendar' => [],
                'by_resource' => [],
            ];
        }

        return ['available' => true, 'message' => null, 'by_calendar' => $byCalendar, 'by_resource' => $byResource];
    }

    // =======================================================================
    // This product's own claims
    // =======================================================================

    /**
     * Bookings without a calendar event yet, plus live holds.
     *
     * Both are real occupancy that Calendar cannot know about: a booking whose
     * event write failed, and a slot somebody is halfway through taking.
     *
     * @return array{by_member: array<string, list<array{0: DateTimeImmutable, 1: DateTimeImmutable}>>, by_resource: array<string, list<array{0: DateTimeImmutable, 1: DateTimeImmutable}>>}
     */
    private function internalBlocks(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $ignoreBookingUuid = null,
        ?string $ignoreHoldUuid = null,
    ): array {
        $byMember = [];
        $byResource = [];

        $bookings = Db::all(
            'SELECT booking_uuid, member_uuid, resource_uuid, starts_at, ends_at
               FROM ' . BookingService::TABLE . "
              WHERE cmp_id = :cmp
                AND status IN ('DRAFT', 'PENDING', 'CONFIRMED', 'ARRIVED', 'IN_PROGRESS')
                AND calendar_sync_state <> 'synced'
                AND starts_at < :to AND ends_at > :from",
            ['cmp' => $this->ctx->cmpId, 'from' => Clock::sql($from), 'to' => Clock::sql($to)],
        );

        foreach ($bookings as $row) {
            if ($ignoreBookingUuid !== null && (string) $row['booking_uuid'] === $ignoreBookingUuid) {
                continue;
            }
            $start = Clock::parse((string) $row['starts_at']);
            $end = Clock::parse((string) $row['ends_at']);
            if ($start === null || $end === null) {
                continue;
            }
            if (!empty($row['member_uuid'])) {
                $byMember[(string) $row['member_uuid']][] = [$start, $end];
            }
            if (!empty($row['resource_uuid'])) {
                $byResource[(string) $row['resource_uuid']][] = [$start, $end];
            }
        }

        $holds = Db::all(
            'SELECT hold_uuid, member_uuid, resource_uuid, starts_at, ends_at
               FROM ' . SlotHoldService::TABLE . '
              WHERE cmp_id = :cmp
                AND consumed_by_booking IS NULL
                AND released_at IS NULL
                AND expires_at > :now
                AND starts_at < :to AND ends_at > :from',
            [
                'cmp'  => $this->ctx->cmpId,
                'now'  => Clock::sql(Clock::now()),
                'from' => Clock::sql($from),
                'to'   => Clock::sql($to),
            ],
        );

        foreach ($holds as $row) {
            if ($ignoreHoldUuid !== null && (string) $row['hold_uuid'] === $ignoreHoldUuid) {
                continue;
            }
            $start = Clock::parse((string) $row['starts_at']);
            $end = Clock::parse((string) $row['ends_at']);
            if ($start === null || $end === null) {
                continue;
            }
            if (!empty($row['member_uuid'])) {
                $byMember[(string) $row['member_uuid']][] = [$start, $end];
            }
            if (!empty($row['resource_uuid'])) {
                $byResource[(string) $row['resource_uuid']][] = [$start, $end];
            }
        }

        return ['by_member' => $byMember, 'by_resource' => $byResource];
    }

    /** @param list<array{0: DateTimeImmutable, 1: DateTimeImmutable}> $blocks */
    private function overlapsAny(DateTimeImmutable $start, DateTimeImmutable $end, array $blocks): bool
    {
        foreach ($blocks as [$blockStart, $blockEnd]) {
            if ($start < $blockEnd && $end > $blockStart) {
                return true;
            }
        }

        return false;
    }

    // =======================================================================
    // Lookups
    // =======================================================================

    /** @return array<string, mixed> */
    public function service(string $serviceUuid): array
    {
        $row = Db::first(
            'SELECT * FROM appointment_services WHERE service_uuid = :id AND cmp_id = :cmp',
            ['id' => $serviceUuid, 'cmp' => $this->ctx->cmpId],
        );

        if ($row === null) {
            throw new \RuntimeException('Unknown service.');
        }

        $row['modes'] = Db::jsonColumn($row['modes'] ?? null);

        return $row;
    }

    /** @return array<string, mixed>|null */
    public function member(string $memberUuid): ?array
    {
        return Db::first(
            'SELECT * FROM appointment_team_members WHERE member_uuid = :id AND cmp_id = :cmp',
            ['id' => $memberUuid, 'cmp' => $this->ctx->cmpId],
        );
    }

    /**
     * Staff who may deliver this service, narrowed to one person or one branch
     * when the caller asked for that.
     *
     * @param array<string, mixed> $service
     * @return list<array<string, mixed>>
     */
    public function eligibleMembers(array $service, ?string $memberUuid = null, ?int $boId = null): array
    {
        $params = ['service' => $service['service_uuid'], 'cmp' => $this->ctx->cmpId];
        $sql = 'SELECT m.*, ss.priority, ss.duration_override_minutes
                  FROM appointment_service_staff ss
                  JOIN appointment_team_members m ON m.member_uuid = ss.member_uuid
                 WHERE ss.service_uuid = :service
                   AND ss.cmp_id = :cmp
                   AND m.is_active = TRUE
                   AND m.accepts_bookings = TRUE';

        if ($memberUuid !== null && $memberUuid !== '') {
            $sql .= ' AND m.member_uuid = :member';
            $params['member'] = $memberUuid;
        }

        $sql .= ' ORDER BY ss.priority DESC, m.created_at ASC';

        $members = Db::all($sql, $params);

        // A branch filter narrows by where the person actually works, which is
        // the availability pattern's bo_id — a member is not pinned to a branch
        // in their own row because a practitioner covering two clinics is
        // normal.
        if ($boId !== null && $boId > 0 && $members !== []) {
            $allowed = [];
            foreach (Db::all(
                'SELECT DISTINCT member_uuid FROM appointment_team_availability
                  WHERE cmp_id = :cmp AND (bo_id = :bo OR bo_id = 0)',
                ['cmp' => $this->ctx->cmpId, 'bo' => $boId],
            ) as $row) {
                $allowed[(string) $row['member_uuid']] = true;
            }
            $members = array_values(array_filter(
                $members,
                static fn (array $m) => isset($allowed[(string) $m['member_uuid']]),
            ));
        }

        return $members;
    }

    /**
     * Working-hours windows for several members at once.
     *
     * @param list<string> $memberUuids
     * @return array<string, list<array<string, mixed>>>
     */
    public function availabilityWindows(array $memberUuids): array
    {
        $memberUuids = array_values(array_filter(array_map('strval', $memberUuids)));
        if ($memberUuids === []) {
            return [];
        }

        // A generated IN list rather than = ANY(:array): PDO with emulation off
        // will not bind a PHP array to a PostgreSQL array without a cast dance,
        // and these values are uuids this product generated, not user input.
        $placeholders = [];
        $params = ['cmp' => $this->ctx->cmpId];
        foreach ($memberUuids as $index => $uuid) {
            $key = 'm' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $uuid;
        }

        $rows = Db::all(
            'SELECT * FROM appointment_team_availability
              WHERE cmp_id = :cmp AND member_uuid IN (' . implode(', ', $placeholders) . ')
              ORDER BY day_of_week, starts_minute',
            $params,
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['member_uuid']][] = $row;
        }

        return $out;
    }

    /** @return list<string> */
    public function requiredResourceUuids(string $serviceUuid): array
    {
        $rows = Db::all(
            'SELECT resource_uuid FROM appointment_service_resources
              WHERE service_uuid = :id AND cmp_id = :cmp AND is_required = TRUE',
            ['id' => $serviceUuid, 'cmp' => $this->ctx->cmpId],
        );

        return array_map(static fn (array $r) => (string) $r['resource_uuid'], $rows);
    }

    /**
     * @param list<string> $resourceUuids
     * @return list<array<string, mixed>>
     */
    public function resources(array $resourceUuids): array
    {
        $resourceUuids = array_values(array_filter(array_map('strval', $resourceUuids)));
        if ($resourceUuids === []) {
            return [];
        }

        $placeholders = [];
        $params = ['cmp' => $this->ctx->cmpId];
        foreach ($resourceUuids as $index => $uuid) {
            $key = 'r' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $uuid;
        }

        return Db::all(
            'SELECT * FROM appointment_resources
              WHERE cmp_id = :cmp AND resource_uuid IN (' . implode(', ', $placeholders) . ')',
            $params,
        );
    }

    private function calendarClient(): CalendarClient
    {
        return $this->calendar ?? new CalendarClient();
    }

    /** @return array{0: DateTimeImmutable, 1: DateTimeImmutable} */
    private function clampWindow(DateTimeImmutable $from, DateTimeImmutable $to, int $horizonDays): array
    {
        $now = Clock::now();

        if ($from < $now) {
            $from = $now;
        }

        $horizon = $now->modify('+' . max(1, $horizonDays) . ' days');
        if ($to > $horizon) {
            $to = $horizon;
        }

        $ceiling = $from->modify('+' . self::MAX_WINDOW_DAYS . ' days');
        if ($to > $ceiling) {
            $to = $ceiling;
        }

        return [$from, $to];
    }

    private function normaliseDaypart(?string $daypart): ?string
    {
        $daypart = strtolower(trim((string) $daypart));

        return in_array($daypart, ['morning', 'afternoon', 'evening'], true) ? $daypart : null;
    }
}
