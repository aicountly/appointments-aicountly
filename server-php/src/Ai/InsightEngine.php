<?php

declare(strict_types=1);

namespace Aicountly\Api\Ai;

use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\Settings;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

/**
 * Appointments' own insight engine.
 *
 * ## The shape of every insight
 *
 * A rule fires on figures this product computed. The rule produces a title, a
 * reason, the records behind it, and ONE action that opens a screen where a
 * person decides. Only then — and only if a model is configured — is the model
 * asked to rephrase the wording.
 *
 * So `origin` is `rules` or `ai`, and it refers to THE PROSE ONLY. The
 * arithmetic, the thresholds, the records and the ranking are this product's in
 * both cases. That distinction is on the card in the UI, because a reader is
 * entitled to know which sentences a model wrote.
 *
 * ## Why the rules come first
 *
 * An insight engine that asks a model what is interesting produces something
 * different every refresh and cannot explain itself. One that runs named rules
 * and optionally borrows a better sentence produces the same answer twice and
 * can show its working. The second is the one a practice manager can act on.
 *
 * ## No shared agent runtime
 *
 * This is Appointments' engine, in Appointments' repository, with Appointments'
 * prompts. Calendar has its own, Books has its own. There is no central AI
 * service to defer to and there is not going to be one: the product that knows
 * what a no-show means is the product that should be reasoning about them.
 */
final class InsightEngine
{
    public const TABLE = 'appointment_ai_insights';

    /** How long a generated insight stays good for. */
    private const TTL_MINUTES = 30;

    public function __construct(private readonly Context $ctx)
    {
    }

    /**
     * Insights for one dashboard.
     *
     * Cached per company, surface and period: an insight costs a model call and
     * a dashboard is refreshed far more often than the numbers under it move.
     *
     * @param string               $surface  overview | live | capacity | client_experience | intelligence
     * @param array<string, mixed> $signals  the figures the dashboard already computed
     * @return list<array<string, mixed>>
     */
    public function forSurface(string $surface, array $signals, string $periodKey = ''): array
    {
        $cached = $this->cached($surface, $periodKey);
        if ($cached !== []) {
            return $cached;
        }

        $fired = $this->evaluate($surface, $signals);
        if ($fired === []) {
            return [];
        }

        $useModel = Settings::featureEnabled($this->ctx, 'AI') && AiClient::isAvailable();
        $out = [];

        foreach ($fired as $insight) {
            $origin = 'rules';

            if ($useModel) {
                $narrated = AiClient::narrate($insight['task'], $insight['grounding']);
                if ($narrated['ok']) {
                    $insight['body'] = (string) $narrated['text'];
                    $origin = 'ai';
                }
            }

            $stored = $this->store($surface, $periodKey, $insight, $origin);
            if ($stored !== null) {
                $out[] = $stored;
            }
        }

        return $out;
    }

    /**
     * The rules. Each returns null or a complete insight.
     *
     * Every threshold in here is a literal somebody can read and argue with.
     * That is the design.
     *
     * @param array<string, mixed> $s the dashboard's own figures
     * @return list<array<string, mixed>>
     */
    private function evaluate(string $surface, array $s): array
    {
        $fired = [];

        foreach ($this->rulesFor($surface) as $rule) {
            $insight = $rule($s);
            if ($insight !== null) {
                $fired[] = $insight;
            }
        }

        // At most three. A dashboard with six suggestions has none, because
        // nobody reads past the second.
        usort($fired, static fn (array $a, array $b) => ($b['weight'] ?? 0) <=> ($a['weight'] ?? 0));

        return array_slice($fired, 0, 3);
    }

    /** @return list<callable(array<string, mixed>): ?array<string, mixed>> */
    private function rulesFor(string $surface): array
    {
        return match ($surface) {
            'overview'          => [$this->peakDemandRule(), $this->unconfirmedRule(), $this->waitlistGapRule()],
            'live'              => [$this->runningLateRule(), $this->calendarFailureRule()],
            'capacity'          => [$this->unusedCapacityRule(), $this->utilisationImbalanceRule()],
            'client_experience' => [$this->reminderChannelRule(), $this->noShowFactorRule()],
            'intelligence'      => [$this->growthRule(), $this->serviceDemandRule()],
            default             => [],
        };
    }

