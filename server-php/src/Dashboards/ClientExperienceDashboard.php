<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Clients\MessagingClient;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\BookingService;
use Aicountly\Api\Domain\NoShowRiskService;
use Aicountly\Api\Domain\ReminderService;
use Aicountly\Api\Support\Clock;

/**
 * DASHBOARD 4 — Client Experience & Engagement.
 *
 * "How effectively are clients booking, confirming, attending and returning?"
 *
 * The funnel screen. Where Capacity asks whether there was room, this asks
 * whether the people who wanted it got through — and whether they came back.
 *
 * ## The funnel is measured, not modelled
 *
 * Every stage is a count of rows this product owns. "Booking started" is the
 * one stage that needs an external signal, and where nothing reports it the
 * stage says so rather than being back-filled from the stage below it. A funnel
 * whose first bar is silently equal to its second is a funnel with no top.
 *
 * ## Reminder performance is honest about delivery
 *
 * Delivery comes from what the messaging provider told us, per reminder, at the
 * time. With no provider connected, reminders are still scheduled and appear
 * here as `not_sent` with the reason — never as delivered. A panel reporting
 * 96% delivery on messages nobody sent is worse than an empty panel, because
 * somebody would act on it.
 *
 * ## The period is the appointment's date, not the booking's
 *
 * "Last 30 days" here means appointments that FELL in the last 30 days, not
 * bookings that were made in it. Attendance, no-show and rebook rates only mean
 * anything that way: a booking made today for next March has no attendance to
 * measure, and counting it would dilute every rate on the screen.
 *
 * The consequence is that the funnel reads "of the appointments scheduled in
 * this period, how many were booked online, confirmed, attended and rebooked" —
 * one consistent denominator rather than two mixed ones.
 *
 * ## No-show intelligence is indicators, not a score
 *
 * See NoShowRiskService. Named, countable signals with thresholds in the code.
 * The panel says "present in 42% of no-shows", not "causes", and the copy is
 * written that way deliberately.
 */
final class ClientExperienceDashboard extends Dashboard
{
    public function id(): string
    {
        return 'client_experience';
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $current = $this->engagement($this->period->fromSql(), $this->period->toSql());
        $previous = $this->engagement($this->period->previousFromSql(), $this->period->previousToSql());

        $reminders = new ReminderService($this->ctx);
        $performance = $reminders->performance($this->period->fromSql(), $this->period->toSql());

        $risk = new NoShowRiskService($this->ctx);
        $factors = $risk->factorFrequency($this->period->fromSql(), $this->period->toSql());

        $metrics = [
            Metric::make('new_clients', 'New clients', $current['new_clients'], 'count', $previous['new_clients'], 'up_is_good', 'vs previous period', 'clients', ['segment' => 'new']),
            Metric::make('returning_clients', 'Returning clients', $current['returning_clients'], 'count', $previous['returning_clients'], 'up_is_good', 'vs previous period', 'clients', ['segment' => 'returning']),
            Metric::make('confirmation_rate', 'Confirmation rate', $current['confirmation_rate'], 'percent', $previous['confirmation_rate'], 'up_is_good', 'vs previous period'),
            Metric::make('attendance_rate', 'Attendance rate', $current['attendance_rate'], 'percent', $previous['attendance_rate'], 'up_is_good', 'vs previous period'),
            Metric::make('rebook_rate', 'Rebook rate', $current['rebook_rate'], 'percent', $previous['rebook_rate'], 'up_is_good', 'vs previous period'),
            Metric::make('no_show_rate', 'No-show rate', $current['no_show_rate'], 'percent', $previous['no_show_rate'], 'down_is_good', 'vs previous period', 'appointments', ['status' => 'NO_SHOW']),
        ];

        return $this->envelope(
            $metrics,
            [
                'funnel'      => $this->funnel($current),
                'reminders'   => $this->reminderPanel($performance),
                'no_show'     => [
                    'rate'         => $current['no_show_rate'],
                    'previous'     => $previous['no_show_rate'],
                    'trend'        => $this->noShowTrend(),
                    'no_shows'     => $factors['no_shows'],
                    'factors'      => $factors['factors'],
                    // The sentence the UI must show next to the factor list.
                    'caveat'       => 'These indicators were present in the appointments that were missed. '
                        . 'That is a correlation this product can count, not a cause it can prove.',
                    'indicators'   => array_map(
                        static fn (string $key, array $meta) => ['key' => $key, 'label' => $meta['label'], 'weight' => $meta['weight']],
                        array_keys(NoShowRiskService::INDICATORS),
                        NoShowRiskService::INDICATORS,
                    ),
                ],
                'preparation' => $this->todaysPreparation(),
                'top_clients' => $this->clientProfiles(),
            ],
            [
                'reminder_channels' => $performance['channels'],
                'no_show_factors'   => $factors['factors'],
                'no_show_count'     => $factors['no_shows'],
            ],
            $this->sources([
                'Client names and contact details' => 'Aicountly Contacts',
                'Reminder delivery'                => 'Messaging provider',
                'Appointment times'                => 'Aicountly Calendar',
            ]),
        );
    }

