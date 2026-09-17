<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Support\Clock;

/**
 * "Is today going well?" as a number you can take apart.
 *
 * ## Why a score at all
 *
 * A practice manager wants one glance. Six separate percentages do not give
 * them one; they give them six things to reconcile while a waiting room fills
 * up.
 *
 * ## Why this one is not magic
 *
 * It starts at 100 and subtracts named, countable penalties. Every component
 * returned says what it measured, how many, and how many points it cost. The
 * arithmetic is in this file and the dashboard shows the breakdown, so a
 * manager who disagrees with the score can see exactly which part they
 * disagree with — and change it, because the weights are constants here and not
 * a model's opinion.
 *
 * No language model is involved in producing this. The AI engine may be asked
 * to write a sentence about a score it was handed; it never computes one.
 */
final class ScheduleHealthService
{
    /**
     * Component => [points at worst, label, what it means].
     *
     * The weights say what the business cares about, in order: people not
     * turning up costs more than a clinic running slightly late, and a
     * practitioner who has not started their first appointment is the loudest
     * signal of all because everything behind them is already late.
     *
     * @var array<string, array{max: int, label: string}>
     */
    public const COMPONENTS = [
        'no_shows'          => ['max' => 25, 'label' => 'No-shows'],
        'major_delays'      => ['max' => 20, 'label' => 'Appointments running more than 15 minutes late'],
        'minor_delays'      => ['max' => 10, 'label' => 'Appointments running a few minutes late'],
        'unconfirmed'       => ['max' => 15, 'label' => 'Appointments nobody has confirmed'],
        'unfilled_gaps'     => ['max' => 15, 'label' => 'Gaps in the day nobody is in'],
        'calendar_failures' => ['max' => 15, 'label' => 'Bookings whose calendar entry failed to write'],
    ];

    private const MINOR_DELAY_MINUTES = 5;
    private const MAJOR_DELAY_MINUTES = 15;

    public function __construct(private readonly Context $ctx)
    {
    }

    /**
     * @return array{
     *     score: int,
     *     band: string,
     *     appointments: int,
     *     components: list<array{key: string, label: string, count: int, share: float, penalty: int}>,
     *     on_time_share: float
     * }
     */
    public function today(): array
    {
        $settings = Settings::for($this->ctx);
        $zone = Clock::zone($settings['timezone']);
        $now = Clock::now();
        $dayStart = Clock::startOfLocalDay($now, $zone);
        $dayEnd = $dayStart->modify('+1 day');

        $row = Db::first(
            "SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE status = 'NO_SHOW') AS no_shows,
                COUNT(*) FILTER (WHERE status = 'PENDING' AND starts_at > :now) AS unconfirmed,
                COUNT(*) FILTER (WHERE calendar_sync_state = 'failed') AS calendar_failures,
                -- Started late: the appointment began more than N minutes after
                -- it was due. Measured from what actually happened, not guessed.
                COUNT(*) FILTER (
                    WHERE started_at IS NOT NULL
                      AND started_at > starts_at + (INTERVAL '1 minute' * :major)
                ) AS major_delays,
                COUNT(*) FILTER (
                    WHERE started_at IS NOT NULL
                      AND started_at > starts_at + (INTERVAL '1 minute' * :minor)
                      AND started_at <= starts_at + (INTERVAL '1 minute' * :major)
                ) AS minor_delays,
                -- Due to have started and has not: the loudest live signal.
                COUNT(*) FILTER (
                    WHERE status IN ('CONFIRMED', 'ARRIVED')
                      AND starts_at + (INTERVAL '1 minute' * :major) < :now
                      AND started_at IS NULL
                ) AS overdue,
                COUNT(*) FILTER (WHERE status = 'COMPLETED') AS completed,
                COUNT(*) FILTER (
                    WHERE started_at IS NOT NULL
                      AND started_at <= starts_at + (INTERVAL '1 minute' * :minor)
                ) AS on_time
             FROM " . BookingService::TABLE . '
             WHERE cmp_id = :cmp AND starts_at >= :from AND starts_at < :to',
            [
                'cmp'   => $this->ctx->cmpId,
                'from'  => Clock::sql($dayStart),
                'to'    => Clock::sql($dayEnd),
                'now'   => Clock::sql($now),
                'minor' => self::MINOR_DELAY_MINUTES,
                'major' => self::MAJOR_DELAY_MINUTES,
            ],
        ) ?? [];

        $total = max(0, (int) ($row['total'] ?? 0));

        // An empty day is not an unhealthy day. Scoring zero appointments out
        // of zero as a failure is how a Monday morning dashboard tells a clinic
        // it is in trouble before it opens.
        if ($total === 0) {
            return [
                'score'         => 100,
                'band'          => 'clear',
                'appointments'  => 0,
                'components'    => [],
                'on_time_share' => 100.0,
            ];
        }

        $gaps = $this->unfilledGaps($dayStart, $dayEnd);

        $measured = [
            'no_shows'          => (int) ($row['no_shows'] ?? 0),
            // An appointment overdue to start is counted with the major
            // delays: by the time somebody is 15 minutes past due and has not
            // begun, the effect on the rest of the day is the same.
            'major_delays'      => (int) ($row['major_delays'] ?? 0) + (int) ($row['overdue'] ?? 0),
            'minor_delays'      => (int) ($row['minor_delays'] ?? 0),
            'unconfirmed'       => (int) ($row['unconfirmed'] ?? 0),
            'unfilled_gaps'     => $gaps,
            'calendar_failures' => (int) ($row['calendar_failures'] ?? 0),
        ];

        $score = 100;
        $components = [];

        foreach (self::COMPONENTS as $key => $meta) {
            $count = $measured[$key];
            if ($count === 0) {
                continue;
            }

            // The penalty scales with the SHARE of the day affected, capped at
            // the component's maximum. Three no-shows out of five is a bad day;
            // three out of eighty is a Tuesday.
            $share = $count / $total;
            $penalty = (int) round(min($meta['max'], $meta['max'] * min(1.0, $share * 2)));

            $score -= $penalty;
            $components[] = [
                'key'     => $key,
                'label'   => $meta['label'],
                'count'   => $count,
                'share'   => round($share * 100, 1),
                'penalty' => $penalty,
            ];
        }

        $score = max(0, min(100, $score));
        $started = (int) ($row['on_time'] ?? 0) + $measured['minor_delays'] + $measured['major_delays'];

        return [
            'score'        => $score,
            'band'         => match (true) {
                $score >= 85 => 'good',
                $score >= 65 => 'watch',
                default      => 'attention',
            },
            'appointments'  => $total,
            'components'    => $components,
            'on_time_share' => $started > 0 ? round((int) ($row['on_time'] ?? 0) / $started * 100, 1) : 100.0,
        ];
    }

