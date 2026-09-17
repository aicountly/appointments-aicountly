<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Db;
use Aicountly\Api\Domain\AvailabilityService;
use Aicountly\Api\Domain\BookingService;
use Aicountly\Api\Domain\NoShowRiskService;
use Aicountly\Api\Domain\WaitlistService;
use Aicountly\Api\Support\Clock;

/**
 * DASHBOARD 1 — Appointment Command Center.
 *
 * "What is happening with appointments today?"
 *
 * This is the screen somebody opens at 8:50am. It is a day view: today's
 * schedule as a live timeline, the handful of things that need a decision, and
 * where the bookings are coming from. It is NOT a reporting screen — no trends,
 * no period comparisons beyond "vs yesterday", nothing that invites analysis.
 * Analysis is Dashboard 5 and the difference is the point.
 *
 * ## The timeline shows available slots too
 *
 * A day view that shows only what is booked hides the thing a receptionist can
 * act on. A 60-minute gap at 1pm with a Book button on it is the most useful
 * row on the screen, and it comes from the same availability engine the booking
 * flow uses — so it is a slot that can actually be taken, not a hole in a grid.
 */
final class OverviewDashboard extends Dashboard
{
    public function id(): string
    {
        return 'overview';
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $settings = $this->settings();
        $zone = Clock::zone($settings['timezone']);
        $now = Clock::now();

        $todayStart = Clock::startOfLocalDay($now, $zone);
        $todayEnd = $todayStart->modify('+1 day');
        $yesterdayStart = $todayStart->modify('-1 day');

        $today = $this->statusCounts(Clock::sql($todayStart), Clock::sql($todayEnd));
        $yesterday = $this->statusCounts(Clock::sql($yesterdayStart), Clock::sql($todayStart));

        // "Upcoming" is everything still to come, not just today: it is the
        // book of work, and a quiet afternoon with 126 appointments next week
        // is a different business from a quiet afternoon with none.
        $upcoming = (int) Db::scalar(
            'SELECT COUNT(*) FROM ' . BookingService::TABLE . "
              WHERE cmp_id = :cmp AND starts_at >= :now
                AND status IN ('PENDING', 'CONFIRMED')",
            ['cmp' => $this->ctx->cmpId, 'now' => Clock::sql($now)],
        );

        $waitlist = new WaitlistService($this->ctx);
        $waitingCount = (int) Db::scalar(
            'SELECT COUNT(*) FROM ' . WaitlistService::TABLE . "
              WHERE cmp_id = :cmp AND status = 'waiting'",
            ['cmp' => $this->ctx->cmpId],
        );

        $risk = (new NoShowRiskService($this->ctx))->assessWindow(Clock::sql($now), Clock::sql($todayEnd));
        $atRisk = array_values(array_filter($risk, static fn (array $r) => $r['at_risk']));

        $availability = $this->todaysAvailability($todayStart, $todayEnd);
        $utilisation = $this->utilisationToday($todayStart, $todayEnd);

        $metrics = [
            Metric::make(
                'today_appointments',
                "Today's appointments",
                $today['TOTAL'],
                'count',
                $yesterday['TOTAL'],
                'up_is_good',
                'vs yesterday',
                'appointments',
                ['date' => $todayStart->setTimezone($zone)->format('Y-m-d')],
            ),
            Metric::make('upcoming', 'Upcoming', $upcoming, 'count', null, 'up_is_good', 'still to come', 'appointments', ['upcoming' => '1']),
            Metric::make('confirmed', 'Confirmed', $today['CONFIRMED'], 'count', $yesterday['CONFIRMED'], 'up_is_good', 'vs yesterday', 'appointments', ['status' => 'CONFIRMED']),
            Metric::make('pending', 'Pending', $today['PENDING'], 'count', $yesterday['PENDING'], 'down_is_good', 'needs action', 'appointments', ['status' => 'PENDING']),
            Metric::make('waitlist', 'On waitlist', $waitingCount, 'count', null, 'neutral', 'across all services', 'waitlist', []),
            Metric::make('no_show_risk', 'No-show risk', count($atRisk), 'count', null, 'down_is_good', 'today', 'appointments', ['risk' => '1']),
            Metric::make(
                'available_slots',
                'Available slots',
                $availability['count'],
                'count',
                null,
                'neutral',
                $availability['calendar_available'] ? 'left today' : null,
                'find_slot',
                [],
            ),
            Metric::make('utilisation', 'Utilisation', $utilisation, 'percent', null, 'up_is_good', 'of booked hours today'),
        ];

        if (!$availability['calendar_available']) {
            // Replaced rather than zeroed: "0 slots left" and "we cannot see
            // the calendar" look identical on a card and mean opposite things.
            $metrics[6] = Metric::unavailable(
                'available_slots',
                'Available slots',
                $availability['calendar_message'] ?? 'Calendar availability is temporarily unavailable.',
            );
        }

        $peak = $this->peakHour($todayStart, $todayEnd, $zone);

        return $this->envelope(
            $metrics,
            [
                'schedule'  => $this->todaysSchedule($todayStart, $todayEnd, $zone, $availability),
                'attention' => $this->needsAttention($now, $todayEnd, $waitingCount, count($atRisk)),
                'sources'   => $this->bookingSources(Clock::sql($todayStart->modify('-30 days')), Clock::sql($todayEnd)),
                'calendar'  => [
                    'available' => $availability['calendar_available'],
                    'message'   => $availability['calendar_message'],
                ],
            ],
            [
                'today_total'      => $today['TOTAL'],
                'unconfirmed'      => $today['PENDING'],
                'available_slots'  => $availability['count'],
                'waitlist_waiting' => $waitingCount,
                'peak_hour'        => $peak,
                'waitlist_matches_tomorrow' => $this->waitlistMatchesTomorrow($waitlist, $todayEnd),
            ],
            $this->sources([
                'Appointment times and availability' => 'Aicountly Calendar',
                'Client names and contact details'   => 'Aicountly Contacts',
            ]),
        );
    }