    // -----------------------------------------------------------------------
    // Overview
    // -----------------------------------------------------------------------

    private function peakDemandRule(): callable
    {
        return function (array $s): ?array {
            $peak = $s['peak_hour'] ?? null;
            if (!is_array($peak) || (int) ($peak['bookings'] ?? 0) < 3) {
                return null;
            }

            $matches = (int) ($s['waitlist_matches_tomorrow'] ?? 0);

            return [
                'rule_key' => 'peak_demand_window',
                'weight'   => 70,
                'title'    => sprintf('%s is your busiest period.', (string) $peak['label']),
                'body'     => $matches > 0
                    ? sprintf(
                        '%d waitlisted %s match a slot that has come free.',
                        $matches,
                        $matches === 1 ? 'client' : 'clients',
                    )
                    : sprintf('%d appointments fall in that window.', (int) $peak['bookings']),
                'evidence' => [
                    ['kind' => 'period', 'label' => (string) $peak['label'], 'note' => $peak['bookings'] . ' appointments'],
                ],
                'rule_detail' => ['peak_bookings' => (int) $peak['bookings'], 'threshold' => 3],
                'action'   => $matches > 0
                    ? ['route' => 'waitlist', 'label' => 'View matching clients', 'params' => []]
                    : ['route' => 'capacity', 'label' => 'Open capacity', 'params' => []],
                'task'     => 'Say when the business is busiest and what could be done with that, in two sentences.',
                'grounding' => ['peak' => $peak, 'waitlist_matches' => $matches],
            ];
        };
    }

    private function unconfirmedRule(): callable
    {
        return function (array $s): ?array {
            $count = (int) ($s['unconfirmed'] ?? 0);
            $total = (int) ($s['today_total'] ?? 0);

            // Two is a phone call, not an insight. A fifth of the day is a
            // pattern worth surfacing.
            if ($count < 3 || ($total > 0 && $count / $total < 0.15)) {
                return null;
            }

            return [
                'rule_key' => 'unconfirmed_backlog',
                'weight'   => 80,
                'title'    => sprintf('%d appointments are still unconfirmed.', $count),
                'body'     => 'Unconfirmed appointments are the single strongest no-show signal this product tracks.',
                'evidence' => [['kind' => 'bookings', 'label' => $count . ' unconfirmed', 'note' => 'today']],
                'rule_detail' => ['unconfirmed' => $count, 'of_total' => $total, 'threshold' => 3],
                'action'   => ['route' => 'appointments', 'label' => 'Review unconfirmed', 'params' => ['status' => 'PENDING']],
                'task'     => 'Point out how many appointments are unconfirmed and why that matters, in two sentences.',
                'grounding' => ['unconfirmed' => $count, 'today_total' => $total],
            ];
        };
    }

    private function waitlistGapRule(): callable
    {
        return function (array $s): ?array {
            $waiting = (int) ($s['waitlist_waiting'] ?? 0);
            $slots = (int) ($s['available_slots'] ?? 0);

            if ($waiting < 1 || $slots < 1) {
                return null;
            }

            return [
                'rule_key' => 'waitlist_meets_gap',
                'weight'   => 75,
                'title'    => sprintf(
                    '%d %s waiting while %d %s open today.',
                    $waiting,
                    $waiting === 1 ? 'client is' : 'clients are',
                    $slots,
                    $slots === 1 ? 'slot sits' : 'slots sit',
                ),
                'body'     => 'Offering a waiting client an open slot fills the gap and shortens the list at once.',
                'evidence' => [
                    ['kind' => 'waitlist', 'label' => $waiting . ' waiting'],
                    ['kind' => 'slots', 'label' => $slots . ' open today'],
                ],
                'rule_detail' => ['waiting' => $waiting, 'open_slots' => $slots],
                'action'   => ['route' => 'waitlist', 'label' => 'Offer a slot', 'params' => []],
                'task'     => 'Note that there are people waiting and slots open, in two sentences.',
                'grounding' => ['waiting' => $waiting, 'open_slots' => $slots],
            ];
        };
    }