    /**
     * Bookable stretches inside the working day with nothing in them.
     *
     * Counted from this product's own bookings against its own working-hours
     * pattern, not from Calendar: a gap is "nobody is booked", and something
     * personal in a practitioner's own diary neither fills a gap nor should be
     * visible on a practice dashboard.
     *
     * A gap has to be long enough to sell. Twelve minutes between two
     * appointments is a breather, not lost revenue, so only stretches that
     * could hold the shortest active service count.
     */
    private function unfilledGaps(\DateTimeImmutable $dayStart, \DateTimeImmutable $dayEnd): int
    {
        $shortest = (int) (Db::scalar(
            'SELECT MIN(duration_minutes) FROM appointment_services WHERE cmp_id = :cmp AND is_active = TRUE',
            ['cmp' => $this->ctx->cmpId],
        ) ?? 0);

        if ($shortest <= 0) {
            return 0;
        }

        $rows = Db::all(
            'SELECT member_uuid, starts_at, ends_at
               FROM ' . BookingService::TABLE . "
              WHERE cmp_id = :cmp
                AND member_uuid IS NOT NULL
                AND starts_at >= :from AND starts_at < :to
                AND status IN ('PENDING', 'CONFIRMED', 'ARRIVED', 'IN_PROGRESS', 'COMPLETED')
              ORDER BY member_uuid, starts_at",
            ['cmp' => $this->ctx->cmpId, 'from' => Clock::sql($dayStart), 'to' => Clock::sql($dayEnd)],
        );

        $byMember = [];
        foreach ($rows as $row) {
            $byMember[(string) $row['member_uuid']][] = $row;
        }

        $gaps = 0;
        foreach ($byMember as $bookings) {
            for ($i = 1, $n = count($bookings); $i < $n; $i++) {
                $previousEnd = Clock::parse((string) $bookings[$i - 1]['ends_at']);
                $nextStart = Clock::parse((string) $bookings[$i]['starts_at']);
                if ($previousEnd === null || $nextStart === null) {
                    continue;
                }
                if (Clock::minutesBetween($previousEnd, $nextStart) >= $shortest) {
                    $gaps++;
                }
            }
        }

        return $gaps;
    }
}