    /**
     * The engagement figures for one window, in one query.
     *
     * New vs returning is decided by whether the client had a booking with this
     * company BEFORE the window — not by a flag on a row, which would be wrong
     * for anybody whose first visit was two years ago.
     *
     * @return array<string, int|float>
     */
    private function engagement(string $fromSql, string $toSql): array
    {
        [$scope, $params] = $this->ctx->scopeClause('b');

        $row = Db::first(
            "SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE b.confirmed_at IS NOT NULL) AS confirmed,
                COUNT(*) FILTER (WHERE b.status = 'COMPLETED') AS completed,
                COUNT(*) FILTER (WHERE b.status = 'NO_SHOW') AS no_shows,
                COUNT(*) FILTER (WHERE b.status = 'CANCELLED') AS cancelled,
                COUNT(*) FILTER (WHERE b.status = 'RESCHEDULED') AS rescheduled,
                COUNT(DISTINCT b.contact_uuid) FILTER (WHERE b.contact_uuid IS NOT NULL) AS clients,
                -- Nobody booked with us before this window.
                COUNT(DISTINCT b.contact_uuid) FILTER (
                    WHERE b.contact_uuid IS NOT NULL
                      AND NOT EXISTS (
                        SELECT 1 FROM " . BookingService::TABLE . ' prior
                         WHERE prior.cmp_id = b.cmp_id
                           AND prior.contact_uuid = b.contact_uuid
                           AND prior.starts_at < :from
                      )
                ) AS new_clients,
                -- Completed an appointment and has another one after it.
                COUNT(*) FILTER (
                    WHERE b.status = \'COMPLETED\'
                      AND EXISTS (
                        SELECT 1 FROM ' . BookingService::TABLE . " later
                         WHERE later.cmp_id = b.cmp_id
                           AND later.contact_uuid = b.contact_uuid
                           AND later.contact_uuid IS NOT NULL
                           AND later.starts_at > b.starts_at
                           AND later.status <> 'CANCELLED'
                      )
                ) AS rebooked
             FROM " . BookingService::TABLE . ' b
            WHERE ' . $scope . ' AND b.starts_at >= :from AND b.starts_at < :to',
            $params + ['from' => $fromSql, 'to' => $toSql],
        ) ?? [];

        $total = (int) ($row['total'] ?? 0);
        $completed = (int) ($row['completed'] ?? 0);
        $noShows = (int) ($row['no_shows'] ?? 0);
        // The denominator for attendance is appointments that were supposed to
        // happen: cancelled ones were called off and never had an attendance to
        // measure. Including them makes a well-run practice look negligent.
        $expected = $completed + $noShows;

        return [
            'total'             => $total,
            'confirmed'         => (int) ($row['confirmed'] ?? 0),
            'completed'         => $completed,
            'no_shows'          => $noShows,
            'cancelled'         => (int) ($row['cancelled'] ?? 0),
            'rescheduled'       => (int) ($row['rescheduled'] ?? 0),
            'clients'           => (int) ($row['clients'] ?? 0),
            'new_clients'       => (int) ($row['new_clients'] ?? 0),
            'returning_clients' => max(0, (int) ($row['clients'] ?? 0) - (int) ($row['new_clients'] ?? 0)),
            'rebooked'          => (int) ($row['rebooked'] ?? 0),
            'confirmation_rate' => $this->percent((int) ($row['confirmed'] ?? 0), $total),
            'attendance_rate'   => $this->percent($completed, $expected),
            'no_show_rate'      => $this->percent($noShows, $expected),
            'rebook_rate'       => $this->percent((int) ($row['rebooked'] ?? 0), $completed),
        ];
    }

