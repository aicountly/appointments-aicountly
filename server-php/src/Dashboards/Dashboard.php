<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Ai\InsightEngine;
use Aicountly\Api\Auth;
use Aicountly\Api\Clients\CalendarClient;
use Aicountly\Api\Clients\ManageClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\BookingService;
use Aicountly\Api\Domain\Settings;
use Aicountly\Api\Support\Clock;

/**
 * What all five dashboards share.
 *
 * ## The five are genuinely different
 *
 * Overview answers "what is happening today". Live answers "what needs me right
 * now". Capacity answers "where is there room". Client Experience answers "are
 * people booking, confirming, attending and coming back". Intelligence answers
 * "is this growing". They are different questions asked by different people at
 * different times of day, and they do not share a widget set — only this base.
 *
 * ## Each dashboard is ONE request
 *
 * A dashboard that fires fourteen requests is a dashboard that paints in
 * fourteen stages and hammers Calendar. Each view aggregates its own figures in
 * SQL here, makes at most one cross-product call, and answers once.
 *
 * ## `freshness` is not decoration
 *
 * Every panel that shows data owned by another product says so, by name. A
 * reader who can see that utilisation came from Calendar and deposits came from
 * Pay can tell which number to distrust when one of them is having a bad day.
 */
abstract class Dashboard
{
    public function __construct(
        protected readonly Context $ctx,
        protected readonly Auth $auth,
        protected readonly Period $period,
    ) {
    }

    /** @return array<string, mixed> */
    abstract public function build(): array;

    /** overview | live | capacity | client_experience | intelligence */
    abstract public function id(): string;

    // -----------------------------------------------------------------------
    // Shared shaping
    // -----------------------------------------------------------------------

    /**
     * @param list<Metric>                $metrics
     * @param array<string, mixed>        $panels
     * @param array<string, mixed>        $signals what the insight rules read
     * @return array<string, mixed>
     */
    protected function envelope(array $metrics, array $panels, array $signals = [], array $sources = []): array
    {
        return [
            'view'      => $this->id(),
            'period'    => $this->period->toArray(),
            'currency'  => Settings::for($this->ctx)['currency'],
            'metrics'   => array_map(static fn (Metric $m) => $m->toArray(), $metrics),
            'panels'    => $panels,
            'insights'  => $this->insights($signals),
            'freshness' => [
                'generated_at' => Clock::iso(Clock::now()),
                'sources'      => $sources,
            ],
        ];
    }

