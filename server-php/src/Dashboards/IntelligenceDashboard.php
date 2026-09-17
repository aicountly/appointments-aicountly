<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Clients\PayClient;
use Aicountly\Api\Clients\ReceptionistClient;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\BookingService;
use Aicountly\Api\Support\Clock;

/**
 * DASHBOARD 5 — Business Intelligence & Growth.
 *
 * "How is the appointment operation growing?"
 *
 * The screen an owner opens on a Friday. Trends over a period, service and
 * staff performance, where the demand comes from, and what the deposits came
 * to. Nothing on it is about today.
 *
 * ## Staff performance is not a staff appraisal
 *
 * Appointments delivered, utilisation, rebook rate. Appointment-shaped
 * measures, on a screen about the business. There is deliberately no ranking
 * score, no league table position and no "performance rating" — this is not an
 * HRMS and it must not become the place somebody's review gets written from.
 *
 * ## Payments
 *
 * Read from Pay, live, when Pay is enabled. Until then the panel says so and
 * offers the connection. It never shows a figure from this product's own
 * database, because this product does not have one: Appointments knows a
 * deposit was REQUIRED, and only Pay knows whether it arrived.
 *
 * ## Receptionist contribution
 *
 * Counts, from Receptionist's API, when it is connected. Never call analytics —
 * conversations belong to Receptionist and this screen links out to them.
 */
final class IntelligenceDashboard extends Dashboard
{
    public function id(): string
    {
        return 'intelligence';
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $current = $this->summary($this->period->fromSql(), $this->period->toSql());
        $previous = $this->summary($this->period->previousFromSql(), $this->period->previousToSql());

        $services = $this->servicePerformance();

        $metrics = [
            Metric::make('appointments', 'Appointments', $current['total'], 'count', $previous['total'], 'up_is_good', 'vs previous period'),
            Metric::make('booking_conversion', 'Booking conversion', $current['conversion'], 'percent', $previous['conversion'], 'up_is_good', 'confirmed of booked'),
            Metric::make('utilisation', 'Utilisation', $current['utilisation'], 'percent', $previous['utilisation'], 'up_is_good', 'vs previous period'),
            Metric::make('repeat_booking', 'Repeat booking', $current['repeat_rate'], 'percent', $previous['repeat_rate'], 'up_is_good', 'clients who came back'),
            Metric::make('cancellation', 'Cancellation', $current['cancellation_rate'], 'percent', $previous['cancellation_rate'], 'down_is_good', 'vs previous period'),
            Metric::make('no_show', 'No-show', $current['no_show_rate'], 'percent', $previous['no_show_rate'], 'down_is_good', 'vs previous period'),
        ];

        $changePct = ($previous['total'] > 0)
            ? round((($current['total'] - $previous['total']) / $previous['total']) * 100, 1)
            : null;

        return $this->envelope(
            $metrics,
            [
                'demand_trend'   => $this->demandTrend(),
                'sources'        => $this->bookingSources($this->period->fromSql(), $this->period->toSql()),
                'receptionist'   => $this->receptionistContribution(),
                'services'       => $services,
                'staff'          => $this->staffPerformance(),
                'payments'       => $this->payments(),
            ],
            [
                'appointments_change_pct' => $changePct,
                'appointments'            => $current['total'],
                'period_label'            => $this->period->label,
                'top_services'            => $services,
            ],
            $this->sources([
                'Deposits and payments'     => 'Aicountly Pay',
                'Receptionist contribution' => 'Aicountly Receptionist',
                'Appointment times'         => 'Aicountly Calendar',
            ]),
        );
    }