    /**
     * Booking started → slot selected → booked → confirmed → attended → rebooked.
     *
     * @param array<string, int|float> $current
     * @return array<string, mixed>
     */
    private function funnel(array $current): array
    {
        $booked = (int) $current['total'];

        // The top two stages are browser-side events. Where nothing reports
        // them they are marked unmeasured rather than filled in from the
        // stage below, which would draw a perfect funnel with no top.
        $started = $this->publicFunnelCount('booking_started');
        $selected = $this->publicFunnelCount('slot_selected');

        $base = $started ?? $booked;

        $stages = [
            [
                'key'        => 'booking_started',
                'label'      => 'Booking started',
                'detail'     => 'Clients who began booking',
                'count'      => $started,
                'measured'   => $started !== null,
                'unmeasured_reason' => $started === null
                    ? 'Public booking pages are not publishing funnel events for this company yet.'
                    : null,
            ],
            [
                'key'      => 'slot_selected',
                'label'    => 'Slot selected',
                'detail'   => 'Chose a date and time',
                'count'    => $selected,
                'measured' => $selected !== null,
                'unmeasured_reason' => $selected === null
                    ? 'Public booking pages are not publishing funnel events for this company yet.'
                    : null,
            ],
            ['key' => 'booking_completed', 'label' => 'Booking completed', 'detail' => 'Finished the booking', 'count' => $booked, 'measured' => true],
            ['key' => 'confirmed', 'label' => 'Confirmed', 'detail' => 'Appointment confirmed', 'count' => (int) $current['confirmed'], 'measured' => true],
            ['key' => 'attended', 'label' => 'Attended', 'detail' => 'Showed up', 'count' => (int) $current['completed'], 'measured' => true],
            ['key' => 'rebooked', 'label' => 'Rebooked', 'detail' => 'Booked again', 'count' => (int) $current['rebooked'], 'measured' => true],
        ];

        foreach ($stages as &$stage) {
            $stage['share'] = ($stage['count'] !== null && $base > 0)
                ? round($stage['count'] / $base * 100, 1)
                : null;
        }

        return ['stages' => $stages, 'base' => $base];
    }

    /**
     * A public-funnel stage count, or null when nothing reports it.
     *
     * Stored as booking metadata on the pages that publish it. Returning null
     * is the honest answer and the panel renders it as "not measured".
     */
    private function publicFunnelCount(string $stage): ?int
    {
        $count = Db::scalar(
            "SELECT COUNT(*) FROM appointment_booking_metadata m
               JOIN " . BookingService::TABLE . " b ON b.booking_uuid = m.booking_uuid
              WHERE m.cmp_id = :cmp AND m.kind = 'source_detail'
                -- jsonb_exists(), not the `?` operator: PDO reads a bare ? as a
                -- positional placeholder and refuses to mix it with named ones.
                AND jsonb_exists(m.payload, :stage)
                AND b.starts_at >= :from AND b.starts_at < :to",
            [
                'cmp'   => $this->ctx->cmpId,
                'stage' => $stage,
                'from'  => $this->period->fromSql(),
                'to'    => $this->period->toSql(),
            ],
        );

        return ((int) $count) > 0 ? (int) $count : null;
    }

