<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Db;
use Aicountly\Api\Domain\BookingService;
use Aicountly\Api\Domain\ScheduleHealthService;
use Aicountly\Api\Domain\SlotHoldService;
use Aicountly\Api\Domain\WaitlistService;
use Aicountly\Api\Support\Clock;

/**
 * DASHBOARD 2 — Live Operations.
 *
 * "What needs attention right now?"
 *
 * The front-desk screen, open all day, refreshing itself. Where Overview asks
 * about the day, this asks about the minute: who is in the building, who is
 * late, which room is free, who is waiting. Every row has buttons on it.
 *
 * ## What makes it different from Overview
 *
 * It is the same appointments and a completely different question. Overview
 * shows the 9am as "Completed" and moves on; this one shows that the 11:45 is
 * sixteen minutes late, that Dr Sharma has not started it, and that four people
 * are in the waiting area. Nothing here is a trend and nothing is a total for
 * the month.
 *
 * ## The schedule health score
 *
 * Explainable by construction — see ScheduleHealthService. It starts at 100 and
 * subtracts named penalties, and the breakdown comes back with it so the UI can
 * show what cost what. There is no model involved.
 */
final class LiveOperationsDashboard extends Dashboard
{
    /** Late enough to matter to the person waiting. */
    private const DELAY_THRESHOLD_MINUTES = 10;

    public function id(): string
    {
        return 'live';
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $settings = $this->settings();
        $zone = Clock::zone($settings['timezone']);
        $now = Clock::now();
        $dayStart = Clock::startOfLocalDay($now, $zone);
        $dayEnd = $dayStart->modify('+1 day');

        $flow = $this->statusCounts(Clock::sql($dayStart), Clock::sql($dayEnd));
        $live = $this->liveCounts($now, $dayEnd);
        $health = (new ScheduleHealthService($this->ctx))->today();
        $team = $this->teamStatus($now, $zone);

        $teamAvailable = count(array_filter($team, static fn (array $m) => $m['state'] === 'available'));

        $metrics = [
            Metric::make('currently_active', 'Currently active', $flow['IN_PROGRESS'], 'count', null, 'neutral', 'in appointments now', 'appointments', ['status' => 'IN_PROGRESS']),
            Metric::make('arriving_next_hour', 'Arriving next hour', $live['next_hour'], 'count', null, 'neutral', 'confirmed bookings'),
            Metric::make('schedule_health', 'Schedule health', $health['score'], 'score', null, 'up_is_good', 'out of 100'),
            Metric::make('team_available', 'Team available', $teamAvailable, 'count', null, 'neutral', 'of ' . count($team) . ' on shift', 'team', []),
            Metric::make('delayed', 'Delayed', $live['delayed'], 'count', null, 'down_is_good', 'more than ' . self::DELAY_THRESHOLD_MINUTES . ' minutes late', 'appointments', ['delayed' => '1']),
            Metric::make('waiting', 'Waiting', $flow['ARRIVED'], 'count', null, 'down_is_good', 'in the waiting area', 'appointments', ['status' => 'ARRIVED']),
        ];

        $quickFill = $this->waitlistQuickFill($now, $dayEnd, $zone);

        return $this->envelope(
            $metrics,
            [
                'flow' => [
                    'stages' => [
                        ['key' => 'booked',      'label' => 'Booked',      'count' => $flow['TOTAL']],
                        ['key' => 'confirmed',   'label' => 'Confirmed',   'count' => $flow['CONFIRMED']],
                        ['key' => 'arrived',     'label' => 'Arrived',     'count' => $flow['ARRIVED']],
                        ['key' => 'in_progress', 'label' => 'In progress', 'count' => $flow['IN_PROGRESS']],
                        ['key' => 'completed',   'label' => 'Completed',   'count' => $flow['COMPLETED']],
                        ['key' => 'cancelled',   'label' => 'Cancelled',   'count' => $flow['CANCELLED'], 'tone' => 'danger'],
                        ['key' => 'no_show',     'label' => 'No-show',     'count' => $flow['NO_SHOW'],   'tone' => 'danger'],
                        ['key' => 'rescheduled', 'label' => 'Rescheduled', 'count' => $flow['RESCHEDULED']],
                    ],
                    'being_booked' => (new SlotHoldService($this->ctx))->liveCount(),
                ],
                'health'    => $health,
                'team'      => $team,
                'resources' => $this->resourceStatus($now),
                'quick_fill' => $quickFill,
                'appointments' => $this->liveAppointments($dayStart, $dayEnd, $zone, $now),
                // The UI polls; it does not hold a socket open. Told here so
                // the cadence is a server decision rather than a magic number
                // in a component.
                'refresh_seconds' => 30,
            ],
            [
                'delayed'           => $live['delayed'],
                'waiting'           => $flow['ARRIVED'],
                'calendar_failures' => $live['calendar_failures'],
            ],
            $this->sources([
                'Appointment times'                => 'Aicountly Calendar',
                'Client names and contact details' => 'Aicountly Contacts',
            ]),
        );
    }

