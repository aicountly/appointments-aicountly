<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Support\Clock;

/**
 * Who might not turn up, and WHY the product thinks so.
 *
 * ## No magic score
 *
 * Every point of risk here comes from a named, countable indicator with a
 * threshold written in this file. The output is a list of the indicators that
 * fired, and the dashboard shows that list. There is deliberately no percentage
 * and no "confidence": a client who is 68% likely to no-show is a number nobody
 * can act on or argue with, whereas "not confirmed, form incomplete, has missed
 * two before" tells a receptionist exactly which phone call to make.
 *
 * ## No model
 *
 * This is arithmetic. The AI engine may be asked to WRITE ABOUT what this
 * produced — see Ai/InsightEngine.php — but it never produces the indicators
 * and never supplies a figure. That separation is the whole reason the dashboard
 * can say "explainable" without it being marketing.
 *
 * ## It is a prompt, not a verdict
 *
 * Nothing here cancels, charges, refuses or deprioritises anybody. It moves a
 * row up a list of people worth ringing.
 */
final class NoShowRiskService
{
    /**
     * Indicator => [weight, human label].
     *
     * The weights are for ORDERING a worklist, nothing more. They are round
     * numbers chosen so that two soft signals do not outrank one hard one, and
     * they are in this file where anybody can see and change them.
     *
     * @var array<string, array{weight: int, label: string}>
     */
    public const INDICATORS = [
        'never_booked_before' => ['weight' => 2, 'label' => 'First appointment with you'],
        'not_confirmed'       => ['weight' => 3, 'label' => 'Has not confirmed'],
        'previous_no_show'    => ['weight' => 4, 'label' => 'Has missed an appointment before'],
        'repeat_no_show'      => ['weight' => 5, 'label' => 'Has missed more than one appointment'],
        'form_incomplete'     => ['weight' => 2, 'label' => 'Booking form not completed'],
        'recent_reschedule'   => ['weight' => 2, 'label' => 'Moved this appointment recently'],
        'long_lead_time'      => ['weight' => 1, 'label' => 'Booked a long way ahead'],
        'late_cancel_history' => ['weight' => 2, 'label' => 'Has cancelled late before'],
        'no_contact_channel'  => ['weight' => 3, 'label' => 'No phone or email to remind them on'],
        'reminders_undelivered' => ['weight' => 2, 'label' => 'Reminders could not be delivered'],
    ];

    /** Fired indicators totalling at least this are worth a receptionist's time. */
    public const AT_RISK_THRESHOLD = 5;

    /** A booking this far ahead counts as a long lead time. */
    private const LONG_LEAD_DAYS = 45;

    /** A reschedule within this many days of now is "recent". */
    private const RECENT_RESCHEDULE_DAYS = 3;

    public function __construct(private readonly Context $ctx)
    {
    }