    /**
     * @param array<string, mixed> $performance
     * @return array<string, mixed>
     */
    private function reminderPanel(array $performance): array
    {
        $messaging = new MessagingClient();

        $channels = [];
        foreach ($performance['channels'] as $channel) {
            $channels[] = $channel + [
                'label' => match ($channel['channel']) {
                    'whatsapp' => 'WhatsApp',
                    'sms'      => 'SMS',
                    'email'    => 'Email',
                    'voice'    => 'Voice via Receptionist',
                    default    => ucfirst((string) $channel['channel']),
                },
            ];
        }

        return [
            'configured'        => $messaging->configured(),
            'unavailable_reason' => $messaging->configured() ? null : $messaging->unavailableMessage(),
            'channels'          => $channels,
            'outcomes'          => array_map(static fn (array $o) => $o + [
                'label' => match ($o['outcome']) {
                    'confirmed'   => 'Confirmed after reminder',
                    'rescheduled' => 'Rescheduled',
                    'cancelled'   => 'Cancelled early',
                    default       => 'No action',
                },
            ], $performance['outcomes']),
            'total'             => $performance['total'],
            // What the outcome numbers do and do not claim.
            'attribution_note'  => 'Attributed to the most recent reminder sent before the client acted.',
        ];
    }

    /**
     * The no-show rate week by week, for the sparkline.
     *
     * @return list<array{week: string, rate: float, no_shows: int, expected: int}>
     */
    private function noShowTrend(): array
    {
        $rows = Db::all(
            "SELECT date_trunc('week', starts_at) AS week,
                    COUNT(*) FILTER (WHERE status = 'NO_SHOW') AS no_shows,
                    COUNT(*) FILTER (WHERE status IN ('NO_SHOW', 'COMPLETED')) AS expected
               FROM " . BookingService::TABLE . '
              WHERE cmp_id = :cmp AND starts_at >= :from AND starts_at < :to
              GROUP BY 1
              ORDER BY 1',
            [
                'cmp'  => $this->ctx->cmpId,
                'from' => Clock::sql($this->period->from->modify('-56 days')),
                'to'   => $this->period->toSql(),
            ],
        );

        return array_map(fn (array $row) => [
            'week'     => substr((string) $row['week'], 0, 10),
            'no_shows' => (int) $row['no_shows'],
            'expected' => (int) $row['expected'],
            'rate'     => $this->percent((int) $row['no_shows'], (int) $row['expected']),
        ], $rows);
    }

    /**
     * Who is ready for today and who is not.
     *
     * @return array<string, mixed>
     */
    private function todaysPreparation(): array
    {
        $settings = $this->settings();
        $zone = Clock::zone($settings['timezone']);
        $now = Clock::now();
        $dayStart = Clock::startOfLocalDay($now, $zone);
        $dayEnd = $dayStart->modify('+1 day');

        $row = Db::first(
            "SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (
                    WHERE s.form_uuid IS NOT NULL
                      AND NOT EXISTS (
                        SELECT 1 FROM appointment_booking_metadata m
                         WHERE m.booking_uuid = b.booking_uuid AND m.kind = 'form_answers'
                      )
                ) AS forms_pending,
                COUNT(*) FILTER (
                    WHERE s.deposit_required = TRUE
                      AND (b.payment_status IS NULL OR b.payment_status <> 'paid')
                ) AS deposits_pending,
                COUNT(*) FILTER (WHERE b.status = 'PENDING') AS unconfirmed
             FROM " . BookingService::TABLE . ' b
             JOIN appointment_services s ON s.service_uuid = b.service_uuid
            WHERE b.cmp_id = :cmp
              AND b.starts_at >= :from AND b.starts_at < :to
              AND b.status IN (\'PENDING\', \'CONFIRMED\', \'ARRIVED\')',
            ['cmp' => $this->ctx->cmpId, 'from' => Clock::sql($dayStart), 'to' => Clock::sql($dayEnd)],
        ) ?? [];

        $total = (int) ($row['total'] ?? 0);
        $formsPending = (int) ($row['forms_pending'] ?? 0);
        $depositsPending = (int) ($row['deposits_pending'] ?? 0);
        $unconfirmed = (int) ($row['unconfirmed'] ?? 0);

        // Ready means nothing outstanding. A client can be short of two things
        // at once, so this is not total minus the sum of the columns.
        $notReady = (int) (Db::scalar(
            "SELECT COUNT(*) FROM " . BookingService::TABLE . " b
               JOIN appointment_services s ON s.service_uuid = b.service_uuid
              WHERE b.cmp_id = :cmp
                AND b.starts_at >= :from AND b.starts_at < :to
                AND b.status IN ('PENDING', 'CONFIRMED', 'ARRIVED')
                AND (
                    b.status = 'PENDING'
                    OR (s.deposit_required = TRUE AND (b.payment_status IS NULL OR b.payment_status <> 'paid'))
                    OR (
                        s.form_uuid IS NOT NULL
                        AND NOT EXISTS (
                            SELECT 1 FROM appointment_booking_metadata m
                             WHERE m.booking_uuid = b.booking_uuid AND m.kind = 'form_answers'
                        )
                    )
                )",
            ['cmp' => $this->ctx->cmpId, 'from' => Clock::sql($dayStart), 'to' => Clock::sql($dayEnd)],
        ) ?? 0);

        $ready = max(0, $total - $notReady);

        return [
            'total'  => $total,
            'items'  => [
                ['key' => 'ready', 'label' => 'Ready for appointment', 'count' => $ready, 'share' => $this->percent($ready, $total), 'tone' => 'success'],
                ['key' => 'forms_pending', 'label' => 'Forms pending', 'count' => $formsPending, 'share' => $this->percent($formsPending, $total), 'tone' => 'warning'],
                ['key' => 'unconfirmed', 'label' => 'Not confirmed', 'count' => $unconfirmed, 'share' => $this->percent($unconfirmed, $total), 'tone' => 'warning'],
                ['key' => 'deposit_pending', 'label' => 'Deposit pending', 'count' => $depositsPending, 'share' => $this->percent($depositsPending, $total), 'tone' => 'danger'],
            ],
        ];
    }

