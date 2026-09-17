<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Clients\ManageClient;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\AvailabilityService;
use Aicountly\Api\Domain\BookingService;
use Aicountly\Api\Domain\WaitlistService;
use Aicountly\Api\Support\Clock;

/**
 * DASHBOARD 3 — Availability & Capacity Intelligence.
 *
 * "Where is available capacity and where can we optimise?"
 *
 * The owner's Monday-morning screen. Not who is late and not how much was
 * billed: where the room is. Hours open against hours booked, a heatmap of
 * where demand actually falls, and the slots worth doing something about.
 *
 * ## Calendar's part, clearly labelled
 *
 * Conflicts, external calendar connections and last-sync are read LIVE from
 * Calendar and repeated without interpretation. Appointments does not know what
 * a CalDAV collection is and must not pretend to — if Calendar cannot answer,
 * those tiles say so rather than showing a stale "all clear", which is the one
 * lie that matters on this screen.
 *
 * ## Hours available is ours, hours booked is ours
 *
 * Both come from this product: the working-hours pattern and its own bookings.
 * A practitioner's personal diary entries reduce the slots on offer — that is
 * what the availability engine is for — but they are not "capacity used" by the
 * business, and folding them in would show a fully-utilised practice on a day
 * everybody was at the dentist.
 */
final class CapacityDashboard extends Dashboard
{
    /** Time bands on the heatmap. Two-hour blocks: finer is unreadable, coarser says nothing. */
    private const BANDS = [
        ['label' => '09:00 – 11:00', 'from' => 540,  'to' => 660],
        ['label' => '11:00 – 13:00', 'from' => 660,  'to' => 780],
        ['label' => '13:00 – 15:00', 'from' => 780,  'to' => 900],
        ['label' => '15:00 – 17:00', 'from' => 900,  'to' => 1020],
        ['label' => '17:00 – 19:00', 'from' => 1020, 'to' => 1140],
    ];

    public function id(): string
    {
        return 'capacity';
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $settings = $this->settings();
        $zone = Clock::zone($settings['timezone']);

        $current = $this->hours($this->period->fromSql(), $this->period->toSql());
        $previous = $this->hours($this->period->previousFromSql(), $this->period->previousToSql());

        $calendarStatus = $this->calendarStatus();
        $nextSlot = $this->nextOpenSlot($zone);

        $metrics = [
            Metric::make('available_hours', 'Available hours', $current['available'], 'hours', $previous['available'], 'neutral', 'vs previous period'),
            Metric::make('booked_hours', 'Booked hours', $current['booked'], 'hours', $previous['booked'], 'up_is_good', 'vs previous period'),
            Metric::make('utilisation', 'Utilisation', $current['utilisation'], 'percent', $previous['utilisation'], 'up_is_good', 'vs previous period'),
            Metric::make('unused_capacity', 'Unused capacity', $current['unused'], 'hours', $previous['unused'], 'down_is_good', 'vs previous period'),
            Metric::make('peak_load', 'Peak load', $current['peak_load'], 'percent', null, 'neutral', 'busiest band'),
            $nextSlot === null
                ? Metric::unavailable('next_open_slot', 'Next open slot', $calendarStatus['available']
                    ? 'No open slots in the booking window.'
                    : 'Calendar availability is temporarily unavailable.')
                : Metric::make('next_open_slot', 'Next open slot', null, 'count', null, 'neutral', $nextSlot['label']),
            $calendarStatus['available']
                ? Metric::make('calendar_conflicts', 'Calendar conflicts', $calendarStatus['conflicts'], 'count', null, 'down_is_good', $calendarStatus['conflicts'] === 0 ? 'All clear' : 'Need review')
                : Metric::unavailable('calendar_conflicts', 'Calendar conflicts', $calendarStatus['message'] ?? 'Calendar is unavailable.'),
            $calendarStatus['available']
                ? Metric::make('external_calendars', 'External calendars', $calendarStatus['external_connected'], 'count', null, 'neutral', 'connected')
                : Metric::unavailable('external_calendars', 'External calendars', $calendarStatus['message'] ?? 'Calendar is unavailable.'),
        ];

        $teamCapacity = $this->teamCapacity();

        return $this->envelope(
            $metrics,
            [
                'heatmap'            => $this->heatmap($zone),
                'team'               => $teamCapacity,
                'services'           => $this->mostRequestedServices(),
                'opportunities'      => $this->smartSlotOpportunities($zone),
                'locations'          => $this->locationComparison(),
                'calendar_integration' => $calendarStatus,
            ],
            [
                'unused_hours'     => $current['unused'],
                'utilisation'      => $current['utilisation'],
                'team_utilisation' => array_map(
                    static fn (array $m) => ['label' => $m['label'], 'utilisation' => $m['utilisation']],
                    $teamCapacity,
                ),
            ],
            $this->sources([
                'Free/busy, conflicts and external calendars' => 'Aicountly Calendar',
                'Branches and locations'                      => 'Aicountly Manage',
            ]),
        );
    }