    /**
     * Assess every upcoming booking in a window, in a handful of queries.
     *
     * One pass over the bookings and one pass over the profiles, joined in PHP.
     * The alternative — a risk query per booking — is forty queries to draw one
     * dashboard panel.
     *
     * @return list<array{
     *     booking_uuid: string, reference: string, starts_at: string,
     *     client_label: string, score: int, at_risk: bool,
     *     indicators: list<array{key: string, label: string, weight: int}>
     * }>
     */
    public function assessWindow(string $fromSql, string $toSql): array
    {
        $bookings = Db::all(
            "SELECT b.booking_uuid, b.reference, b.starts_at, b.status, b.contact_uuid,
                    b.client_name, b.client_phone, b.client_email, b.reschedule_count,
                    b.updated_at, b.service_uuid,
                    s.form_uuid,
                    (SELECT COUNT(*) FROM appointment_booking_metadata m
                      WHERE m.booking_uuid = b.booking_uuid AND m.kind = 'form_answers') AS has_form_answers,
                    (SELECT COUNT(*) FROM appointment_reminders r
                      WHERE r.booking_uuid = b.booking_uuid AND r.status IN ('failed', 'not_sent')) AS failed_reminders
               FROM " . BookingService::TABLE . ' b
               JOIN appointment_services s ON s.service_uuid = b.service_uuid
              WHERE b.cmp_id = :cmp
                AND b.starts_at >= :from AND b.starts_at < :to
                AND b.status IN (\'PENDING\', \'CONFIRMED\')
              ORDER BY b.starts_at ASC',
            ['cmp' => $this->ctx->cmpId, 'from' => $fromSql, 'to' => $toSql],
        );

        if ($bookings === []) {
            return [];
        }

        $profiles = $this->profilesFor(array_column($bookings, 'contact_uuid'));
        $now = Clock::now();
        $out = [];

        foreach ($bookings as $booking) {
            $fired = [];
            $contactUuid = trim((string) ($booking['contact_uuid'] ?? ''));
            $profile = $contactUuid !== '' ? ($profiles[$contactUuid] ?? null) : null;

            if ($booking['status'] !== 'CONFIRMED') {
                $fired[] = 'not_confirmed';
            }

            if ($profile === null || (int) $profile['total_bookings'] <= 1) {
                $fired[] = 'never_booked_before';
            } else {
                $noShows = (int) $profile['no_show_count'];
                if ($noShows >= 2) {
                    $fired[] = 'repeat_no_show';
                } elseif ($noShows === 1) {
                    $fired[] = 'previous_no_show';
                }
                if ((int) $profile['late_cancel_count'] >= 1) {
                    $fired[] = 'late_cancel_history';
                }
            }

            // A form the service asks for and the client has not filled in.
            if (!empty($booking['form_uuid']) && (int) $booking['has_form_answers'] === 0) {
                $fired[] = 'form_incomplete';
            }

            if ((int) $booking['reschedule_count'] > 0) {
                $updatedAt = Clock::parse((string) $booking['updated_at']);
                if ($updatedAt !== null && Clock::minutesBetween($updatedAt, $now) < self::RECENT_RESCHEDULE_DAYS * 1440) {
                    $fired[] = 'recent_reschedule';
                }
            }

            $startsAt = Clock::parse((string) $booking['starts_at']);
            if ($startsAt !== null && Clock::minutesBetween($now, $startsAt) > self::LONG_LEAD_DAYS * 1440) {
                $fired[] = 'long_lead_time';
            }

            if (trim((string) $booking['client_phone']) === '' && trim((string) $booking['client_email']) === '') {
                $fired[] = 'no_contact_channel';
            }

            if ((int) $booking['failed_reminders'] > 0) {
                $fired[] = 'reminders_undelivered';
            }

            $indicators = [];
            $score = 0;
            foreach ($fired as $key) {
                $meta = self::INDICATORS[$key] ?? null;
                if ($meta === null) {
                    continue;
                }
                $score += $meta['weight'];
                $indicators[] = ['key' => $key, 'label' => $meta['label'], 'weight' => $meta['weight']];
            }

            $out[] = [
                'booking_uuid' => (string) $booking['booking_uuid'],
                'reference'    => (string) $booking['reference'],
                'starts_at'    => $startsAt !== null ? Clock::iso($startsAt) : (string) $booking['starts_at'],
                'client_label' => trim((string) $booking['client_name']) ?: 'Client',
                'score'        => $score,
                'at_risk'      => $score >= self::AT_RISK_THRESHOLD,
                'indicators'   => $indicators,
            ];
        }

        // Riskiest first, then soonest — a receptionist works down this list.
        usort($out, static function (array $a, array $b): int {
            return $b['score'] <=> $a['score'] ?: strcmp($a['starts_at'], $b['starts_at']);
        });

        return $out;
    }

    /**
     * How often each indicator appeared among the bookings that actually went
     * wrong, over a period. The "top risk factors" panel.
     *
     * Presented as a share of no-shows in which the indicator was present, and
     * labelled as such. It is NOT a claim that the indicator caused the
     * no-show, and the dashboard copy says so.
     *
     * @return array{no_shows: int, factors: list<array{key: string, label: string, share: float, count: int}>}
     */
    public function factorFrequency(string $fromSql, string $toSql): array
    {
        $rows = Db::all(
            "SELECT b.booking_uuid, b.contact_uuid, b.reschedule_count, b.client_phone, b.client_email,
                    b.confirmed_at, s.form_uuid,
                    (SELECT COUNT(*) FROM appointment_booking_metadata m
                      WHERE m.booking_uuid = b.booking_uuid AND m.kind = 'form_answers') AS has_form_answers
               FROM " . BookingService::TABLE . " b
               JOIN appointment_services s ON s.service_uuid = b.service_uuid
              WHERE b.cmp_id = :cmp
                AND b.status = 'NO_SHOW'
                AND b.starts_at >= :from AND b.starts_at < :to",
            ['cmp' => $this->ctx->cmpId, 'from' => $fromSql, 'to' => $toSql],
        );

        $total = count($rows);
        if ($total === 0) {
            return ['no_shows' => 0, 'factors' => []];
        }

        $profiles = $this->profilesFor(array_column($rows, 'contact_uuid'));
        $counts = [];

        foreach ($rows as $row) {
            $contactUuid = trim((string) ($row['contact_uuid'] ?? ''));
            $profile = $contactUuid !== '' ? ($profiles[$contactUuid] ?? null) : null;

            if ($row['confirmed_at'] === null) {
                $counts['not_confirmed'] = ($counts['not_confirmed'] ?? 0) + 1;
            }
            if ($profile === null || (int) $profile['total_bookings'] <= 1) {
                $counts['never_booked_before'] = ($counts['never_booked_before'] ?? 0) + 1;
            }
            if (!empty($row['form_uuid']) && (int) $row['has_form_answers'] === 0) {
                $counts['form_incomplete'] = ($counts['form_incomplete'] ?? 0) + 1;
            }
            if ((int) $row['reschedule_count'] > 0) {
                $counts['recent_reschedule'] = ($counts['recent_reschedule'] ?? 0) + 1;
            }
            if (trim((string) $row['client_phone']) === '' && trim((string) $row['client_email']) === '') {
                $counts['no_contact_channel'] = ($counts['no_contact_channel'] ?? 0) + 1;
            }
        }

        arsort($counts);

        $factors = [];
        foreach ($counts as $key => $count) {
            $factors[] = [
                'key'   => (string) $key,
                'label' => self::INDICATORS[$key]['label'] ?? (string) $key,
                'count' => $count,
                'share' => round($count / $total * 100, 1),
            ];
        }

        return ['no_shows' => $total, 'factors' => $factors];
    }

    /**
     * @param list<mixed> $contactUuids
     * @return array<string, array<string, mixed>>
     */
    private function profilesFor(array $contactUuids): array
    {
        $unique = [];
        foreach ($contactUuids as $uuid) {
            $uuid = trim((string) ($uuid ?? ''));
            if ($uuid !== '') {
                $unique[$uuid] = true;
            }
        }
        if ($unique === []) {
            return [];
        }

        $placeholders = [];
        $params = ['cmp' => $this->ctx->cmpId];
        foreach (array_keys($unique) as $index => $uuid) {
            $key = 'c' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $uuid;
        }

        $rows = Db::all(
            'SELECT * FROM ' . ClientProfileService::TABLE . '
              WHERE cmp_id = :cmp AND contact_uuid IN (' . implode(', ', $placeholders) . ')',
            $params,
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['contact_uuid']] = $row;
        }

        return $out;
    }
}