    /**
     * A handful of client appointment profiles.
     *
     * The appointment-shaped facts only: how many times they have been, what
     * they usually book, which slot they prefer. Names and contact details come
     * from what the booking captured, and the canonical record is Contacts' —
     * the UI links out to it.
     *
     * @return list<array<string, mixed>>
     */
    private function clientProfiles(): array
    {
        $rows = Db::all(
            "SELECT b.contact_uuid,
                    MAX(b.client_name) AS client_name,
                    MAX(b.client_phone) AS client_phone,
                    MAX(b.client_email) AS client_email,
                    COUNT(*) AS appointments,
                    COUNT(*) FILTER (WHERE b.status = 'COMPLETED') AS completed,
                    COUNT(*) FILTER (WHERE b.status = 'NO_SHOW') AS no_shows,
                    MAX(b.starts_at) AS last_appointment,
                    MIN(b.created_at) AS first_seen,
                    (array_agg(s.name ORDER BY b.starts_at DESC))[1] AS usual_service,
                    mode() WITHIN GROUP (ORDER BY EXTRACT(DOW FROM b.starts_at)) AS usual_dow,
                    mode() WITHIN GROUP (ORDER BY EXTRACT(HOUR FROM b.starts_at)) AS usual_hour
               FROM " . BookingService::TABLE . ' b
               JOIN appointment_services s ON s.service_uuid = b.service_uuid
              WHERE b.cmp_id = :cmp AND b.contact_uuid IS NOT NULL
              GROUP BY b.contact_uuid
              ORDER BY appointments DESC, last_appointment DESC
              LIMIT 6',
            ['cmp' => $this->ctx->cmpId],
        );

        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

        return array_map(static function (array $row) use ($days): array {
            $lastAppointment = Clock::parse((string) $row['last_appointment']);
            $hour = (int) $row['usual_hour'];

            return [
                'contact_uuid'    => (string) $row['contact_uuid'],
                'label'           => trim((string) $row['client_name']) ?: 'Client',
                'phone'           => (string) $row['client_phone'],
                'email'           => (string) $row['client_email'],
                'appointments'    => (int) $row['appointments'],
                'completed'       => (int) $row['completed'],
                'no_shows'        => (int) $row['no_shows'],
                'segment'         => (int) $row['appointments'] > 1 ? 'returning' : 'new',
                'usual_service'   => $row['usual_service'],
                'preferred_slot'  => ($days[(int) $row['usual_dow']] ?? '') . ', ' . sprintf('%d:00', $hour),
                'last_appointment' => $lastAppointment !== null ? Clock::iso($lastAppointment) : null,
                'member_since'    => substr((string) $row['first_seen'], 0, 10),
            ];
        }, $rows);
    }
}