    /** @return array{next_hour: int, delayed: int, calendar_failures: int} */
    private function liveCounts(\DateTimeImmutable $now, \DateTimeImmutable $dayEnd): array
    {
        $row = Db::first(
            "SELECT
                COUNT(*) FILTER (
                    WHERE status IN ('PENDING', 'CONFIRMED')
                      AND starts_at >= :now AND starts_at < :hour
                ) AS next_hour,
                -- Due to have started and nobody has started it.
                COUNT(*) FILTER (
                    WHERE status IN ('CONFIRMED', 'ARRIVED')
                      AND started_at IS NULL
                      AND starts_at + (INTERVAL '1 minute' * :threshold) < :now
                ) AS delayed,
                COUNT(*) FILTER (WHERE calendar_sync_state = 'failed') AS calendar_failures
             FROM " . BookingService::TABLE . '
            WHERE cmp_id = :cmp AND starts_at >= :day_start AND starts_at < :day_end',
            [
                'now'       => Clock::sql($now),
                'hour'      => Clock::sql($now->modify('+1 hour')),
                'threshold' => self::DELAY_THRESHOLD_MINUTES,
                'cmp'       => $this->ctx->cmpId,
                'day_start' => Clock::sql($dayEnd->modify('-1 day')),
                'day_end'   => Clock::sql($dayEnd),
            ],
        ) ?? [];

        return [
            'next_hour'         => (int) ($row['next_hour'] ?? 0),
            'delayed'           => (int) ($row['delayed'] ?? 0),
            'calendar_failures' => (int) ($row['calendar_failures'] ?? 0),
        ];
    }