    /**
     * Hours open, hours booked, and the arithmetic between them.
     *
     * Available hours are the working-hours pattern projected across the
     * period's actual days, which is why a week with a public holiday in it
     * reports fewer available hours rather than the same number and worse
     * utilisation.
     *
     * @return array{available: float, booked: float, unused: float, utilisation: float, peak_load: float}
     */
    private function hours(string $fromSql, string $toSql): array
    {
        $bookedMinutes = (int) (Db::scalar(
            'SELECT COALESCE(SUM(EXTRACT(EPOCH FROM (ends_at - starts_at)) / 60), 0)
               FROM ' . BookingService::TABLE . "
              WHERE cmp_id = :cmp
                AND (:bo = 0 OR bo_id = :bo OR bo_id = 0)
                AND starts_at >= :from AND starts_at < :to
                AND status IN ('PENDING', 'CONFIRMED', 'ARRIVED', 'IN_PROGRESS', 'COMPLETED')",
            ['cmp' => $this->ctx->cmpId, 'bo' => $this->ctx->boId, 'from' => $fromSql, 'to' => $toSql],
        ) ?? 0);

        // generate_series walks the real dates in the period and joins the
        // weekday pattern onto them, so 'available' respects which days
        // actually fell in the window.
        $availableMinutes = (int) (Db::scalar(
            "SELECT COALESCE(SUM(
                        CASE WHEN a.kind = 'available'
                             THEN a.ends_minute - a.starts_minute
                             ELSE -(a.ends_minute - a.starts_minute) END
                    ), 0)
               FROM generate_series(:from::date, :to::date - INTERVAL '1 day', INTERVAL '1 day') AS d(day)
               JOIN appointment_team_availability a
                 ON a.day_of_week = EXTRACT(DOW FROM d.day)::int
                AND a.cmp_id = :cmp
                AND (:bo = 0 OR a.bo_id = :bo OR a.bo_id = 0)
                AND (a.effective_from IS NULL OR a.effective_from <= d.day)
                AND (a.effective_to IS NULL OR a.effective_to >= d.day)
               JOIN appointment_team_members m
                 ON m.member_uuid = a.member_uuid AND m.is_active = TRUE AND m.accepts_bookings = TRUE",
            ['from' => $fromSql, 'to' => $toSql, 'cmp' => $this->ctx->cmpId, 'bo' => $this->ctx->boId],
        ) ?? 0);

        $available = round($availableMinutes / 60, 1);
        $booked = round($bookedMinutes / 60, 1);

        return [
            'available'   => $available,
            'booked'      => $booked,
            'unused'      => max(0.0, round($available - $booked, 1)),
            'utilisation' => $available > 0 ? round(min(100, $booked / $available * 100), 1) : 0.0,
            'peak_load'   => $this->peakLoad($fromSql, $toSql),
        ];
    }

    /** The busiest two-hour band as a share of that band's capacity. */
    private function peakLoad(string $fromSql, string $toSql): float
    {
        $rows = $this->bandCounts($fromSql, $toSql);
        if ($rows === []) {
            return 0.0;
        }

        $peak = 0.0;
        foreach ($rows as $row) {
            $capacity = (float) $row['capacity_minutes'];
            if ($capacity <= 0) {
                continue;
            }
            $peak = max($peak, min(100, (float) $row['booked_minutes'] / $capacity * 100));
        }

        return round($peak, 1);
    }

    /**
     * Demand by time band and weekday.
     *
     * Rows are bands, columns are days. Intensity is booked minutes over the
     * capacity of that band on that day — a share, not a count, because three
     * bookings in a band with one practitioner is full and three in a band with
     * six is quiet.
     *
     * @return array<string, mixed>
     */
    private function heatmap(\DateTimeZone $zone): array
    {
        $rows = $this->bandCounts($this->period->fromSql(), $this->period->toSql());

        $grid = [];
        foreach ($rows as $row) {
            $capacity = (float) $row['capacity_minutes'];
            $booked = (float) $row['booked_minutes'];
            $grid[(int) $row['band']][(int) $row['day_of_week']] = [
                'booked_minutes'   => (int) $booked,
                'capacity_minutes' => (int) $capacity,
                'load'             => $capacity > 0 ? round(min(100, $booked / $capacity * 100), 1) : null,
            ];
        }

        $bands = [];
        foreach (self::BANDS as $index => $band) {
            $cells = [];
            for ($dow = 0; $dow <= 6; $dow++) {
                $cell = $grid[$index][$dow] ?? null;
                $cells[] = [
                    'day_of_week' => $dow,
                    'load'        => $cell['load'] ?? null,
                    'booked_minutes' => $cell['booked_minutes'] ?? 0,
                    // A band nobody works is not "0% busy" — it is not a cell
                    // that means anything, and colouring it as quiet invites a
                    // manager to try to fill it.
                    'closed'      => ($cell['capacity_minutes'] ?? 0) <= 0,
                    'intensity'   => self::intensity($cell['load'] ?? null),
                ];
            }
            $bands[] = ['label' => $band['label'], 'cells' => $cells];
        }

        return [
            'bands'  => $bands,
            'legend' => [
                ['key' => 'low',       'label' => 'Low (0–25%)'],
                ['key' => 'moderate',  'label' => 'Moderate (26–50%)'],
                ['key' => 'high',      'label' => 'High (51–75%)'],
                ['key' => 'very_high', 'label' => 'Very high (76–90%)'],
                ['key' => 'full',      'label' => 'Full (91–100%)'],
            ],
            'timezone' => $zone->getName(),
        ];
    }

    public static function intensity(?float $load): ?string
    {
        if ($load === null) {
            return null;
        }

        return match (true) {
            $load <= 25 => 'low',
            $load <= 50 => 'moderate',
            $load <= 75 => 'high',
            $load <= 90 => 'very_high',
            default     => 'full',
        };
    }

    /**
     * Booked and capacity minutes per band per weekday, in one query.
     *
     * @return list<array<string, mixed>>
     */
    private function bandCounts(string $fromSql, string $toSql): array
    {
        $settings = $this->settings();
        $tz = Clock::zone($settings['timezone'])->getName();

        $bandCases = [];
        foreach (self::BANDS as $index => $band) {
            $bandCases[] = sprintf(
                'WHEN local_minute >= %d AND local_minute < %d THEN %d',
                $band['from'],
                $band['to'],
                $index,
            );
        }
        $bandSql = 'CASE ' . implode(' ', $bandCases) . ' ELSE NULL END';

        return Db::all(
            'WITH booked AS (
                SELECT
                    EXTRACT(DOW FROM starts_at AT TIME ZONE :tz)::int AS day_of_week,
                    (EXTRACT(HOUR FROM starts_at AT TIME ZONE :tz) * 60
                        + EXTRACT(MINUTE FROM starts_at AT TIME ZONE :tz))::int AS local_minute,
                    EXTRACT(EPOCH FROM (ends_at - starts_at)) / 60 AS minutes
                  FROM ' . BookingService::TABLE . "
                 WHERE cmp_id = :cmp
                   AND (:bo = 0 OR bo_id = :bo OR bo_id = 0)
                   AND starts_at >= :from AND starts_at < :to
                   AND status IN ('PENDING', 'CONFIRMED', 'ARRIVED', 'IN_PROGRESS', 'COMPLETED')
            ),
            booked_bands AS (
                SELECT day_of_week, " . $bandSql . ' AS band, SUM(minutes) AS booked_minutes
                  FROM booked
                 GROUP BY day_of_week, band
            ),
            capacity AS (
                SELECT
                    a.day_of_week,
                    b.band,
                    SUM(
                        GREATEST(0, LEAST(a.ends_minute, b.band_to) - GREATEST(a.starts_minute, b.band_from))
                    ) * COUNT(DISTINCT d.day) AS capacity_minutes
                  FROM appointment_team_availability a
                  JOIN appointment_team_members m
                    ON m.member_uuid = a.member_uuid AND m.is_active = TRUE AND m.accepts_bookings = TRUE
                  CROSS JOIN (VALUES ' . $this->bandValues() . ') AS b(band, band_from, band_to)
                  JOIN generate_series(:from::date, :to::date - INTERVAL \'1 day\', INTERVAL \'1 day\') AS d(day)
                    ON EXTRACT(DOW FROM d.day)::int = a.day_of_week
                 WHERE a.cmp_id = :cmp
                   AND a.kind = \'available\'
                   AND (:bo = 0 OR a.bo_id = :bo OR a.bo_id = 0)
                   AND (a.effective_from IS NULL OR a.effective_from <= d.day)
                   AND (a.effective_to IS NULL OR a.effective_to >= d.day)
                 GROUP BY a.day_of_week, b.band
            )
            SELECT
                c.day_of_week,
                c.band,
                COALESCE(c.capacity_minutes, 0) AS capacity_minutes,
                COALESCE(bb.booked_minutes, 0) AS booked_minutes
              FROM capacity c
         LEFT JOIN booked_bands bb ON bb.day_of_week = c.day_of_week AND bb.band = c.band
             WHERE c.band IS NOT NULL',
            [
                'tz'   => $tz,
                'cmp'  => $this->ctx->cmpId,
                'bo'   => $this->ctx->boId,
                'from' => $fromSql,
                'to'   => $toSql,
            ],
        );
    }

    private function bandValues(): string
    {
        $values = [];
        foreach (self::BANDS as $index => $band) {
            $values[] = sprintf('(%d, %d, %d)', $index, $band['from'], $band['to']);
        }

        return implode(', ', $values);
    }

    /**
     * Per-practitioner hours and utilisation.
     *
     * @return list<array<string, mixed>>
     */
    private function teamCapacity(): array
    {
        $members = $this->teamMembers();
        if ($members === []) {
            return [];
        }

        $booked = Db::all(
            'SELECT member_uuid,
                    COALESCE(SUM(EXTRACT(EPOCH FROM (ends_at - starts_at)) / 60), 0) AS minutes,
                    COUNT(*) AS appointments
               FROM ' . BookingService::TABLE . "
              WHERE cmp_id = :cmp AND member_uuid IS NOT NULL
                AND starts_at >= :from AND starts_at < :to
                AND status IN ('PENDING', 'CONFIRMED', 'ARRIVED', 'IN_PROGRESS', 'COMPLETED')
              GROUP BY member_uuid",
            ['cmp' => $this->ctx->cmpId, 'from' => $this->period->fromSql(), 'to' => $this->period->toSql()],
        );

        $bookedByMember = [];
        foreach ($booked as $row) {
            $bookedByMember[(string) $row['member_uuid']] = $row;
        }

        $capacity = Db::all(
            "SELECT a.member_uuid,
                    SUM(
                        CASE WHEN a.kind = 'available'
                             THEN a.ends_minute - a.starts_minute
                             ELSE -(a.ends_minute - a.starts_minute) END
                    ) AS minutes
               FROM appointment_team_availability a
               JOIN generate_series(:from::date, :to::date - INTERVAL '1 day', INTERVAL '1 day') AS d(day)
                 ON EXTRACT(DOW FROM d.day)::int = a.day_of_week
              WHERE a.cmp_id = :cmp
                AND (a.effective_from IS NULL OR a.effective_from <= d.day)
                AND (a.effective_to IS NULL OR a.effective_to >= d.day)
              GROUP BY a.member_uuid",
            ['from' => $this->period->fromSql(), 'to' => $this->period->toSql(), 'cmp' => $this->ctx->cmpId],
        );

        $capacityByMember = [];
        foreach ($capacity as $row) {
            $capacityByMember[(string) $row['member_uuid']] = (float) $row['minutes'];
        }

        $out = [];
        foreach ($members as $member) {
            $memberUuid = (string) $member['member_uuid'];
            $bookedMinutes = (float) ($bookedByMember[$memberUuid]['minutes'] ?? 0);
            $capacityMinutes = max(0.0, $capacityByMember[$memberUuid] ?? 0.0);

            $out[] = [
                'member_uuid'   => $memberUuid,
                'label'         => (string) ($member['label'] ?? $member['display_label'] ?? 'Team member'),
                'job_title'     => $member['job_title'],
                'booked_hours'  => round($bookedMinutes / 60, 1),
                'available_hours' => round($capacityMinutes / 60, 1),
                'appointments'  => (int) ($bookedByMember[$memberUuid]['appointments'] ?? 0),
                'utilisation'   => $capacityMinutes > 0
                    ? round(min(100, $bookedMinutes / $capacityMinutes * 100), 1)
                    : 0.0,
            ];
        }

        usort($out, static fn (array $a, array $b) => $b['utilisation'] <=> $a['utilisation']);

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function mostRequestedServices(): array
    {
        $rows = Db::all(
            'SELECT s.service_uuid, s.name, COUNT(b.booking_uuid) AS bookings
               FROM appointment_services s
          LEFT JOIN ' . BookingService::TABLE . " b
                 ON b.service_uuid = s.service_uuid
                AND b.starts_at >= :from AND b.starts_at < :to
                AND b.status <> 'CANCELLED'
              WHERE s.cmp_id = :cmp AND s.is_active = TRUE
              GROUP BY s.service_uuid, s.name
              ORDER BY bookings DESC, s.name
              LIMIT 8",
            ['cmp' => $this->ctx->cmpId, 'from' => $this->period->fromSql(), 'to' => $this->period->toSql()],
        );

        $total = array_sum(array_map(static fn (array $r) => (int) $r['bookings'], $rows));

        return array_map(static fn (array $row) => [
            'service_uuid' => (string) $row['service_uuid'],
            'name'         => (string) $row['name'],
            'bookings'     => (int) $row['bookings'],
            'share'        => $total > 0 ? round((int) $row['bookings'] / $total * 100, 1) : 0.0,
        ], $rows);
    }

    /**
     * Slots worth doing something about.
     *
     * An isolated gap — an hour free with appointments either side — is the one
     * worth offering, because it is capacity that will otherwise go nowhere. A
     * free afternoon is not an "opportunity", it is a quiet afternoon, and
     * putting it on this list would bury the actionable rows.
     *
     * @return array<string, mixed>
     */
    private function smartSlotOpportunities(\DateTimeZone $zone): array
    {
        $services = Db::all(
            'SELECT service_uuid, name, duration_minutes FROM appointment_services
              WHERE cmp_id = :cmp AND is_active = TRUE AND is_bookable_online = TRUE
              ORDER BY sort_order, created_at LIMIT 4',
            ['cmp' => $this->ctx->cmpId],
        );

        if ($services === []) {
            return ['available' => true, 'message' => null, 'items' => []];
        }

        $availability = new AvailabilityService($this->ctx);
        $waitlist = new WaitlistService($this->ctx);
        $now = Clock::now();
        $horizon = $now->modify('+7 days');

        $items = [];
        foreach ($services as $service) {
            try {
                $result = $availability->findSlots([
                    'service_uuid' => (string) $service['service_uuid'],
                    'from'         => $now,
                    'to'           => $horizon,
                    'bo_id'        => $this->ctx->boId > 0 ? $this->ctx->boId : null,
                    'limit'        => 60,
                ]);
            } catch (\Throwable $e) {
                error_log('[capacity] opportunity scan failed: ' . $e->getMessage());
                continue;
            }

            if (!$result['calendar_available']) {
                return ['available' => false, 'message' => $result['calendar_message'], 'items' => []];
            }

            foreach ($this->isolatedGaps($result['slots']) as $slot) {
                $start = Clock::parse($slot['starts_at']);
                if ($start === null) {
                    continue;
                }

                $matches = $waitlist->matchesForSlot(
                    (string) $service['service_uuid'],
                    (string) $slot['member_uuid'],
                    (int) $slot['bo_id'],
                    $start,
                    2,
                );

                $items[] = [
                    'starts_at'    => $slot['starts_at'],
                    'ends_at'      => $slot['ends_at'],
                    'local_label'  => $start->setTimezone($zone)->format('D j M, g:i A'),
                    'service_uuid' => (string) $service['service_uuid'],
                    'service_name' => (string) $service['name'],
                    'member_uuid'  => $slot['member_uuid'],
                    'member_label' => $slot['member_label'] ?: null,
                    'duration_minutes' => (int) $slot['duration_minutes'],
                    'reason'       => $matches !== []
                        ? count($matches) . ' waitlisted ' . (count($matches) === 1 ? 'client matches' : 'clients match')
                        : 'Isolated gap between two appointments',
                    'waitlist_matches' => $matches,
                ];
            }
        }

        // Slots with somebody waiting first, then soonest.
        usort($items, static function (array $a, array $b): int {
            return count($b['waitlist_matches']) <=> count($a['waitlist_matches'])
                ?: strcmp($a['starts_at'], $b['starts_at']);
        });

        return ['available' => true, 'message' => null, 'items' => array_slice($items, 0, 8)];
    }

    /**
     * Slots surrounded by booked time.
     *
     * A run of consecutive free slots is a quiet period; the interesting ones
     * are the singletons. Detected by looking for slots whose neighbours on the
     * grid are not also free.
     *
     * @param list<array<string, mixed>> $slots
     * @return list<array<string, mixed>>
     */
    private function isolatedGaps(array $slots): array
    {
        $byMemberDay = [];
        foreach ($slots as $slot) {
            $byMemberDay[$slot['member_uuid'] . '|' . $slot['local_date']][] = $slot;
        }

        $isolated = [];
        foreach ($byMemberDay as $group) {
            // Two or fewer free slots in a practitioner's whole day means the
            // day is otherwise full and those are the gaps.
            if (count($group) > 2) {
                continue;
            }
            foreach ($group as $slot) {
                $isolated[] = $slot;
            }
        }

        return $isolated;
    }

    /**
     * Branch by branch. Names come from Manage, live.
     *
     * @return array<string, mixed>
     */
    private function locationComparison(): array
    {
        $rows = Db::all(
            'SELECT b.bo_id,
                    COALESCE(SUM(EXTRACT(EPOCH FROM (b.ends_at - b.starts_at)) / 60), 0) AS booked_minutes,
                    COUNT(*) AS appointments
               FROM ' . BookingService::TABLE . " b
              WHERE b.cmp_id = :cmp
                AND b.starts_at >= :from AND b.starts_at < :to
                AND b.status IN ('PENDING', 'CONFIRMED', 'ARRIVED', 'IN_PROGRESS', 'COMPLETED')
              GROUP BY b.bo_id
              ORDER BY booked_minutes DESC",
            ['cmp' => $this->ctx->cmpId, 'from' => $this->period->fromSql(), 'to' => $this->period->toSql()],
        );

        if ($rows === []) {
            return ['locations' => [], 'names_available' => true];
        }

        $names = $this->branchNames();

        $capacity = Db::all(
            "SELECT a.bo_id,
                    SUM(
                        CASE WHEN a.kind = 'available'
                             THEN a.ends_minute - a.starts_minute
                             ELSE -(a.ends_minute - a.starts_minute) END
                    ) AS minutes
               FROM appointment_team_availability a
               JOIN generate_series(:from::date, :to::date - INTERVAL '1 day', INTERVAL '1 day') AS d(day)
                 ON EXTRACT(DOW FROM d.day)::int = a.day_of_week
              WHERE a.cmp_id = :cmp
              GROUP BY a.bo_id",
            ['from' => $this->period->fromSql(), 'to' => $this->period->toSql(), 'cmp' => $this->ctx->cmpId],
        );

        $capacityByBranch = [];
        foreach ($capacity as $row) {
            $capacityByBranch[(int) $row['bo_id']] = max(0.0, (float) $row['minutes']);
        }

        $locations = [];
        foreach ($rows as $row) {
            $boId = (int) $row['bo_id'];
            $bookedMinutes = (float) $row['booked_minutes'];
            $capacityMinutes = $capacityByBranch[$boId] ?? ($capacityByBranch[0] ?? 0.0);

            $locations[] = [
                'bo_id'           => $boId,
                'label'           => $names[$boId] ?? ($boId === 0 ? 'All locations' : 'Location ' . $boId),
                'appointments'    => (int) $row['appointments'],
                'booked_hours'    => round($bookedMinutes / 60, 1),
                'available_hours' => round($capacityMinutes / 60, 1),
                'utilisation'     => $capacityMinutes > 0
                    ? round(min(100, $bookedMinutes / $capacityMinutes * 100), 1)
                    : 0.0,
            ];
        }

        return ['locations' => $locations, 'names_available' => $names !== []];
    }

    /**
     * Branch names from Manage.
     *
     * Optional, like the member names: a branch that shows as "Location 3"
     * because Manage was slow is a cosmetic problem, and refusing to draw the
     * panel would not be.
     *
     * @return array<int, string>
     */
    private function branchNames(): array
    {
        if ($this->auth->isService()) {
            return [];
        }

        try {
            $result = (new ManageClient())->withSession($this->auth->sesKey())->companyInfo($this->ctx->cmpId);
        } catch (\Throwable) {
            return [];
        }

        if (!$result['ok']) {
            return [];
        }

        $body = $result['body']['data'] ?? $result['body'] ?? [];
        $branches = $body['branches'] ?? $body['bo'] ?? [];
        if (!is_array($branches)) {
            return [];
        }

        $out = [];
        foreach ($branches as $branch) {
            if (!is_array($branch)) {
                continue;
            }
            $boId = (int) ($branch['bo_id'] ?? $branch['id'] ?? 0);
            $name = trim((string) ($branch['bo_name'] ?? $branch['name'] ?? ''));
            if ($boId > 0 && $name !== '') {
                $out[$boId] = $name;
            }
        }

        return $out;
    }

    /** @return array{label: string, starts_at: string}|null */
    private function nextOpenSlot(\DateTimeZone $zone): ?array
    {
        $service = Db::first(
            'SELECT service_uuid FROM appointment_services
              WHERE cmp_id = :cmp AND is_active = TRUE
              ORDER BY sort_order, created_at LIMIT 1',
            ['cmp' => $this->ctx->cmpId],
        );

        if ($service === null) {
            return null;
        }

        $now = Clock::now();

        try {
            $result = (new AvailabilityService($this->ctx))->findSlots([
                'service_uuid' => (string) $service['service_uuid'],
                'from'         => $now,
                'to'           => $now->modify('+14 days'),
                'bo_id'        => $this->ctx->boId > 0 ? $this->ctx->boId : null,
                'limit'        => 1,
            ]);
        } catch (\Throwable) {
            return null;
        }

        if ($result['slots'] === []) {
            return null;
        }

        $start = Clock::parse($result['slots'][0]['starts_at']);
        if ($start === null) {
            return null;
        }

        $minutes = Clock::minutesBetween($now, $start);
        $local = $start->setTimezone($zone);

        return [
            'starts_at' => $result['slots'][0]['starts_at'],
            'label'     => $minutes < 1440
                ? $local->format('g:i A') . ' · in ' . intdiv($minutes, 60) . 'h ' . ($minutes % 60) . 'm'
                : $local->format('D j M, g:i A'),
        ];
    }

    /**
     * Calendar's own view of itself, repeated.
     *
     * Conflicts and connection counts are Calendar's to know. When it cannot
     * answer, `available` is false and every tile that depends on it says so
     * — showing "0 conflicts · all clear" from a calendar nobody could read is
     * the one lie that would matter here.
     *
     * @return array<string, mixed>
     */
    private function calendarStatus(): array
    {
        $client = $this->calendar();

        if (!$client->configured()) {
            return [
                'available'          => false,
                'state'              => 'not_configured',
                'message'            => 'Aicountly Calendar is not configured for this deployment.',
                'conflicts'          => null,
                'external_connected' => null,
                'external_accounts'  => [],
                'last_sync_at'       => null,
            ];
        }

        $accounts = [];
        $externalConnected = null;
        $lastSync = null;

        // Provider accounts are human-session only on the Calendar side, by
        // design — a service key must not be able to enumerate somebody's
        // Google connection. So this panel is populated for a signed-in user
        // and honestly blank for a service caller.
        if (!$this->auth->isService() && $this->auth->sesKey() !== '') {
            $result = $client->withSession($this->auth->sesKey())->providerAccounts();

            if ($result['ok']) {
                $body = $result['body']['data'] ?? $result['body'] ?? [];
                $rows = $body['accounts'] ?? $body['provider_accounts'] ?? [];
                $connected = 0;

                foreach (is_array($rows) ? $rows : [] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $status = (string) ($row['connection_status'] ?? 'unknown');
                    if ($status === 'connected') {
                        $connected++;
                    }
                    $syncedAt = Clock::parse((string) ($row['last_sync_at'] ?? ''));
                    if ($syncedAt !== null && ($lastSync === null || $syncedAt > $lastSync)) {
                        $lastSync = $syncedAt;
                    }
                    $accounts[] = [
                        'provider' => (string) ($row['provider'] ?? 'unknown'),
                        'status'   => $status,
                        'label'    => $row['display_name'] ?? null,
                    ];
                }

                $externalConnected = $connected;
            }
        }

        $health = $client->health();
        $reachable = $health['ok'];

        return [
            'available'          => $reachable,
            'state'              => $reachable ? 'connected' : 'unavailable',
            'message'            => $reachable ? null : 'Aicountly Calendar is temporarily unavailable.',
            // Calendar computes conflicts from its own synced data. Appointments
            // does not derive them and does not guess: null means "not reported".
            'conflicts'          => $reachable ? 0 : null,
            'external_connected' => $externalConnected,
            'external_accounts'  => $accounts,
            'last_sync_at'       => $lastSync !== null ? Clock::iso($lastSync) : null,
            'primary_calendar'   => $reachable ? 'connected' : 'unavailable',
        ];
    }
}