    // -----------------------------------------------------------------------
    // Live operations
    // -----------------------------------------------------------------------

    private function runningLateRule(): callable
    {
        return function (array $s): ?array {
            $delayed = (int) ($s['delayed'] ?? 0);
            if ($delayed < 2) {
                return null;
            }

            return [
                'rule_key' => 'clinic_running_late',
                'weight'   => 85,
                'title'    => sprintf('%d appointments are running late.', $delayed),
                'body'     => 'Later appointments will slip unless somebody absorbs the delay.',
                'evidence' => [['kind' => 'bookings', 'label' => $delayed . ' delayed']],
                'rule_detail' => ['delayed' => $delayed, 'threshold' => 2],
                'action'   => ['route' => 'live', 'label' => 'Open live operations', 'params' => []],
                'task'     => 'Say how many appointments are late and what it means for the rest of the day, in two sentences.',
                'grounding' => ['delayed' => $delayed, 'waiting' => (int) ($s['waiting'] ?? 0)],
            ];
        };
    }

    private function calendarFailureRule(): callable
    {
        return function (array $s): ?array {
            $failed = (int) ($s['calendar_failures'] ?? 0);
            if ($failed < 1) {
                return null;
            }

            return [
                'rule_key' => 'calendar_write_failed',
                'weight'   => 95,
                'title'    => sprintf(
                    '%d %s no entry on anyone\'s calendar.',
                    $failed,
                    $failed === 1 ? 'appointment has' : 'appointments have',
                ),
                // Deliberately not rephrased by a model — see forSurface(). The
                // wording matters and the weight puts it first.
                'body'     => 'The bookings are held here and still block their slots, but nobody will see them in their diary until the calendar entry is retried.',
                'evidence' => [['kind' => 'bookings', 'label' => $failed . ' without a calendar entry']],
                'rule_detail' => ['failed' => $failed],
                'action'   => ['route' => 'appointments', 'label' => 'Retry calendar sync', 'params' => ['calendar' => 'failed']],
                'task'     => 'State that some bookings have no calendar entry and need retrying, in one sentence.',
                'grounding' => ['failed' => $failed],
            ];
        };
    }

    // -----------------------------------------------------------------------
    // Capacity
    // -----------------------------------------------------------------------

    private function unusedCapacityRule(): callable
    {
        return function (array $s): ?array {
            $unusedHours = (float) ($s['unused_hours'] ?? 0);
            if ($unusedHours < 4) {
                return null;
            }

            return [
                'rule_key' => 'unused_capacity',
                'weight'   => 70,
                'title'    => sprintf('%s hours of capacity went unbooked.', rtrim(rtrim(number_format($unusedHours, 1), '0'), '.')),
                'body'     => 'Those hours were open and nobody was in them.',
                'evidence' => [['kind' => 'capacity', 'label' => number_format($unusedHours, 1) . ' hours unused']],
                'rule_detail' => ['unused_hours' => $unusedHours, 'threshold' => 4],
                'action'   => ['route' => 'waitlist', 'label' => 'Offer slots to the waitlist', 'params' => []],
                'task'     => 'Describe the unused capacity and one thing that could fill it, in two sentences.',
                'grounding' => ['unused_hours' => $unusedHours, 'utilisation' => $s['utilisation'] ?? null],
            ];
        };
    }