    /**
     * Where each team member is right now.
     *
     * Derived from their own appointments — in progress, next up, the gap
     * between — rather than from a status somebody has to remember to set. A
     * "currently on break" toggle is a toggle nobody touches after the first
     * week.
     *
     * @return list<array<string, mixed>>
     */
    private function teamStatus(\DateTimeImmutable $now, \DateTimeZone $zone): array
    {
        $members = $this->teamMembers();
        if ($members === []) {
            return [];
        }

        $rows = Db::all(
            "SELECT member_uuid, booking_uuid, starts_at, ends_at, started_at, status
               FROM " . BookingService::TABLE . "
              WHERE cmp_id = :cmp
                AND member_uuid IS NOT NULL
                AND starts_at >= :from AND starts_at < :to
                AND status IN ('PENDING', 'CONFIRMED', 'ARRIVED', 'IN_PROGRESS')
              ORDER BY member_uuid, starts_at",
            [
                'cmp'  => $this->ctx->cmpId,
                'from' => Clock::sql(Clock::startOfLocalDay($now, $zone)),
                'to'   => Clock::sql(Clock::startOfLocalDay($now, $zone)->modify('+1 day')),
            ],
        );

        $byMember = [];
        foreach ($rows as $row) {
            $byMember[(string) $row['member_uuid']][] = $row;
        }

        $out = [];
        foreach ($members as $member) {
            $memberUuid = (string) $member['member_uuid'];
            $bookings = $byMember[$memberUuid] ?? [];

            $state = 'available';
            $detail = 'No more appointments today';
            $until = null;
            $lateBy = null;

            $current = null;
            $next = null;

            foreach ($bookings as $booking) {
                $starts = Clock::parse((string) $booking['starts_at']);
                $ends = Clock::parse((string) $booking['ends_at']);
                if ($starts === null || $ends === null) {
                    continue;
                }
                if ($booking['status'] === 'IN_PROGRESS' || ($starts <= $now && $ends > $now)) {
                    $current = ['booking' => $booking, 'starts' => $starts, 'ends' => $ends];
                    break;
                }
                if ($starts > $now && $next === null) {
                    $next = ['booking' => $booking, 'starts' => $starts, 'ends' => $ends];
                }
            }

            if ($current !== null) {
                $state = 'in_appointment';
                $detail = $current['starts']->setTimezone($zone)->format('g:i A')
                    . ' – ' . $current['ends']->setTimezone($zone)->format('g:i A');
                $until = Clock::iso($current['ends']);

                if ($current['booking']['started_at'] === null) {
                    $overdue = Clock::minutesBetween($current['starts'], $now);
                    if ($overdue >= self::DELAY_THRESHOLD_MINUTES) {
                        $state = 'running_late';
                        $lateBy = $overdue;
                        $detail = 'Should have started ' . $overdue . ' minutes ago';
                    }
                }
            } elseif ($next !== null) {
                $minutes = Clock::minutesBetween($now, $next['starts']);
                // A long gap in the middle of the day is a break, not
                // availability somebody is about to fill.
                $state = $minutes > 60 ? 'break' : 'available';
                $detail = 'Next at ' . $next['starts']->setTimezone($zone)->format('g:i A');
                $until = Clock::iso($next['starts']);
            }

            $out[] = [
                'member_uuid' => $memberUuid,
                'label'       => (string) ($member['label'] ?? $member['display_label'] ?? 'Team member'),
                'job_title'   => $member['job_title'],
                'state'       => $state,
                'detail'      => $detail,
                'until'       => $until,
                'late_by_minutes' => $lateBy,
                'appointments_today' => count($bookings),
            ];
        }

        return $out;
    }