    /**
     * The day as a vertical timeline: appointments and the gaps between them.
     *
     * @param array<string, mixed> $availability
     * @return array<string, mixed>
     */
    private function todaysSchedule(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeZone $zone,
        array $availability,
    ): array {
        [$scope, $params] = $this->ctx->scopeClause('b');

        $rows = Db::all(
            'SELECT b.booking_uuid, b.reference, b.starts_at, b.ends_at, b.status, b.mode,
                    b.client_name, b.contact_uuid, b.member_uuid, b.bo_id,
                    b.calendar_sync_state, b.connect_join_url,
                    s.name AS service_name, s.duration_minutes,
                    m.display_label AS member_label,
                    (SELECT COUNT(*) FROM appointment_booking_attendees a
                      WHERE a.booking_uuid = b.booking_uuid) AS attendee_count,
                    (SELECT COUNT(*) FROM appointment_booking_metadata md
                      WHERE md.booking_uuid = b.booking_uuid AND md.kind = \'form_answers\') AS has_form
               FROM ' . BookingService::TABLE . ' b
               JOIN appointment_services s ON s.service_uuid = b.service_uuid
          LEFT JOIN appointment_team_members m ON m.member_uuid = b.member_uuid
              WHERE ' . $scope . " AND b.starts_at >= :from AND b.starts_at < :to
                AND b.status <> 'CANCELLED'
              ORDER BY b.starts_at ASC",
            $params + ['from' => Clock::sql($from), 'to' => Clock::sql($to)],
        );

        $items = [];
        foreach ($rows as $row) {
            $startsAt = Clock::parse((string) $row['starts_at']);
            $items[] = [
                'kind'          => 'appointment',
                'booking_uuid'  => (string) $row['booking_uuid'],
                'reference'     => (string) $row['reference'],
                'starts_at'     => $startsAt !== null ? Clock::iso($startsAt) : null,
                'local_time'    => $startsAt !== null ? $startsAt->setTimezone($zone)->format('g:i A') : '',
                'duration_minutes' => (int) $row['duration_minutes'],
                'status'        => (string) $row['status'],
                'mode'          => (string) $row['mode'],
                'client_label'  => trim((string) $row['client_name']) ?: 'Client',
                'contact_uuid'  => $row['contact_uuid'],
                'service_name'  => (string) $row['service_name'],
                'member_label'  => trim((string) ($row['member_label'] ?? '')) ?: null,
                'attendee_count' => (int) $row['attendee_count'],
                'has_form'      => (int) $row['has_form'] > 0,
                'calendar_sync_state' => (string) $row['calendar_sync_state'],
                'join_url'      => $row['connect_join_url'],
            ];
        }

        // Open slots, interleaved by time, so the gap between the 11:30 and the
        // 2pm is visible where it actually is.
        foreach (array_slice($availability['slots'], 0, 12) as $slot) {
            $items[] = [
                'kind'          => 'available',
                'starts_at'     => $slot['starts_at'],
                'local_time'    => (new \DateTimeImmutable($slot['starts_at']))->setTimezone($zone)->format('g:i A'),
                'duration_minutes' => (int) $slot['duration_minutes'],
                'member_uuid'   => $slot['member_uuid'],
                'member_label'  => $slot['member_label'] ?: null,
                'status'        => 'AVAILABLE',
            ];
        }

        usort($items, static fn (array $a, array $b) => strcmp((string) $a['starts_at'], (string) $b['starts_at']));

        return [
            'items'              => $items,
            'appointment_count'  => count($rows),
            'calendar_available' => $availability['calendar_available'],
        ];
    }

    /**
     * The short list of things somebody should deal with.
     *
     * Every row is a count with a route behind it, so clicking goes to the
     * filtered list rather than to a screen where the reader has to find them
     * again.
     *
     * @return list<array<string, mixed>>
     */
    private function needsAttention(
        \DateTimeImmutable $now,
        \DateTimeImmutable $todayEnd,
        int $waitingCount,
        int $atRiskCount,
    ): array {
        $horizon = $now->modify('+7 days');

        $row = Db::first(
            "SELECT
                COUNT(*) FILTER (WHERE b.status = 'PENDING') AS unconfirmed,
                COUNT(*) FILTER (
                    WHERE s.form_uuid IS NOT NULL
                      AND NOT EXISTS (
                        SELECT 1 FROM appointment_booking_metadata m
                         WHERE m.booking_uuid = b.booking_uuid AND m.kind = 'form_answers'
                      )
                ) AS forms_incomplete,
                COUNT(*) FILTER (
                    WHERE s.deposit_required = TRUE
                      AND (b.payment_status IS NULL OR b.payment_status <> 'paid')
                ) AS deposits_pending,
                COUNT(*) FILTER (WHERE b.calendar_sync_state = 'failed') AS calendar_failures
             FROM " . BookingService::TABLE . ' b
             JOIN appointment_services s ON s.service_uuid = b.service_uuid
            WHERE b.cmp_id = :cmp
              AND b.starts_at >= :now AND b.starts_at < :horizon
              AND b.status IN (\'PENDING\', \'CONFIRMED\')',
            ['cmp' => $this->ctx->cmpId, 'now' => Clock::sql($now), 'horizon' => Clock::sql($horizon)],
        ) ?? [];

        $items = [];

        $add = function (string $key, int $count, string $title, string $detail, string $route, array $params, string $tone) use (&$items): void {
            if ($count > 0) {
                $items[] = [
                    'key'    => $key,
                    'count'  => $count,
                    'title'  => $title,
                    'detail' => $detail,
                    'tone'   => $tone,
                    'action' => ['route' => $route, 'params' => $params],
                ];
            }
        };

        $add(
            'unconfirmed',
            (int) ($row['unconfirmed'] ?? 0),
            'appointments unconfirmed',
            'Clients have not responded yet',
            'appointments',
            ['status' => 'PENDING'],
            'warning',
        );
        $add(
            'forms_incomplete',
            (int) ($row['forms_incomplete'] ?? 0),
            'client forms incomplete',
            'Intake forms still pending',
            'appointments',
            ['forms' => 'pending'],
            'neutral',
        );
        $add(
            'deposits_pending',
            (int) ($row['deposits_pending'] ?? 0),
            'payments pending',
            'Deposit required before confirmation',
            'appointments',
            ['payment' => 'pending'],
            'warning',
        );
        $add(
            'no_show_risk',
            $atRiskCount,
            'appointments at no-show risk',
            'Consider reaching out',
            'appointments',
            ['risk' => '1'],
            'danger',
        );
        $add(
            'waitlist',
            $waitingCount,
            'people on waitlist',
            'Waiting for a slot to come free',
            'waitlist',
            [],
            'info',
        );
        $add(
            'calendar_failures',
            (int) ($row['calendar_failures'] ?? 0),
            'bookings without a calendar entry',
            'The calendar write failed and needs retrying',
            'appointments',
            ['calendar' => 'failed'],
            'danger',
        );

        return $items;
    }

    /**
     * Today's remaining open slots, from the real availability engine.
     *
     * Across every active bookable service, capped: this is for a count and a
     * handful of timeline rows, not a booking page.
     *
     * @return array{count: int, slots: list<array<string, mixed>>, calendar_available: bool, calendar_message: ?string}
     */
    private function todaysAvailability(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $services = Db::all(
            'SELECT service_uuid FROM appointment_services
              WHERE cmp_id = :cmp AND is_active = TRUE
              ORDER BY sort_order, created_at
              LIMIT 6',
            ['cmp' => $this->ctx->cmpId],
        );

        if ($services === []) {
            return ['count' => 0, 'slots' => [], 'calendar_available' => true, 'calendar_message' => null];
        }

        $availability = new AvailabilityService($this->ctx);
        $now = Clock::now();
        $searchFrom = $now > $from ? $now : $from;

        $slots = [];
        $calendarAvailable = true;
        $message = null;
        $seen = [];

        foreach ($services as $service) {
            try {
                $result = $availability->findSlots([
                    'service_uuid' => (string) $service['service_uuid'],
                    'from'         => $searchFrom,
                    'to'           => $to,
                    'bo_id'        => $this->ctx->boId > 0 ? $this->ctx->boId : null,
                    'limit'        => 40,
                ]);
            } catch (\Throwable $e) {
                error_log('[overview] availability failed: ' . $e->getMessage());
                continue;
            }

            if (!$result['calendar_available']) {
                $calendarAvailable = false;
                $message = $result['calendar_message'];
                break;
            }

            foreach ($result['slots'] as $slot) {
                // One slot per time per practitioner, however many services
                // could fill it — otherwise a clinic offering six services
                // reports six times its real capacity.
                $key = $slot['member_uuid'] . '|' . $slot['starts_at'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $slots[] = $slot;
            }
        }

        if (!$calendarAvailable) {
            return ['count' => 0, 'slots' => [], 'calendar_available' => false, 'calendar_message' => $message];
        }

        usort($slots, static fn (array $a, array $b) => strcmp($a['starts_at'], $b['starts_at']));

        return [
            'count'              => count($slots),
            'slots'              => $slots,
            'calendar_available' => true,
            'calendar_message'   => null,
        ];
    }

    /**
     * Booked minutes as a share of the working day.
     *
     * From this product's own bookings and its own working-hours pattern.
     * Calendar's busy time is deliberately NOT in the denominator: a
     * practitioner's dentist appointment reduces their availability, it does
     * not mean the practice was 100% utilised.
     */
    private function utilisationToday(\DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        $bookedMinutes = (int) (Db::scalar(
            'SELECT COALESCE(SUM(EXTRACT(EPOCH FROM (ends_at - starts_at)) / 60), 0)
               FROM ' . BookingService::TABLE . "
              WHERE cmp_id = :cmp AND starts_at >= :from AND starts_at < :to
                AND status IN ('PENDING', 'CONFIRMED', 'ARRIVED', 'IN_PROGRESS', 'COMPLETED')",
            ['cmp' => $this->ctx->cmpId, 'from' => Clock::sql($from), 'to' => Clock::sql($to)],
        ) ?? 0);

        $settings = $this->settings();
        $zone = Clock::zone($settings['timezone']);
        $dow = Clock::localDayOfWeek($from, $zone);

        $availableMinutes = (int) (Db::scalar(
            "SELECT COALESCE(SUM(
                     CASE WHEN kind = 'available' THEN ends_minute - starts_minute ELSE 0 END
                   ), 0)
               FROM appointment_team_availability
              WHERE cmp_id = :cmp AND day_of_week = :dow
                AND (effective_from IS NULL OR effective_from <= :date)
                AND (effective_to IS NULL OR effective_to >= :date)",
            [
                'cmp'  => $this->ctx->cmpId,
                'dow'  => $dow,
                'date' => $from->setTimezone($zone)->format('Y-m-d'),
            ],
        ) ?? 0);

        if ($availableMinutes <= 0) {
            return 0.0;
        }

        return round(min(100, $bookedMinutes / $availableMinutes * 100), 1);
    }

    /**
     * The busiest hour of the day, for the insight rule.
     *
     * @return array{label: string, bookings: int, hour: int}|null
     */
    private function peakHour(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeZone $zone): ?array
    {
        $rows = Db::all(
            'SELECT EXTRACT(HOUR FROM starts_at AT TIME ZONE :tz)::int AS hour, COUNT(*) AS total
               FROM ' . BookingService::TABLE . "
              WHERE cmp_id = :cmp AND starts_at >= :from AND starts_at < :to
                AND status <> 'CANCELLED'
              GROUP BY 1
              ORDER BY total DESC
              LIMIT 1",
            [
                'tz'   => $zone->getName(),
                'cmp'  => $this->ctx->cmpId,
                'from' => Clock::sql($from->modify('-7 days')),
                'to'   => Clock::sql($to),
            ],
        );

        if ($rows === []) {
            return null;
        }

        $hour = (int) $rows[0]['hour'];
        $end = ($hour + 2) % 24;

        return [
            'hour'     => $hour,
            'bookings' => (int) $rows[0]['total'],
            'label'    => sprintf('%d:00 – %d:00', $hour, $end),
        ];
    }

    private function waitlistMatchesTomorrow(WaitlistService $waitlist, \DateTimeImmutable $todayEnd): int
    {
        $services = Db::all(
            "SELECT DISTINCT service_uuid FROM " . WaitlistService::TABLE . "
              WHERE cmp_id = :cmp AND status = 'waiting' LIMIT 5",
            ['cmp' => $this->ctx->cmpId],
        );

        $total = 0;
        foreach ($services as $service) {
            $total += count($waitlist->matchesForSlot(
                (string) $service['service_uuid'],
                null,
                $this->ctx->boId,
                $todayEnd->modify('+10 hours'),
                5,
            ));
        }

        return $total;
    }
}