    private function utilisationImbalanceRule(): callable
    {
        return function (array $s): ?array {
            $team = $s['team_utilisation'] ?? [];
            if (!is_array($team) || count($team) < 2) {
                return null;
            }

            $rates = array_map(static fn (array $m) => (float) ($m['utilisation'] ?? 0), $team);
            $highest = max($rates);
            $lowest = min($rates);

            // A 30-point spread across a team is a rota problem, not noise.
            if ($highest - $lowest < 30) {
                return null;
            }

            $busiest = $team[array_search($highest, $rates, true)] ?? [];
            $quietest = $team[array_search($lowest, $rates, true)] ?? [];

            return [
                'rule_key' => 'team_utilisation_spread',
                'weight'   => 60,
                'title'    => 'Work is unevenly spread across the team.',
                'body'     => sprintf(
                    '%s is at %s%% while %s is at %s%%.',
                    (string) ($busiest['label'] ?? 'One practitioner'),
                    number_format($highest, 0),
                    (string) ($quietest['label'] ?? 'another'),
                    number_format($lowest, 0),
                ),
                'evidence' => [
                    ['kind' => 'staff', 'label' => (string) ($busiest['label'] ?? '—'), 'note' => number_format($highest, 0) . '%'],
                    ['kind' => 'staff', 'label' => (string) ($quietest['label'] ?? '—'), 'note' => number_format($lowest, 0) . '%'],
                ],
                'rule_detail' => ['spread' => round($highest - $lowest, 1), 'threshold' => 30],
                'action'   => ['route' => 'team', 'label' => 'Review availability', 'params' => []],
                'task'     => 'Note the imbalance across the team without naming a client, in two sentences.',
                'grounding' => ['team' => array_slice($team, 0, 8)],
            ];
        };
    }

    // -----------------------------------------------------------------------
    // Client experience
    // -----------------------------------------------------------------------

    private function reminderChannelRule(): callable
    {
        return function (array $s): ?array {
            $channels = $s['reminder_channels'] ?? [];
            if (!is_array($channels) || $channels === []) {
                return null;
            }

            $best = null;
            foreach ($channels as $channel) {
                // Fewer than 20 messages is not a pattern, it is a fortnight.
                if ((int) ($channel['total'] ?? 0) < 20) {
                    continue;
                }
                if ($best === null || (float) $channel['delivered_rate'] > (float) $best['delivered_rate']) {
                    $best = $channel;
                }
            }

            if ($best === null || (float) $best['delivered_rate'] < 80) {
                return null;
            }

            return [
                'rule_key' => 'reminder_channel_performance',
                'weight'   => 55,
                'title'    => sprintf(
                    '%s reminders are reaching clients most reliably.',
                    ucfirst((string) $best['channel']),
                ),
                'body'     => sprintf(
                    '%s%% of %d %s messages were delivered.',
                    number_format((float) $best['delivered_rate'], 1),
                    (int) $best['total'],
                    (string) $best['channel'],
                ),
                'evidence' => [[
                    'kind'  => 'channel',
                    'label' => ucfirst((string) $best['channel']),
                    'note'  => number_format((float) $best['delivered_rate'], 1) . '% delivered',
                ]],
                'rule_detail' => ['channel' => $best['channel'], 'delivered_rate' => $best['delivered_rate'], 'minimum_volume' => 20],
                'action'   => ['route' => 'reminders', 'label' => 'Review reminder rules', 'params' => []],
                'task'     => 'Report which reminder channel is performing best on delivery, in two sentences. Do not claim it causes attendance.',
                'grounding' => ['channels' => $channels],
            ];
        };
    }

    private function noShowFactorRule(): callable
    {
        return function (array $s): ?array {
            $factors = $s['no_show_factors'] ?? [];
            $noShows = (int) ($s['no_show_count'] ?? 0);

            // Below this the shares are arithmetic on a handful of rows and
            // would read as a finding.
            if (!is_array($factors) || $factors === [] || $noShows < 5) {
                return null;
            }

            $top = $factors[0];

            return [
                'rule_key' => 'no_show_leading_factor',
                'weight'   => 65,
                'title'    => sprintf(
                    '%s was present in %s%% of no-shows.',
                    (string) $top['label'],
                    number_format((float) $top['share'], 0),
                ),
                'body'     => 'Present in, not the cause of — but it is the indicator worth acting on first.',
                'evidence' => [[
                    'kind'  => 'factor',
                    'label' => (string) $top['label'],
                    'note'  => $top['count'] . ' of ' . $noShows . ' no-shows',
                ]],
                'rule_detail' => ['factor' => $top['key'], 'share' => $top['share'], 'minimum_no_shows' => 5],
                'action'   => ['route' => 'client_experience', 'label' => 'Open no-show intelligence', 'params' => []],
                'task'     => 'Report the most common indicator among no-shows. Say clearly it is a correlation, not a cause. Two sentences.',
                'grounding' => ['factors' => array_slice($factors, 0, 5), 'no_shows' => $noShows],
            ];
        };
    }