    /**
     * Rooms, cabins and chairs: occupied, free, or being turned around.
     *
     * `turnaround_minutes` is why a room shows as reserved after an
     * appointment ends — a treatment room that needs ten minutes of cleaning is
     * not available at the moment the previous client walks out, and showing it
     * as free is how a receptionist books a client into a room somebody is
     * still mopping.
     *
     * @return list<array<string, mixed>>
     */
    private function resourceStatus(\DateTimeImmutable $now): array
    {
        $resources = Db::all(
            'SELECT resource_uuid, name, kind, turnaround_minutes
               FROM appointment_resources
              WHERE cmp_id = :cmp AND is_active = TRUE
                AND (:bo = 0 OR bo_id = :bo OR bo_id = 0)
              ORDER BY kind, name',
            ['cmp' => $this->ctx->cmpId, 'bo' => $this->ctx->boId],
        );

        if ($resources === []) {
            return [];
        }

        $rows = Db::all(
            "SELECT b.resource_uuid, b.booking_uuid, b.starts_at, b.ends_at, b.client_name, b.status,
                    r.turnaround_minutes
               FROM " . BookingService::TABLE . ' b
               JOIN appointment_resources r ON r.resource_uuid = b.resource_uuid
              WHERE b.cmp_id = :cmp
                AND b.resource_uuid IS NOT NULL
                AND b.status IN (\'CONFIRMED\', \'ARRIVED\', \'IN_PROGRESS\')
                AND b.starts_at <= :now
                AND b.ends_at + (INTERVAL \'1 minute\' * r.turnaround_minutes) > :now',
            ['cmp' => $this->ctx->cmpId, 'now' => Clock::sql($now)],
        );

        $occupied = [];
        foreach ($rows as $row) {
            $occupied[(string) $row['resource_uuid']] = $row;
        }

        $out = [];
        foreach ($resources as $resource) {
            $resourceUuid = (string) $resource['resource_uuid'];
            $busy = $occupied[$resourceUuid] ?? null;

            if ($busy === null) {
                $out[] = [
                    'resource_uuid' => $resourceUuid,
                    'name'          => (string) $resource['name'],
                    'kind'          => (string) $resource['kind'],
                    'state'         => 'available',
                    'detail'        => 'Ready for next booking',
                    'until'         => null,
                ];
                continue;
            }

            $ends = Clock::parse((string) $busy['ends_at']);
            $inTurnaround = $ends !== null && $ends <= $now;

            $out[] = [
                'resource_uuid' => $resourceUuid,
                'name'          => (string) $resource['name'],
                'kind'          => (string) $resource['kind'],
                'state'         => $inTurnaround ? 'turnaround' : 'occupied',
                'detail'        => $inTurnaround
                    ? 'Being reset · ' . (int) $resource['turnaround_minutes'] . ' min'
                    : (trim((string) $busy['client_name']) ?: 'In use'),
                'until'         => $ends !== null
                    ? Clock::iso($ends->modify('+' . (int) $resource['turnaround_minutes'] . ' minutes'))
                    : null,
                'booking_uuid'  => (string) $busy['booking_uuid'],
            ];
        }

        return $out;
    }

    /**
     * A slot that just came free, and who would take it.
     *
     * Looks for the next stretch today where a practitioner has nothing booked,
     * and asks the waitlist who matches. The match reasons come with it — see
     * WaitlistService — so the receptionist can see WHY before they ring
     * somebody.
     *
     * @return array<string, mixed>|null
     */
    private function waitlistQuickFill(\DateTimeImmutable $now, \DateTimeImmutable $dayEnd, \DateTimeZone $zone): ?array
    {
        if (!\Aicountly\Api\Domain\Settings::featureEnabled($this->ctx, 'WAITLIST')) {
            return [
                'available'  => false,
                'reason'     => 'The waitlist is turned off for this company.',
                'slot'       => null,
                'matches'    => [],
            ];
        }

        // The most recent cancellation today is the slot most likely to still
        // be fillable, and the one a receptionist is already thinking about.
        $released = Db::first(
            "SELECT b.service_uuid, b.member_uuid, b.bo_id, b.starts_at, b.ends_at,
                    s.name AS service_name, s.duration_minutes
               FROM " . BookingService::TABLE . " b
               JOIN appointment_services s ON s.service_uuid = b.service_uuid
              WHERE b.cmp_id = :cmp
                AND b.status IN ('CANCELLED', 'RESCHEDULED')
                AND b.starts_at > :now AND b.starts_at < :end
              ORDER BY b.updated_at DESC
              LIMIT 1",
            ['cmp' => $this->ctx->cmpId, 'now' => Clock::sql($now), 'end' => Clock::sql($dayEnd)],
        );

        if ($released === null) {
            return ['available' => false, 'reason' => 'No slots have come free today.', 'slot' => null, 'matches' => []];
        }

        $startsAt = Clock::parse((string) $released['starts_at']);
        if ($startsAt === null) {
            return ['available' => false, 'reason' => 'No slots have come free today.', 'slot' => null, 'matches' => []];
        }

        $matches = (new WaitlistService($this->ctx))->matchesForSlot(
            (string) $released['service_uuid'],
            $released['member_uuid'] !== null ? (string) $released['member_uuid'] : null,
            (int) $released['bo_id'],
            $startsAt,
            3,
        );

        return [
            'available' => true,
            'reason'    => null,
            'slot'      => [
                'starts_at'        => Clock::iso($startsAt),
                'local_time'       => $startsAt->setTimezone($zone)->format('g:i A'),
                'duration_minutes' => (int) $released['duration_minutes'],
                'service_uuid'     => (string) $released['service_uuid'],
                'service_name'     => (string) $released['service_name'],
                'member_uuid'      => $released['member_uuid'],
            ],
            'matches'   => $matches,
        ];
    }

    /**
     * The live table: every appointment today with its quick actions.
     *
     * The actions are named, not spelled out as URLs — the frontend resolves a
     * name against its own route table, so nothing arriving from an API can
     * point a browser somewhere this product did not choose.
     *
     * @return list<array<string, mixed>>
     */
    private function liveAppointments(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeZone $zone,
        \DateTimeImmutable $now,
    ): array {
        [$scope, $params] = $this->ctx->scopeClause('b');

        $rows = Db::all(
            'SELECT b.booking_uuid, b.reference, b.starts_at, b.ends_at, b.started_at, b.arrived_at,
                    b.status, b.mode, b.client_name, b.client_phone, b.contact_uuid, b.bo_id,
                    b.calendar_sync_state, b.connect_join_url,
                    s.name AS service_name,
                    m.display_label AS member_label,
                    r.name AS resource_name
               FROM ' . BookingService::TABLE . ' b
               JOIN appointment_services s ON s.service_uuid = b.service_uuid
          LEFT JOIN appointment_team_members m ON m.member_uuid = b.member_uuid
          LEFT JOIN appointment_resources r ON r.resource_uuid = b.resource_uuid
              WHERE ' . $scope . ' AND b.starts_at >= :from AND b.starts_at < :to
              ORDER BY b.starts_at ASC',
            $params + ['from' => Clock::sql($from), 'to' => Clock::sql($to)],
        );

        $out = [];
        foreach ($rows as $row) {
            $startsAt = Clock::parse((string) $row['starts_at']);
            $status = (string) $row['status'];

            $lateBy = null;
            if (
                $startsAt !== null
                && $row['started_at'] === null
                && in_array($status, ['CONFIRMED', 'ARRIVED'], true)
                && $startsAt < $now
            ) {
                $minutes = Clock::minutesBetween($startsAt, $now);
                if ($minutes >= self::DELAY_THRESHOLD_MINUTES) {
                    $lateBy = $minutes;
                }
            }

            $out[] = [
                'booking_uuid' => (string) $row['booking_uuid'],
                'reference'    => (string) $row['reference'],
                'starts_at'    => $startsAt !== null ? Clock::iso($startsAt) : null,
                'local_time'   => $startsAt !== null ? $startsAt->setTimezone($zone)->format('g:i A') : '',
                'status'       => $status,
                'mode'         => (string) $row['mode'],
                'client_label' => trim((string) $row['client_name']) ?: 'Client',
                'client_phone' => (string) $row['client_phone'],
                'contact_uuid' => $row['contact_uuid'],
                'service_name' => (string) $row['service_name'],
                'member_label' => trim((string) ($row['member_label'] ?? '')) ?: null,
                'resource_name' => $row['resource_name'],
                'late_by_minutes' => $lateBy,
                'calendar_sync_state' => (string) $row['calendar_sync_state'],
                'join_url'     => $row['connect_join_url'],
                'actions'      => $this->actionsFor($status, $lateBy !== null),
            ];
        }

        return $out;
    }

    /**
     * Which buttons this row gets.
     *
     * Derived from the legal transitions in BookingService, so a receptionist
     * is never shown a Start button on a cancelled appointment and the backend
     * never has to refuse one.
     *
     * @return list<array{key: string, label: string, primary?: bool}>
     */
    private function actionsFor(string $status, bool $late): array
    {
        $actions = [];

        $can = static fn (string $target): bool => in_array($target, BookingService::TRANSITIONS[$status] ?? [], true);

        if ($can('IN_PROGRESS')) {
            $actions[] = ['key' => 'start', 'label' => 'Start', 'primary' => !$late];
        }
        if ($can('ARRIVED')) {
            $actions[] = ['key' => 'arrive', 'label' => 'Mark arrived', 'primary' => $late];
        }
        if ($can('COMPLETED')) {
            $actions[] = ['key' => 'complete', 'label' => 'Complete'];
        }
        if (in_array($status, ['PENDING', 'CONFIRMED'], true)) {
            $actions[] = ['key' => 'remind', 'label' => 'Send reminder'];
            $actions[] = ['key' => 'call', 'label' => 'Call'];
        }
        if ($can('RESCHEDULED')) {
            $actions[] = ['key' => 'reschedule', 'label' => 'Reschedule'];
        }
        if ($can('NO_SHOW')) {
            $actions[] = ['key' => 'no_show', 'label' => 'No-show'];
        }
        if ($can('CANCELLED')) {
            $actions[] = ['key' => 'cancel', 'label' => 'Cancel'];
        }

        return $actions;
    }
}