    /** @param array<string, mixed> $signals @return list<array<string, mixed>> */
    protected function insights(array $signals): array
    {
        if ($signals === []) {
            return [];
        }

        try {
            return (new InsightEngine($this->ctx))->forSurface($this->id(), $signals, $this->period->cacheKey());
        } catch (\Throwable $e) {
            // An insight is the least important thing on the page. It must
            // never be the reason the figures do not appear.
            error_log('[dashboard] insight generation failed for ' . $this->id() . ': ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Where each panel's data came from. Shown in the footer.
     *
     * @param array<string, string> $extra what => owner
     * @return list<array{what: string, owner: string}>
     */
    protected function sources(array $extra = []): array
    {
        $base = ['Appointments, services and policies' => 'Aicountly Appointments'];
        $out = [];

        foreach ($base + $extra as $what => $owner) {
            $out[] = ['what' => $what, 'owner' => $owner];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // Shared queries
    // -----------------------------------------------------------------------

    /**
     * Booking counts by status for a window, in one query.
     *
     * @return array<string, int>
     */
    protected function statusCounts(string $fromSql, string $toSql): array
    {
        [$scope, $params] = $this->ctx->scopeClause();

        $rows = Db::all(
            'SELECT status, COUNT(*) AS total
               FROM ' . BookingService::TABLE . '
              WHERE ' . $scope . ' AND starts_at >= :from AND starts_at < :to
              GROUP BY status',
            $params + ['from' => $fromSql, 'to' => $toSql],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        // Every status present, so callers never index into a missing key and
        // a panel never shows a gap where a zero belongs.
        foreach (array_keys(BookingService::TRANSITIONS) as $status) {
            $counts[$status] ??= 0;
        }

        $counts['TOTAL'] = array_sum(array_intersect_key(
            $counts,
            array_flip(array_keys(BookingService::TRANSITIONS)),
        ));

        return $counts;
    }

    /** Total bookings in a window, whatever their status. */
    protected function bookingCount(string $fromSql, string $toSql): int
    {
        [$scope, $params] = $this->ctx->scopeClause();

        return (int) Db::scalar(
            'SELECT COUNT(*) FROM ' . BookingService::TABLE . '
              WHERE ' . $scope . ' AND starts_at >= :from AND starts_at < :to',
            $params + ['from' => $fromSql, 'to' => $toSql],
        );
    }

    /**
     * Where bookings came from, as counts and shares.
     *
     * @return list<array{source: string, label: string, total: int, share: float}>
     */
    protected function bookingSources(string $fromSql, string $toSql): array
    {
        [$scope, $params] = $this->ctx->scopeClause();

        $rows = Db::all(
            'SELECT booking_source, COUNT(*) AS total
               FROM ' . BookingService::TABLE . '
              WHERE ' . $scope . ' AND starts_at >= :from AND starts_at < :to
              GROUP BY booking_source
              ORDER BY total DESC',
            $params + ['from' => $fromSql, 'to' => $toSql],
        );

        $total = array_sum(array_map(static fn (array $r) => (int) $r['total'], $rows));

        $out = [];
        foreach ($rows as $row) {
            $source = (string) $row['booking_source'];
            $out[] = [
                'source' => $source,
                'label'  => self::sourceLabel($source),
                'total'  => (int) $row['total'],
                'share'  => $total > 0 ? round((int) $row['total'] / $total * 100, 1) : 0.0,
            ];
        }

        return $out;
    }

    public static function sourceLabel(string $source): string
    {
        return match ($source) {
            'ONLINE_BOOKING'  => 'Online booking',
            'STAFF_BOOKING'   => 'Staff booking',
            'RECEPTIONIST'    => 'Receptionist',
            'CRM'             => 'CRM',
            'SALES'           => 'Sales',
            'POS'             => 'Point of sale',
            'API_INTEGRATION' => 'API / integration',
            'WAITLIST_OFFER'  => 'Waitlist offer',
            default           => 'Other',
        };
    }

    /**
     * The team, with the labels a screen needs.
     *
     * Names come from Manage where it answers, and fall back to the
     * appointment-specific `display_label` where it does not. NOTHING IS
     * STORED: a person renamed in Manage is renamed here on the next read.
     *
     * @return list<array<string, mixed>>
     */
    protected function teamMembers(): array
    {
        $members = Db::all(
            'SELECT member_uuid, user_uuid, calendar_subscriber_uuid, display_label, job_title, timezone
               FROM appointment_team_members
              WHERE cmp_id = :cmp AND is_active = TRUE
              ORDER BY display_label NULLS LAST, created_at',
            ['cmp' => $this->ctx->cmpId],
        );

        if ($members === [] || $this->auth->isService()) {
            return $members;
        }

        $names = $this->manageMemberNames();
        foreach ($members as &$member) {
            $userUuid = (string) $member['user_uuid'];
            $member['label'] = $names[$userUuid]
                ?? (trim((string) ($member['display_label'] ?? '')) ?: 'Team member');
        }

        return $members;
    }

    /**
     * Names from Manage, keyed by user uuid.
     *
     * Optional: a Manage that is slow or down leaves the labels as the
     * appointment-side fallback rather than failing the dashboard. A name is
     * the least important thing on a capacity screen.
     *
     * @return array<string, string>
     */
    protected function manageMemberNames(): array
    {
        try {
            $result = (new ManageClient())
                ->withSession($this->auth->sesKey())
                ->companyMembers($this->ctx->cmpId);
        } catch (\Throwable) {
            return [];
        }

        if (!$result['ok']) {
            return [];
        }

        $rows = $result['body']['data'] ?? $result['body']['members'] ?? $result['body'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $uuid = trim((string) ($row['uuid_aictly'] ?? $row['user_uuid'] ?? $row['uuid'] ?? ''));
            $name = trim((string) ($row['name'] ?? $row['full_name'] ?? $row['user_name'] ?? ''));
            if ($uuid !== '' && $name !== '') {
                $out[$uuid] = $name;
            }
        }

        return $out;
    }

    protected function calendar(): CalendarClient
    {
        return new CalendarClient();
    }

    /** @return array<string, mixed> */
    protected function settings(): array
    {
        return Settings::for($this->ctx);
    }

    protected function percent(int $part, int $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : 0.0;
    }
}