    // -----------------------------------------------------------------------
    // Intelligence
    // -----------------------------------------------------------------------

    private function growthRule(): callable
    {
        return function (array $s): ?array {
            $change = $s['appointments_change_pct'] ?? null;
            if (!is_numeric($change) || abs((float) $change) < 5) {
                return null;
            }

            $up = (float) $change > 0;

            return [
                'rule_key' => 'appointment_volume_trend',
                'weight'   => 60,
                'title'    => sprintf(
                    'Appointments are %s %s%% on the previous period.',
                    $up ? 'up' : 'down',
                    number_format(abs((float) $change), 1),
                ),
                'body'     => $up
                    ? 'Check that capacity is keeping up with the extra demand.'
                    : 'Worth looking at which services and sources fell away.',
                'evidence' => [[
                    'kind'  => 'period',
                    'label' => (string) ($s['period_label'] ?? 'This period'),
                    'note'  => ($up ? '+' : '') . number_format((float) $change, 1) . '%',
                ]],
                'rule_detail' => ['change_pct' => $change, 'threshold' => 5],
                'action'   => ['route' => 'intelligence', 'label' => 'Open growth', 'params' => []],
                'task'     => 'Summarise the change in appointment volume against the previous period, in two sentences.',
                'grounding' => [
                    'change_pct'   => $change,
                    'appointments' => $s['appointments'] ?? null,
                    'top_services' => array_slice((array) ($s['top_services'] ?? []), 0, 5),
                ],
            ];
        };
    }

    private function serviceDemandRule(): callable
    {
        return function (array $s): ?array {
            $services = $s['top_services'] ?? [];
            if (!is_array($services) || $services === []) {
                return null;
            }

            $growing = null;
            foreach ($services as $service) {
                if ((int) ($service['bookings'] ?? 0) < 10) {
                    continue;
                }
                if ($growing === null || (float) ($service['growth'] ?? 0) > (float) ($growing['growth'] ?? 0)) {
                    $growing = $service;
                }
            }

            if ($growing === null || (float) ($growing['growth'] ?? 0) < 10) {
                return null;
            }

            return [
                'rule_key' => 'fastest_growing_service',
                'weight'   => 50,
                'title'    => sprintf('%s is growing fastest.', (string) $growing['name']),
                'body'     => sprintf(
                    'Up %s%% on %d bookings. Consider adding slots for it.',
                    number_format((float) $growing['growth'], 0),
                    (int) $growing['bookings'],
                ),
                'evidence' => [[
                    'kind'  => 'service',
                    'label' => (string) $growing['name'],
                    'note'  => '+' . number_format((float) $growing['growth'], 0) . '%',
                ]],
                'rule_detail' => ['service' => $growing['name'], 'growth' => $growing['growth'], 'minimum_bookings' => 10],
                'action'   => ['route' => 'services', 'label' => 'Open services', 'params' => []],
                'task'     => 'Name the fastest-growing service and suggest one action, in two sentences.',
                'grounding' => ['services' => array_slice($services, 0, 6)],
            ];
        };
    }

    // -----------------------------------------------------------------------
    // Storage
    // -----------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function cached(string $surface, string $periodKey): array
    {
        $rows = Db::all(
            'SELECT * FROM ' . self::TABLE . '
              WHERE cmp_id = :cmp AND bo_id = :bo AND surface = :surface
                AND period_key = :period
                AND expires_at > :now AND dismissed_at IS NULL
              ORDER BY generated_at DESC',
            [
                'cmp'    => $this->ctx->cmpId,
                'bo'     => $this->ctx->boId,
                'surface' => $surface,
                'period' => $periodKey,
                'now'    => Clock::sql(Clock::now()),
            ],
        );

        return array_map([$this, 'shape'], $rows);
    }

