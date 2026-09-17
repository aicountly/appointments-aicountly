<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Ai\AiClient;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\AvailabilityService;
use Aicountly\Api\Domain\Settings;
use Aicountly\Api\Domain\SlotHoldService;
use Aicountly\Api\Http;
use Aicountly\Api\Support\Clock;

/**
 * Find a slot, hold it, let it go.
 *
 * ## The one thing this controller must never do
 *
 * Offer a slot it could not verify. When Calendar cannot be read the response
 * carries `calendar_available: false` and an empty slot list, and the booking
 * endpoints refuse for the same reason. There is no "best effort" mode where
 * rule-derived slots are shown with a warning, because somebody would book one
 * and two people would arrive at three o'clock.
 */
final class AvailabilityController extends Controller
{
    public static function slots(): void
    {
        [, $ctx] = self::enter('appointments.booking.view');

        $serviceUuid = trim((string) (Http::param('service_uuid') ?? ''));
        if ($serviceUuid === '') {
            Http::validationFailed('service_uuid is required.');
        }

        $settings = Settings::for($ctx);
        $zone = Clock::zone($settings['timezone']);
        $now = Clock::now();

        $from = Clock::parse(Http::param('from')) ?? $now;
        $to = Clock::parse(Http::param('to')) ?? Clock::startOfLocalDay($from, $zone)->modify('+8 days');

        try {
            $result = (new AvailabilityService($ctx))->findSlots([
                'service_uuid' => $serviceUuid,
                'member_uuid'  => Http::param('member_uuid'),
                'bo_id'        => Http::intParam('location_bo_id'),
                'from'         => $from,
                'to'           => $to,
                'daypart'      => Http::param('daypart'),
                'limit'        => Http::intParam('limit', 200),
                'ignore_booking_uuid' => Http::param('ignore_booking_uuid'),
            ]);
        } catch (\Throwable $e) {
            Http::notFound('That service does not exist.');
        }

        // 503 rather than 200-with-a-flag when the calendar is the problem: a
        // client that only checks the status code should not conclude "no
        // availability" from "we could not look".
        if (!$result['calendar_available']) {
            Http::error(503, 'calendar_unavailable', (string) $result['calendar_message'], [
                'retryable' => true,
                'slots'     => [],
            ]);
        }

        Http::data([
            'slots'    => $result['slots'],
            'window'   => $result['window'],
            'service'  => [
                'service_uuid'     => (string) $result['service']['service_uuid'],
                'name'             => (string) $result['service']['name'],
                'duration_minutes' => (int) $result['service']['duration_minutes'],
                'modes'            => $result['service']['modes'],
                'deposit_required' => (bool) $result['service']['deposit_required'],
            ],
            'members'  => array_map(static fn (array $m) => [
                'member_uuid'   => (string) $m['member_uuid'],
                'display_label' => $m['display_label'],
                'job_title'     => $m['job_title'],
            ], $result['members']),
            'calendar_available' => true,
        ]);
    }

    /**
     * Natural-language slot search: "45 minutes with a senior consultant
     * tomorrow afternoon".
     *
     * The model maps the phrase onto OUR filters, from a vocabulary WE supply
     * — see AiClient::interpretSlotSearch. It cannot invent a service, a
     * practitioner or a date, so the worst a hostile phrase achieves is a
     * search that finds nothing. With no model configured the endpoint still
     * works: it falls back to keyword matching against the service names, which
     * handles "tax consultation tomorrow" perfectly well.
     */
    public static function interpret(): void
    {
        [, $ctx] = self::enter('appointments.booking.view');

        $phrase = trim((string) (Http::param('q') ?? ''));
        if ($phrase === '') {
            Http::validationFailed('q is required.');
        }

        $services = Db::all(
            'SELECT service_uuid, name, duration_minutes FROM appointment_services
              WHERE cmp_id = :cmp AND is_active = TRUE ORDER BY sort_order, name',
            ['cmp' => $ctx->cmpId],
        );

        $members = Db::all(
            'SELECT member_uuid, display_label, job_title FROM appointment_team_members
              WHERE cmp_id = :cmp AND is_active = TRUE AND accepts_bookings = TRUE',
            ['cmp' => $ctx->cmpId],
        );

        $aiUsed = false;
        $filters = [];

        if (Settings::featureEnabled($ctx, 'AI') && AiClient::isAvailable()) {
            $vocabulary = [
                'service' => array_map(static fn (array $s) => (string) $s['name'], $services),
                'when'    => ['today', 'tomorrow', 'this_week', 'next_week'],
                'daypart' => ['morning', 'afternoon', 'evening', 'any'],
            ];
            if ($members !== []) {
                $vocabulary['practitioner'] = array_values(array_filter(array_map(
                    static fn (array $m) => trim((string) ($m['display_label'] ?? '')),
                    $members,
                )));
            }

            $interpreted = AiClient::interpretSlotSearch($phrase, $vocabulary);
            if ($interpreted['ok']) {
                $filters = $interpreted['filters'];
                $aiUsed = $filters !== [];
            }
        }

        if ($filters === []) {
            $filters = self::keywordFallback($phrase, $services);
        }

        $resolved = self::resolveFilters($ctx, $filters, $services, $members);

        Http::data([
            'phrase'      => $phrase,
            'interpreted' => $resolved,
            // The UI says which it was, because "the AI understood you" and
            // "we matched a word" are different promises.
            'origin'      => $aiUsed ? 'ai' : 'keywords',
        ]);
    }