    /** @return array<string, int|float> */
    private function summary(string $fromSql, string $toSql): array
    {
        [$scope, $params] = $this->ctx->scopeClause('b');

        $row = Db::first(
            "SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE b.confirmed_at IS NOT NULL) AS confirmed,
                COUNT(*) FILTER (WHERE b.status = 'COMPLETED') AS completed,
                COUNT(*) FILTER (WHERE b.status = 'CANCELLED') AS cancelled,
                COUNT(*) FILTER (WHERE b.status = 'NO_SHOW') AS no_shows,
                COALESCE(SUM(EXTRACT(EPOCH FROM (b.ends_at - b.starts_at)) / 60)
                    FILTER (WHERE b.status IN ('PENDING','CONFIRMED','ARRIVED','IN_PROGRESS','COMPLETED')), 0) AS booked_minutes,
                COUNT(DISTINCT b.contact_uuid) FILTER (WHERE b.contact_uuid IS NOT NULL) AS clients,
                COUNT(DISTINCT b.contact_uuid) FILTER (
                    WHERE b.contact_uuid IS NOT NULL
                      AND EXISTS (
                        SELECT 1 FROM " . BookingService::TABLE . " prior
                         WHERE prior.cmp_id = b.cmp_id
                           AND prior.contact_uuid = b.contact_uuid
                           AND prior.starts_at < b.starts_at
                           AND prior.status = 'COMPLETED'
                      )
                ) AS repeat_clients
             FROM " . BookingService::TABLE . ' b
            WHERE ' . $scope . ' AND b.starts_at >= :from AND b.starts_at < :to',
            $params + ['from' => $fromSql, 'to' => $toSql],
        ) ?? [];

        $total = (int) ($row['total'] ?? 0);
        $completed = (int) ($row['completed'] ?? 0);
        $noShows = (int) ($row['no_shows'] ?? 0);
        $clients = (int) ($row['clients'] ?? 0);

        $availableMinutes = (int) (Db::scalar(
            "SELECT COALESCE(SUM(
                        CASE WHEN a.kind = 'available'
                             THEN a.ends_minute - a.starts_minute
                             ELSE -(a.ends_minute - a.starts_minute) END
                    ), 0)
               FROM appointment_team_availability a
               JOIN appointment_team_members m
                 ON m.member_uuid = a.member_uuid AND m.is_active = TRUE AND m.accepts_bookings = TRUE
               JOIN generate_series(:from::date, :to::date - INTERVAL '1 day', INTERVAL '1 day') AS d(day)
                 ON EXTRACT(DOW FROM d.day)::int = a.day_of_week
              WHERE a.cmp_id = :cmp
                AND (a.effective_from IS NULL OR a.effective_from <= d.day)
                AND (a.effective_to IS NULL OR a.effective_to >= d.day)",
            ['from' => $fromSql, 'to' => $toSql, 'cmp' => $this->ctx->cmpId],
        ) ?? 0);

        $bookedMinutes = (float) ($row['booked_minutes'] ?? 0);

        return [
            'total'             => $total,
            'confirmed'         => (int) ($row['confirmed'] ?? 0),
            'completed'         => $completed,
            'conversion'        => $this->percent((int) ($row['confirmed'] ?? 0), $total),
            'cancellation_rate' => $this->percent((int) ($row['cancelled'] ?? 0), $total),
            'no_show_rate'      => $this->percent($noShows, $completed + $noShows),
            'repeat_rate'       => $this->percent((int) ($row['repeat_clients'] ?? 0), $clients),
            'utilisation'       => $availableMinutes > 0
                ? round(min(100, $bookedMinutes / $availableMinutes * 100), 1)
                : 0.0,
        ];
    }

    /**
     * Appointments, bookings, completions and cancellations over time.
     *
     * Bucketed by day for short periods and by week for long ones: 365 daily
     * points on a chart 700 pixels wide is noise, and the shape is what the
     * reader is here for.
     *
     * @return array<string, mixed>
     */
    private function demandTrend(): array
    {
        $days = $this->period->days();
        $bucket = $days > 120 ? 'month' : ($days > 45 ? 'week' : 'day');

        $rows = Db::all(
            "SELECT date_trunc(:bucket, starts_at) AS bucket,
                    COUNT(*) AS appointments,
                    COUNT(*) FILTER (WHERE created_at >= :from AND created_at < :to) AS bookings,
                    COUNT(*) FILTER (WHERE status = 'COMPLETED') AS completions,
                    COUNT(*) FILTER (WHERE status = 'CANCELLED') AS cancellations
               FROM " . BookingService::TABLE . '
              WHERE cmp_id = :cmp
                AND (:bo = 0 OR bo_id = :bo OR bo_id = 0)
                AND starts_at >= :from AND starts_at < :to
              GROUP BY 1
              ORDER BY 1',
            [
                'bucket' => $bucket,
                'cmp'    => $this->ctx->cmpId,
                'bo'     => $this->ctx->boId,
                'from'   => $this->period->fromSql(),
                'to'     => $this->period->toSql(),
            ],
        );

        return [
            'bucket' => $bucket,
            'series' => [
                ['key' => 'appointments',  'label' => 'Appointments'],
                ['key' => 'bookings',      'label' => 'Bookings'],
                ['key' => 'completions',   'label' => 'Completions'],
                ['key' => 'cancellations', 'label' => 'Cancellations'],
            ],
            'points' => array_map(static fn (array $row) => [
                'at'            => substr((string) $row['bucket'], 0, 10),
                'appointments'  => (int) $row['appointments'],
                'bookings'      => (int) $row['bookings'],
                'completions'   => (int) $row['completions'],
                'cancellations' => (int) $row['cancellations'],
            ], $rows),
        ];
    }

    /**
     * Service by service, with growth against the previous period.
     *
     * @return list<array<string, mixed>>
     */
    private function servicePerformance(): array
    {
        $rows = Db::all(
            "SELECT s.service_uuid, s.name, s.duration_minutes,
                    COUNT(b.booking_uuid) FILTER (
                        WHERE b.starts_at >= :from AND b.starts_at < :to
                    ) AS bookings,
                    COUNT(b.booking_uuid) FILTER (
                        WHERE b.starts_at >= :from AND b.starts_at < :to AND b.status = 'COMPLETED'
                    ) AS completions,
                    COALESCE(SUM(EXTRACT(EPOCH FROM (b.ends_at - b.starts_at)) / 60) FILTER (
                        WHERE b.starts_at >= :from AND b.starts_at < :to
                          AND b.status IN ('PENDING','CONFIRMED','ARRIVED','IN_PROGRESS','COMPLETED')
                    ), 0) AS booked_minutes,
                    COUNT(b.booking_uuid) FILTER (
                        WHERE b.starts_at >= :prev_from AND b.starts_at < :prev_to
                    ) AS previous_bookings
               FROM appointment_services s
          LEFT JOIN " . BookingService::TABLE . ' b ON b.service_uuid = s.service_uuid
              WHERE s.cmp_id = :cmp AND s.is_active = TRUE
              GROUP BY s.service_uuid, s.name, s.duration_minutes
              ORDER BY bookings DESC, s.name
              LIMIT 12',
            [
                'cmp'       => $this->ctx->cmpId,
                'from'      => $this->period->fromSql(),
                'to'        => $this->period->toSql(),
                'prev_from' => $this->period->previousFromSql(),
                'prev_to'   => $this->period->previousToSql(),
            ],
        );

        $totalBookedMinutes = array_sum(array_map(static fn (array $r) => (float) $r['booked_minutes'], $rows));

        return array_map(function (array $row) use ($totalBookedMinutes): array {
            $bookings = (int) $row['bookings'];
            $previous = (int) $row['previous_bookings'];

            return [
                'service_uuid' => (string) $row['service_uuid'],
                'name'         => (string) $row['name'],
                'bookings'     => $bookings,
                'completions'  => (int) $row['completions'],
                // Share of the period's booked time, which is the honest
                // per-service "utilisation": a service has no capacity of its
                // own, only a claim on the team's.
                'utilisation'  => $totalBookedMinutes > 0
                    ? round((float) $row['booked_minutes'] / $totalBookedMinutes * 100, 1)
                    : 0.0,
                // Null, not zero, when there is nothing to compare against.
                'growth'       => $previous > 0 ? round((($bookings - $previous) / $previous) * 100, 1) : null,
                'previous'     => $previous,
            ];
        }, $rows);
    }

    /**
     * Staff, measured on appointment work only.
     *
     * NOT an appraisal. See the class docblock.
     *
     * @return list<array<string, mixed>>
     */
    private function staffPerformance(): array
    {
        $members = $this->teamMembers();
        if ($members === []) {
            return [];
        }

        $rows = Db::all(
            "SELECT b.member_uuid,
                    COUNT(*) AS appointments,
                    COUNT(*) FILTER (WHERE b.status = 'COMPLETED') AS completed,
                    COUNT(*) FILTER (WHERE b.status = 'NO_SHOW') AS no_shows,
                    COALESCE(SUM(EXTRACT(EPOCH FROM (b.ends_at - b.starts_at)) / 60) FILTER (
                        WHERE b.status IN ('PENDING','CONFIRMED','ARRIVED','IN_PROGRESS','COMPLETED')
                    ), 0) AS booked_minutes,
                    COUNT(*) FILTER (
                        WHERE b.status = 'COMPLETED'
                          AND EXISTS (
                            SELECT 1 FROM " . BookingService::TABLE . " later
                             WHERE later.cmp_id = b.cmp_id
                               AND later.contact_uuid = b.contact_uuid
                               AND later.contact_uuid IS NOT NULL
                               AND later.starts_at > b.starts_at
                               AND later.status <> 'CANCELLED'
                          )
                    ) AS rebooked
               FROM " . BookingService::TABLE . ' b
              WHERE b.cmp_id = :cmp AND b.member_uuid IS NOT NULL
                AND b.starts_at >= :from AND b.starts_at < :to
              GROUP BY b.member_uuid',
            ['cmp' => $this->ctx->cmpId, 'from' => $this->period->fromSql(), 'to' => $this->period->toSql()],
        );

        $byMember = [];
        foreach ($rows as $row) {
            $byMember[(string) $row['member_uuid']] = $row;
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
            $capacityByMember[(string) $row['member_uuid']] = max(0.0, (float) $row['minutes']);
        }

        $out = [];
        foreach ($members as $member) {
            $memberUuid = (string) $member['member_uuid'];
            $stats = $byMember[$memberUuid] ?? null;
            if ($stats === null) {
                continue;
            }

            $capacityMinutes = $capacityByMember[$memberUuid] ?? 0.0;
            $completed = (int) $stats['completed'];

            $out[] = [
                'member_uuid'  => $memberUuid,
                'label'        => (string) ($member['label'] ?? $member['display_label'] ?? 'Team member'),
                'job_title'    => $member['job_title'],
                'appointments' => (int) $stats['appointments'],
                'completed'    => $completed,
                'no_shows'     => (int) $stats['no_shows'],
                'utilisation'  => $capacityMinutes > 0
                    ? round(min(100, (float) $stats['booked_minutes'] / $capacityMinutes * 100), 1)
                    : 0.0,
                'rebook_rate'  => $this->percent((int) $stats['rebooked'], $completed),
            ];
        }

        usort($out, static fn (array $a, array $b) => $b['appointments'] <=> $a['appointments']);

        return $out;
    }

    /**
     * Receptionist's contribution, or an honest placeholder.
     *
     * The `attributed` figures below come from this product's own bookings
     * (booking_source = RECEPTIONIST, which is proven by the service key that
     * created them). They are shown whether or not Receptionist itself answers,
     * because Appointments genuinely knows them.
     *
     * @return array<string, mixed>
     */
    private function receptionistContribution(): array
    {
        $client = new ReceptionistClient();

        $attributed = Db::first(
            "SELECT
                COUNT(*) AS booked,
                COUNT(*) FILTER (WHERE status = 'RESCHEDULED') AS rescheduled,
                COUNT(*) FILTER (WHERE status = 'CANCELLED') AS cancelled
             FROM " . BookingService::TABLE . "
            WHERE cmp_id = :cmp AND booking_source = 'RECEPTIONIST'
              AND starts_at >= :from AND starts_at < :to",
            ['cmp' => $this->ctx->cmpId, 'from' => $this->period->fromSql(), 'to' => $this->period->toSql()],
        ) ?? [];

        $panel = [
            'configured'         => $client->configured(),
            'unavailable_reason' => $client->configured() ? null : $client->unavailableMessage(),
            'deep_link'          => $client->configured() ? $client->deepLink($this->ctx->cmpId) : null,
            'attributed'         => [
                'booked'      => (int) ($attributed['booked'] ?? 0),
                'rescheduled' => (int) ($attributed['rescheduled'] ?? 0),
                'cancelled'   => (int) ($attributed['cancelled'] ?? 0),
            ],
            // Only Receptionist knows how many calls it started that never
            // became a booking, so `initiated` stays null until it answers.
            'initiated'          => null,
        ];

        if (!$client->configured()) {
            return $panel;
        }

        $result = $client->contribution([
            'cmp_id' => $this->ctx->cmpId,
            'from'   => $this->period->from->format('Y-m-d'),
            'to'     => $this->period->to->format('Y-m-d'),
        ]);

        if ($result['ok']) {
            $body = $result['body']['data'] ?? $result['body'] ?? [];
            $panel['initiated'] = isset($body['initiated']) ? (int) $body['initiated'] : null;
        } else {
            $panel['unavailable_reason'] = 'Receptionist did not answer, so only the bookings it created are shown.';
        }

        return $panel;
    }

    /**
     * Deposits and appointment value, from Pay.
     *
     * `required_minor` is ours: the sum of what booking policy asked for. Every
     * other figure is Pay's and is null until Pay is connected, because this
     * product does not know what was collected and must not imply that it does.
     *
     * @return array<string, mixed>
     */
    private function payments(): array
    {
        $client = new PayClient();

        $requiredMinor = (int) (Db::scalar(
            'SELECT COALESCE(SUM(s.deposit_minor), 0)
               FROM ' . BookingService::TABLE . " b
               JOIN appointment_services s ON s.service_uuid = b.service_uuid
              WHERE b.cmp_id = :cmp
                AND s.deposit_required = TRUE
                AND b.starts_at >= :from AND b.starts_at < :to
                AND b.status <> 'CANCELLED'",
            ['cmp' => $this->ctx->cmpId, 'from' => $this->period->fromSql(), 'to' => $this->period->toSql()],
        ) ?? 0);

        $panel = [
            'configured'         => $client->configured(),
            'unavailable_reason' => $client->configured() ? null : $client->unavailableMessage(),
            'connect_action'     => $client->configured() ? null : ['route' => 'integrations', 'label' => 'Connect Aicountly Pay'],
            'currency'           => $this->settings()['currency'],
            'deposit_required_minor' => $requiredMinor,
            'collected_minor'    => null,
            'pending_minor'      => null,
            'refunded_minor'     => null,
            'appointment_value_minor' => null,
            'benefits'           => [
                'Secure online payments',
                'Automated deposit collection',
                'Fewer no-shows',
                'A smoother client experience',
                'Automatic reconciliation',
            ],
        ];

        if (!$client->configured()) {
            return $panel;
        }

        $result = $client->summary([
            'reference_prefix' => 'appointments',
            'cmp_id'           => $this->ctx->cmpId,
            'from'             => $this->period->from->format('Y-m-d'),
            'to'               => $this->period->to->format('Y-m-d'),
        ]);

        if (!$result['ok']) {
            $panel['unavailable_reason'] = 'Aicountly Pay did not answer, so payment figures are not shown.';

            return $panel;
        }

        $body = $result['body']['data'] ?? $result['body'] ?? [];

        $panel['collected_minor'] = isset($body['collected_minor']) ? (int) $body['collected_minor'] : null;
        $panel['pending_minor'] = isset($body['pending_minor']) ? (int) $body['pending_minor'] : null;
        $panel['refunded_minor'] = isset($body['refunded_minor']) ? (int) $body['refunded_minor'] : null;
        $panel['appointment_value_minor'] = isset($body['total_minor']) ? (int) $body['total_minor'] : null;

        return $panel;
    }
}