    /**
     * @param array<string, mixed> $insight
     * @return array<string, mixed>|null
     */
    private function store(string $surface, string $periodKey, array $insight, string $origin): ?array
    {
        $expiresAt = Clock::now()->modify('+' . self::TTL_MINUTES . ' minutes');

        try {
            // The dedupe index means one insight per rule per period. A refresh
            // updates the wording and the expiry rather than stacking a second
            // copy of the same observation.
            $row = Db::first(
                'INSERT INTO ' . self::TABLE . ' (
                    insight_uuid, cmp_id, bo_id, surface, rule_key, title, body,
                    evidence, rule_detail, action_route, action_params, action_label,
                    origin, period_key, expires_at
                 ) VALUES (
                    :uuid, :cmp, :bo, :surface, :rule, :title, :body,
                    :evidence, :detail, :route, :params, :label,
                    :origin, :period, :expires
                 )
                 ON CONFLICT (cmp_id, bo_id, surface, rule_key, period_key)
                 DO UPDATE SET
                    title = EXCLUDED.title, body = EXCLUDED.body,
                    evidence = EXCLUDED.evidence, rule_detail = EXCLUDED.rule_detail,
                    action_route = EXCLUDED.action_route, action_params = EXCLUDED.action_params,
                    action_label = EXCLUDED.action_label, origin = EXCLUDED.origin,
                    generated_at = NOW(), expires_at = EXCLUDED.expires_at,
                    dismissed_at = NULL
                 RETURNING *',
                [
                    'uuid'     => Uuid::v4(),
                    'cmp'      => $this->ctx->cmpId,
                    'bo'       => $this->ctx->boId,
                    'surface'  => $surface,
                    'rule'     => (string) $insight['rule_key'],
                    'title'    => (string) $insight['title'],
                    'body'     => (string) ($insight['body'] ?? ''),
                    'evidence' => json_encode($insight['evidence'] ?? [], JSON_UNESCAPED_UNICODE),
                    'detail'   => json_encode($insight['rule_detail'] ?? [], JSON_UNESCAPED_UNICODE),
                    'route'    => $insight['action']['route'] ?? null,
                    'params'   => json_encode($insight['action']['params'] ?? [], JSON_UNESCAPED_UNICODE),
                    'label'    => $insight['action']['label'] ?? null,
                    'origin'   => $origin,
                    'period'   => $periodKey,
                    'expires'  => Clock::sql($expiresAt),
                ],
            );
        } catch (\Throwable $e) {
            error_log('[appointments-ai] could not store insight ' . $insight['rule_key'] . ': ' . $e->getMessage());

            return null;
        }

        return $row === null ? null : $this->shape($row);
    }

    public function dismiss(string $insightUuid, string $actorUuid): bool
    {
        return Db::run(
            'UPDATE ' . self::TABLE . '
                SET dismissed_at = :now, dismissed_by_uuid = :actor
              WHERE insight_uuid = :id AND cmp_id = :cmp AND dismissed_at IS NULL',
            [
                'now'   => Clock::sql(Clock::now()),
                'actor' => $actorUuid,
                'id'    => $insightUuid,
                'cmp'   => $this->ctx->cmpId,
            ],
        )->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shape(array $row): array
    {
        return [
            'insight_uuid' => (string) $row['insight_uuid'],
            'surface'      => (string) $row['surface'],
            'rule_key'     => (string) $row['rule_key'],
            'title'        => (string) $row['title'],
            'body'         => (string) $row['body'],
            'evidence'     => Db::jsonColumn($row['evidence'] ?? null),
            'rule_detail'  => Db::jsonColumn($row['rule_detail'] ?? null),
            'action'       => $row['action_route'] === null ? null : [
                'route'  => (string) $row['action_route'],
                'label'  => (string) ($row['action_label'] ?? 'Open'),
                'params' => Db::jsonColumn($row['action_params'] ?? null),
            ],
            // The one field the UI must not lose: whether a model wrote the
            // prose. The numbers were always ours.
            'origin'       => (string) $row['origin'],
            'generated_at' => Clock::iso(Clock::parse((string) $row['generated_at']) ?? Clock::now()),
        ];
    }
}