    /**
     * Claim a slot for a few minutes while somebody finishes booking it.
     *
     * See SlotHoldService for why the hold lives in Appointments and not in
     * Calendar.
     */
    public static function hold(): void
    {
        [$auth, $ctx] = self::enter('appointments.booking.create');

        $body = Http::body();

        $result = (new SlotHoldService($ctx))->place([
            'service_uuid'   => (string) ($body['service_uuid'] ?? ''),
            'member_uuid'    => $body['member_uuid'] ?? null,
            'resource_uuids' => (array) ($body['resource_uuids'] ?? []),
            'starts_at'      => (string) ($body['starts_at'] ?? ''),
            'ends_at'        => (string) ($body['ends_at'] ?? ''),
            'bo_id'          => (int) ($body['bo_id'] ?? $ctx->boId),
            'held_by'        => self::holdOwner($auth),
            'held_by_kind'   => $auth->kind,
        ]);

        if (!$result['ok']) {
            self::fail($result['code'], $result['reason']);
        }

        Http::data($result['hold'], 201);
    }

    public static function releaseHold(string $holdUuid): void
    {
        [$auth, $ctx] = self::enter('appointments.booking.create');

        $released = (new SlotHoldService($ctx))->release($holdUuid, self::holdOwner($auth));

        if (!$released) {
            Http::notFound('That hold has already expired or was never yours.');
        }

        Http::data(['released' => true]);
    }

    // -----------------------------------------------------------------------

    /**
     * What to do when there is no model: match service names, and look for the
     * words people actually type.
     *
     * @param list<array<string, mixed>> $services
     * @return array<string, string>
     */
    private static function keywordFallback(string $phrase, array $services): array
    {
        $lower = mb_strtolower($phrase);
        $filters = [];

        foreach ($services as $service) {
            $name = mb_strtolower((string) $service['name']);
            if ($name !== '' && str_contains($lower, $name)) {
                $filters['service'] = (string) $service['name'];
                break;
            }
        }

        // Longest match first, so "next week" is not read as "week".
        foreach (['next week' => 'next_week', 'this week' => 'this_week', 'tomorrow' => 'tomorrow', 'today' => 'today'] as $needle => $value) {
            if (str_contains($lower, $needle)) {
                $filters['when'] = $value;
                break;
            }
        }

        foreach (['morning', 'afternoon', 'evening'] as $daypart) {
            if (str_contains($lower, $daypart)) {
                $filters['daypart'] = $daypart;
                break;
            }
        }

        return $filters;
    }

    /**
     * Turn interpreted labels into ids and dates this product can use.
     *
     * A label that matches nothing is dropped rather than guessed at, which is
     * what keeps a hostile or garbled phrase from producing a confident search
     * for the wrong thing.
     *
     * @param array<string, string>      $filters
     * @param list<array<string, mixed>> $services
     * @param list<array<string, mixed>> $members
     * @return array<string, mixed>
     */
    private static function resolveFilters(
        \Aicountly\Api\Context $ctx,
        array $filters,
        array $services,
        array $members,
    ): array {
        $settings = Settings::for($ctx);
        $zone = Clock::zone($settings['timezone']);
        $now = Clock::now();
        $today = Clock::startOfLocalDay($now, $zone);

        $out = [
            'service_uuid' => null,
            'service_name' => null,
            'member_uuid'  => null,
            'member_label' => null,
            'daypart'      => $filters['daypart'] ?? null,
            'from'         => Clock::iso($now),
            'to'           => Clock::iso($today->modify('+8 days')),
            'when'         => $filters['when'] ?? null,
        ];

        if (isset($filters['service'])) {
            foreach ($services as $service) {
                if (mb_strtolower((string) $service['name']) === mb_strtolower($filters['service'])) {
                    $out['service_uuid'] = (string) $service['service_uuid'];
                    $out['service_name'] = (string) $service['name'];
                    break;
                }
            }
        }

        if (isset($filters['practitioner'])) {
            foreach ($members as $member) {
                if (mb_strtolower(trim((string) ($member['display_label'] ?? ''))) === mb_strtolower($filters['practitioner'])) {
                    $out['member_uuid'] = (string) $member['member_uuid'];
                    $out['member_label'] = (string) $member['display_label'];
                    break;
                }
            }
        }

        [$from, $to] = match ($filters['when'] ?? null) {
            'today'     => [$now, $today->modify('+1 day')],
            'tomorrow'  => [$today->modify('+1 day'), $today->modify('+2 days')],
            'this_week' => [$now, $today->modify('+7 days')],
            'next_week' => [$today->modify('+7 days'), $today->modify('+14 days')],
            default     => [$now, $today->modify('+8 days')],
        };

        $out['from'] = Clock::iso($from);
        $out['to'] = Clock::iso($to);

        if ($out['daypart'] === 'any') {
            $out['daypart'] = null;
        }

        return $out;
    }
}
