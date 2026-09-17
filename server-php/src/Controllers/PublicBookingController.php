<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\AvailabilityService;
use Aicountly\Api\Domain\BookingService;
use Aicountly\Api\Domain\ClientProfileService;
use Aicountly\Api\Domain\Settings;
use Aicountly\Api\Domain\SlotHoldService;
use Aicountly\Api\Http;
use Aicountly\Api\Idempotency;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

/**
 * Public booking — the only endpoints in this product with no session.
 *
 * A client following a booking link has no AICOUNTLY account and never will,
 * so these routes resolve no Auth and carry their own protections instead:
 *
 *  - RATE LIMITING, per hashed client fingerprint, per scope. Availability is
 *    cheap and generous; booking is not.
 *  - NO INTERNAL IDS IN, NO INTERNAL IDS OUT beyond the uuids a booking flow
 *    genuinely needs. The company is resolved from the slug, never from a
 *    parameter — otherwise a public endpoint would take a company id from a
 *    stranger.
 *  - REVALIDATION before every confirmation, through Calendar, with the same
 *    refusal-on-unknown rule as everywhere else.
 *  - IDEMPOTENCY, always, because this is the caller most likely to be on a bad
 *    connection and least able to recover from a double booking.
 *
 * ## What a public caller may never do
 *
 * Choose a company, book a service that is not online-bookable, book outside
 * the booking rules, override anything, name a booking source, or see another
 * client's booking. Each of those is enforced here rather than trusted.
 */
final class PublicBookingController extends Controller
{
    /** scope => [requests, window seconds] */
    private const LIMITS = [
        'page'      => [60, 300],
        'slots'     => [90, 300],
        'hold'      => [12, 300],
        'book'      => [6, 900],
        'manage'    => [20, 900],
    ];

    /**
     * GET /api/public/pages/{slug}
     *
     * Everything a booking page needs to render, and nothing more.
     */
    public static function page(string $slug): void
    {
        $page = self::resolvePage($slug);
        $ctx = Context::forCompany((int) $page['cmp_id'], (int) $page['bo_id']);
        self::rateLimit('page', $slug);

        $services = self::bookableServices($ctx, $page);

        Http::data([
            'page' => [
                'slug'      => (string) $page['slug'],
                'kind'      => (string) $page['kind'],
                'headline'  => (string) $page['headline'],
                'intro'     => (string) $page['intro'],
                'brand_colour' => $page['brand_colour'],
                'logo_url'  => $page['logo_url'],
                'terms'     => (string) $page['terms'],
                'timezone'  => (string) $page['timezone'],
                'locale'    => (string) $page['locale'],
                'require_client_phone' => (bool) $page['require_client_phone'],
                'require_client_email' => (bool) $page['require_client_email'],
            ],
            'services' => $services,
            // A client's own clock, so a page loaded from another country shows
            // times they can act on.
            'viewer_timezone_hint' => (string) $page['timezone'],
            'payments' => [
                // Said plainly rather than hidden, because a service that
                // requires a deposit cannot be booked here while Pay is off
                // and the client is entitled to know why.
                'enabled' => Settings::featureEnabled($ctx, 'PAY'),
                'message' => Settings::featureEnabled($ctx, 'PAY')
                    ? null
                    : 'Online payment is not available yet, so services that require a deposit cannot be booked here.',
            ],
        ]);
    }

    /** GET /api/public/pages/{slug}/slots */
    public static function slots(string $slug): void
    {
        $page = self::resolvePage($slug);
        $ctx = Context::forCompany((int) $page['cmp_id'], (int) $page['bo_id']);
        self::rateLimit('slots', $slug);

        $serviceUuid = trim((string) (Http::param('service_uuid') ?? ''));
        $service = self::assertBookableService($ctx, $page, $serviceUuid);

        $zone = Clock::zone((string) $page['timezone']);
        $now = Clock::now();
        $from = Clock::parse(Http::param('from')) ?? $now;
        $to = Clock::parse(Http::param('to')) ?? Clock::startOfLocalDay($from, $zone)->modify('+14 days');

        if ($from < $now) {
            $from = $now;
        }

        $result = (new AvailabilityService($ctx))->findSlots([
            'service_uuid' => $serviceUuid,
            'member_uuid'  => self::publicMemberFilter($page),
            'bo_id'        => (int) $page['bo_id'] > 0 ? (int) $page['bo_id'] : null,
            'from'         => $from,
            'to'           => $to,
            'daypart'      => Http::param('daypart'),
            'limit'        => 300,
        ]);

        if (!$result['calendar_available']) {
            // The same refusal as everywhere else. A public page that offered
            // rule-derived slots here would take bookings nobody could honour.
            Http::error(503, 'calendar_unavailable', 'Availability is temporarily unavailable. Please try again in a moment.', [
                'retryable' => true,
                'slots'     => [],
            ]);
        }

        // Practitioner uuids are stripped unless the page is a staff page. A
        // business page has no reason to publish who works there through its
        // availability response.
        $exposeMember = (string) $page['kind'] === 'staff';

        Http::data([
            'slots' => array_map(static fn (array $slot) => [
                'starts_at'  => $slot['starts_at'],
                'ends_at'    => $slot['ends_at'],
                'local_time' => $slot['local_time'],
                'local_date' => $slot['local_date'],
                'daypart'    => $slot['daypart'],
                'duration_minutes' => $slot['duration_minutes'],
                'member_uuid' => $exposeMember ? $slot['member_uuid'] : null,
            ], $result['slots']),
            'service' => [
                'service_uuid'     => (string) $service['service_uuid'],
                'name'             => (string) $service['name'],
                'duration_minutes' => (int) $service['duration_minutes'],
            ],
            'window'   => $result['window'],
            'timezone' => (string) $page['timezone'],
        ]);
    }

    /** POST /api/public/pages/{slug}/holds */
    public static function hold(string $slug): void
    {
        $page = self::resolvePage($slug);
        $ctx = Context::forCompany((int) $page['cmp_id'], (int) $page['bo_id']);
        $fingerprint = self::rateLimit('hold', $slug);

        $body = Http::body();
        $serviceUuid = trim((string) ($body['service_uuid'] ?? ''));
        self::assertBookableService($ctx, $page, $serviceUuid);

        $result = (new SlotHoldService($ctx))->place([
            'service_uuid' => $serviceUuid,
            'member_uuid'  => self::publicMemberChoice($page, $body),
            'starts_at'    => (string) ($body['starts_at'] ?? ''),
            'ends_at'      => (string) ($body['ends_at'] ?? ''),
            'bo_id'        => (int) $page['bo_id'],
            // The hold belongs to this browser, identified by the same hashed
            // fingerprint the rate limiter uses. Not a cookie, not a session.
            'held_by'      => $fingerprint,
            'held_by_kind' => 'public',
        ]);

        if (!$result['ok']) {
            self::fail($result['code'], $result['reason']);
        }

        Http::data($result['hold'], 201);
    }

    /**
     * POST /api/public/pages/{slug}/bookings
     *
     * Where a public booking actually happens.
     */
    public static function book(string $slug): void
    {
        $page = self::resolvePage($slug);
        $ctx = Context::forCompany((int) $page['cmp_id'], (int) $page['bo_id']);
        $fingerprint = self::rateLimit('book', $slug);

        // Always keyed, whether or not the caller sent one: this is the caller
        // most likely to double-submit.
        $key = Idempotency::fromRequest()
            ?? ('public-' . substr(hash('sha256', $fingerprint . '|' . (string) file_get_contents('php://input')), 0, 40));

        $replay = Idempotency::replay($ctx, 'public.book:' . $slug, $key);
        if ($replay !== null) {
            Http::json($replay['status'], $replay['body']);
        }

        $body = Http::body();
        $serviceUuid = trim((string) ($body['service_uuid'] ?? ''));
        $service = self::assertBookableService($ctx, $page, $serviceUuid);

        $errors = [];
        $name = trim((string) ($body['client_name'] ?? ''));
        $phone = trim((string) ($body['client_phone'] ?? ''));
        $email = trim((string) ($body['client_email'] ?? ''));

        if ($name === '') {
            $errors['client_name'] = 'Please tell us your name.';
        }
        if ((bool) $page['require_client_phone'] && $phone === '') {
            $errors['client_phone'] = 'Please give us a phone number.';
        }
        if ((bool) $page['require_client_email'] && $email === '') {
            $errors['client_email'] = 'Please give us an email address.';
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['client_email'] = 'That does not look like an email address.';
        }
        if ($phone === '' && $email === '') {
            $errors['client_phone'] = 'A phone number or an email address is needed so we can confirm.';
        }
        if (!(bool) ($body['accept_terms'] ?? false) && trim((string) $page['terms']) !== '') {
            $errors['accept_terms'] = 'Please accept the terms to continue.';
        }

        if ($errors !== []) {
            Http::validationFailed('Please check the details.', $errors);
        }

        // A deposit-required service cannot be confirmed without Pay. Refused
        // rather than booked-and-hoped, because confirming it would tell the
        // client a payment had been arranged.
        if ((bool) $service['deposit_required'] && !Settings::featureEnabled($ctx, 'PAY')) {
            Http::error(503, 'payment_unavailable', 'This service needs a deposit and online payment is not available yet. '
                . 'Please contact us to book it.', ['retryable' => false]);
        }

        $answers = [];
        if (!empty($service['form_uuid'])) {
            $answers = FormsController::validateAnswers($ctx, (string) $service['form_uuid'], (array) ($body['answers'] ?? []));
        }

        // A public caller is its own kind of Auth: no permissions, no company
        // choice, and a booking source of ONLINE_BOOKING that it cannot change.
        $auth = self::publicAuth($fingerprint);

        $result = (new BookingService($ctx, $auth))->create([
            'service_uuid' => $serviceUuid,
            'member_uuid'  => self::publicMemberChoice($page, $body),
            'starts_at'    => $body['starts_at'] ?? null,
            'ends_at'      => $body['ends_at'] ?? null,
            'bo_id'        => (int) $page['bo_id'],
            'client_name'  => $name,
            'client_phone' => $phone,
            'client_email' => $email,
            'mode'         => $body['mode'] ?? null,
            'timezone'     => (string) $page['timezone'],
            'notes'        => $body['notes'] ?? '',
            'hold_uuid'    => $body['hold_uuid'] ?? null,
            'hold_owner'   => $fingerprint,
            'form_answers' => $answers,
        ]);

        if (!$result['ok']) {
            self::fail($result['code'], $result['reason']);
        }

        $booking = $result['booking'] ?? [];
        $bookingUuid = (string) ($booking['booking_uuid'] ?? '');

        // Funnel evidence, so the Client Experience dashboard can report a real
        // top-of-funnel rather than inferring one.
        self::recordFunnel($ctx, $bookingUuid, $body);

        Audit::recordPublic($ctx, 'booking.created_public', 'booking', $bookingUuid, [
            'slug'      => (string) $page['slug'],
            'reference' => $booking['reference'] ?? null,
        ]);

        $startsAt = Clock::parse((string) ($booking['starts_at'] ?? ''));
        $zone = Clock::zone((string) $page['timezone']);

        // Deliberately minimal. A public confirmation carries what the client
        // needs to recognise and manage their own appointment — never
        // practitioner uuids, internal references or anything about the
        // business's diary.
        $payload = [
            'data' => [
                'booking' => [
                    'booking_uuid' => $bookingUuid,
                    'reference'    => (string) ($booking['reference'] ?? ''),
                    'status'       => (string) ($booking['status'] ?? ''),
                    'starts_at'    => $booking['starts_at'] ?? null,
                    'local_time'   => $startsAt !== null ? $startsAt->setTimezone($zone)->format('D j M Y, g:i A') : null,
                    'timezone'     => (string) $page['timezone'],
                    'service_name' => (string) $service['name'],
                    'mode'         => (string) ($booking['mode'] ?? ''),
                    'join_url'     => $booking['connect_join_url'] ?? null,
                ],
                'message' => (string) ($booking['status'] ?? '') === 'CONFIRMED'
                    ? 'Your appointment is confirmed.'
                    : 'Your appointment has been requested and is awaiting confirmation.',
            ],
        ];

        Idempotency::remember($ctx, 'public.book:' . $slug, $key, 201, $payload);

        Http::json(201, $payload);
    }

    /**
     * GET /api/public/bookings/{bookingUuid}
     *
     * A client looking at their own appointment. The uuid is the credential —
     * it is a v4, unguessable, and was sent only to them.
     */
    public static function show(string $bookingUuid): void
    {
        self::rateLimit('manage', $bookingUuid);

        if (!Uuid::isValid($bookingUuid)) {
            Http::notFound('That appointment does not exist.');
        }

        $row = Db::first(
            'SELECT b.*, s.name AS service_name, s.duration_minutes,
                    p.timezone AS page_timezone, p.slug
               FROM ' . BookingService::TABLE . ' b
               JOIN appointment_services s ON s.service_uuid = b.service_uuid
          LEFT JOIN appointment_booking_pages p ON p.cmp_id = b.cmp_id AND p.is_published = TRUE
              WHERE b.booking_uuid = :id
              LIMIT 1',
            ['id' => $bookingUuid],
        );

        if ($row === null) {
            Http::notFound('That appointment does not exist.');
        }

        $ctx = Context::forCompany((int) $row['cmp_id'], (int) $row['bo_id']);
        $rule = (new BookingService($ctx, self::publicAuth('public')))->rulesFor([
            'cancellation_rule_uuid' => null,
        ]);

        $zone = Clock::zone((string) ($row['page_timezone'] ?? $row['timezone']));
        $startsAt = Clock::parse((string) $row['starts_at']);
        $noticeHours = (int) $rule['cancellation_notice_hours'];

        $withinNotice = $startsAt !== null
            && Clock::minutesBetween(Clock::now(), $startsAt) >= $noticeHours * 60;

        Http::data([
            'booking' => [
                'booking_uuid' => (string) $row['booking_uuid'],
                'reference'    => (string) $row['reference'],
                'status'       => (string) $row['status'],
                'starts_at'    => $startsAt !== null ? Clock::iso($startsAt) : null,
                'local_time'   => $startsAt !== null ? $startsAt->setTimezone($zone)->format('D j M Y, g:i A') : null,
                'timezone'     => $zone->getName(),
                'service_name' => (string) $row['service_name'],
                'duration_minutes' => (int) $row['duration_minutes'],
                'mode'         => (string) $row['mode'],
                'join_url'     => $row['connect_join_url'],
                'client_name'  => (string) $row['client_name'],
            ],
            'can_cancel'     => (bool) $rule['allow_client_cancellation']
                && $withinNotice
                && in_array((string) $row['status'], ['PENDING', 'CONFIRMED'], true),
            'can_reschedule' => (bool) $rule['allow_client_reschedule']
                && in_array((string) $row['status'], ['PENDING', 'CONFIRMED'], true),
            'notice_hours'   => $noticeHours,
            'booking_page'   => $row['slug'] ?? null,
        ]);
    }

    /** POST /api/public/bookings/{bookingUuid}/cancel */
    public static function cancel(string $bookingUuid): void
    {
        self::rateLimit('manage', $bookingUuid);

        if (!Uuid::isValid($bookingUuid)) {
            Http::notFound('That appointment does not exist.');
        }

        $row = Db::first(
            'SELECT cmp_id, bo_id, status, starts_at, service_uuid, contact_uuid
               FROM ' . BookingService::TABLE . ' WHERE booking_uuid = :id',
            ['id' => $bookingUuid],
        );

        if ($row === null) {
            Http::notFound('That appointment does not exist.');
        }

        $ctx = Context::forCompany((int) $row['cmp_id'], (int) $row['bo_id']);
        $auth = self::publicAuth('public');
        $service = new BookingService($ctx, $auth);

        $rule = $service->rulesFor(
            Db::first(
                'SELECT cancellation_rule_uuid FROM appointment_services WHERE service_uuid = :id AND cmp_id = :cmp',
                ['id' => $row['service_uuid'], 'cmp' => $ctx->cmpId],
            ) ?? [],
        );

        if (!(bool) $rule['allow_client_cancellation']) {
            Http::forbidden('This appointment cannot be cancelled online. Please contact us.');
        }

        $reason = trim((string) (Http::body()['reason'] ?? '')) ?: 'Cancelled by the client online';
        $result = $service->cancel($bookingUuid, $reason);

        if (!$result['ok']) {
            self::fail($result['code'], $result['reason']);
        }

        Audit::recordPublic($ctx, 'booking.cancelled_public', 'booking', $bookingUuid, ['late' => $result['late']], $reason);

        Http::data([
            'cancelled' => true,
            'message'   => $result['late']
                ? 'Your appointment has been cancelled. It was inside the notice period, so the business may be in touch.'
                : 'Your appointment has been cancelled.',
        ]);
    }

    // =======================================================================
    // Guards
    // =======================================================================

    /**
     * The published page behind a slug, or a 404.
     *
     * An unpublished page 404s rather than 403s: a stranger should not be able
     * to learn that a draft exists at an address.
     *
     * @return array<string, mixed>
     */
    private static function resolvePage(string $slug): array
    {
        $slug = strtolower(trim($slug));

        if ($slug === '' || preg_match('/^[a-z0-9-]{3,60}$/', $slug) !== 1) {
            Http::notFound('That booking page does not exist.');
        }

        $page = Db::first(
            'SELECT * FROM appointment_booking_pages WHERE lower(slug) = :slug AND is_published = TRUE',
            ['slug' => $slug],
        );

        if ($page === null) {
            Http::notFound('That booking page does not exist.');
        }

        $ctx = Context::forCompany((int) $page['cmp_id'], (int) $page['bo_id']);

        if (!Settings::featureEnabled($ctx, 'PUBLIC_BOOKING')) {
            Http::notFound('That booking page is not available.');
        }

        $page['service_uuids'] = Db::jsonColumn($page['service_uuids'] ?? null);

        return $page;
    }

    /**
     * Services this page may offer.
     *
     * Active, online-bookable, and on the page's own list — three separate
     * checks, because a service being active does not make it public and a
     * service being on a page does not make it bookable.
     *
     * @param array<string, mixed> $page
     * @return list<array<string, mixed>>
     */
    private static function bookableServices(Context $ctx, array $page): array
    {
        $rows = Db::all(
            'SELECT s.*, f.name AS form_name,
                    (SELECT COUNT(*) FROM appointment_form_fields ff WHERE ff.form_uuid = s.form_uuid) AS form_fields
               FROM appointment_services s
          LEFT JOIN appointment_forms f ON f.form_uuid = s.form_uuid
              WHERE s.cmp_id = :cmp
                AND s.is_active = TRUE
                AND s.is_bookable_online = TRUE
                AND (:bo = 0 OR s.bo_id = :bo OR s.bo_id = 0)
              ORDER BY s.sort_order, s.name',
            ['cmp' => $ctx->cmpId, 'bo' => (int) $page['bo_id']],
        );

        $allowed = $page['service_uuids'];
        $pageService = $page['service_uuid'] ?? null;

        $out = [];
        foreach ($rows as $row) {
            $uuid = (string) $row['service_uuid'];

            if ($pageService !== null && $uuid !== (string) $pageService) {
                continue;
            }
            if ($allowed !== [] && !in_array($uuid, $allowed, true)) {
                continue;
            }

            $out[] = [
                'service_uuid'     => $uuid,
                'name'             => (string) $row['name'],
                'description'      => (string) $row['description'],
                'duration_minutes' => (int) $row['duration_minutes'],
                'modes'            => Db::jsonColumn($row['modes'] ?? null),
                'price_minor'      => $row['price_minor'] === null ? null : (int) $row['price_minor'],
                'currency'         => (string) $row['currency'],
                'deposit_required' => (bool) $row['deposit_required'],
                'deposit_minor'    => $row['deposit_minor'] === null ? null : (int) $row['deposit_minor'],
                'form'             => empty($row['form_uuid']) ? null : [
                    'form_uuid'  => (string) $row['form_uuid'],
                    'name'       => (string) ($row['form_name'] ?? ''),
                    'field_count' => (int) $row['form_fields'],
                ],
                'min_notice_minutes'   => (int) $row['min_notice_minutes'],
                'booking_horizon_days' => (int) $row['booking_horizon_days'],
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private static function assertBookableService(Context $ctx, array $page, string $serviceUuid): array
    {
        if (!Uuid::isValid($serviceUuid)) {
            Http::validationFailed('Please choose a service.');
        }

        foreach (self::bookableServices($ctx, $page) as $service) {
            if ($service['service_uuid'] === $serviceUuid) {
                return $service + ['form_uuid' => $service['form'] === null ? null : $service['form']['form_uuid']];
            }
        }

        // 404, not 403: whether a service exists but is private is not a
        // stranger's business.
        Http::notFound('That service is not available on this page.');
    }

    /**
     * Which practitioner a public caller may ask for.
     *
     * A staff page is pinned to its own member. A business page lets the client
     * express a preference only if the uuid is one that actually delivers the
     * service, and otherwise falls back to "any available" rather than
     * refusing — a stale link should not break a booking.
     *
     * @param array<string, mixed> $page
     * @param array<string, mixed> $body
     */
    private static function publicMemberChoice(array $page, array $body): ?string
    {
        if ((string) $page['kind'] === 'staff') {
            return $page['member_uuid'] === null ? null : (string) $page['member_uuid'];
        }

        $requested = trim((string) ($body['member_uuid'] ?? ''));
        if (!Uuid::isValid($requested)) {
            return null;
        }

        $eligible = Db::first(
            'SELECT 1 FROM appointment_team_members m
              WHERE m.member_uuid = :member AND m.cmp_id = :cmp
                AND m.is_active = TRUE AND m.accepts_bookings = TRUE AND m.bookable_online = TRUE',
            ['member' => $requested, 'cmp' => (int) $page['cmp_id']],
        );

        return $eligible === null ? null : $requested;
    }

    /** @param array<string, mixed> $page */
    private static function publicMemberFilter(array $page): ?string
    {
        return (string) $page['kind'] === 'staff' && $page['member_uuid'] !== null
            ? (string) $page['member_uuid']
            : null;
    }

    /**
     * A public caller's identity: no permissions, no company choice, and a
     * booking source it cannot change.
     */
    private static function publicAuth(string $fingerprint): Auth
    {
        return Auth::forPublic($fingerprint);
    }

    /**
     * Fixed-window rate limiting against a hashed client fingerprint.
     *
     * Hashed because an IP address sitting in a product database for a year is
     * a liability with no benefit. Fixed-window rather than a token bucket
     * because it needs one row and no background process, and the protection
     * this needs is against a script, not against a determined attacker who
     * would rotate addresses anyway.
     *
     * @return string the fingerprint, which doubles as the hold owner
     */
    private static function rateLimit(string $scope, string $salt): string
    {
        [$allowed, $windowSeconds] = self::LIMITS[$scope] ?? [30, 300];

        $fingerprint = self::fingerprint($salt);
        $now = Clock::now();
        $windowStart = $now->setTimestamp((int) (floor($now->getTimestamp() / $windowSeconds) * $windowSeconds));

        try {
            $row = Db::first(
                'INSERT INTO appointment_public_rate_limits (fingerprint, scope, window_start, hits)
                 VALUES (:fp, :scope, :window, 1)
                 ON CONFLICT (fingerprint, scope, window_start)
                 DO UPDATE SET hits = appointment_public_rate_limits.hits + 1
                 RETURNING hits',
                ['fp' => $fingerprint, 'scope' => $scope, 'window' => Clock::sql($windowStart)],
            );
        } catch (\Throwable $e) {
            // A rate limiter that cannot write must not take the booking page
            // down. Logged loudly; the request proceeds.
            error_log('[public] rate limit write failed: ' . $e->getMessage());

            return $fingerprint;
        }

        if ((int) ($row['hits'] ?? 0) > $allowed) {
            $retryAfter = $windowSeconds - ($now->getTimestamp() - $windowStart->getTimestamp());
            if (PHP_SAPI !== 'cli') {
                header('Retry-After: ' . max(1, $retryAfter));
            }
            Http::error(429, 'rate_limited', 'Too many requests. Please wait a moment and try again.', [
                'retry_after_seconds' => max(1, $retryAfter),
            ]);
        }

        return $fingerprint;
    }

    /**
     * A stable, hashed identity for one client.
     *
     * The salt keeps scopes and pages independent, so hammering one booking
     * page does not lock somebody out of another.
     */
    private static function fingerprint(string $salt): string
    {
        $ip = '';
        foreach ([$_SERVER['HTTP_X_FORWARDED_FOR'] ?? '', $_SERVER['REMOTE_ADDR'] ?? ''] as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            $first = trim(explode(',', $candidate)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                $ip = $first;
                break;
            }
        }

        $agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200);

        return substr(hash('sha256', $salt . '|' . $ip . '|' . $agent), 0, 48);
    }

    /** @param array<string, mixed> $body */
    private static function recordFunnel(Context $ctx, string $bookingUuid, array $body): void
    {
        $stages = [];
        foreach (['booking_started', 'slot_selected'] as $stage) {
            if (!empty($body[$stage])) {
                $stages[$stage] = true;
            }
        }

        if ($stages === []) {
            return;
        }

        try {
            Db::run(
                'INSERT INTO appointment_booking_metadata (booking_uuid, cmp_id, kind, payload)
                 VALUES (:booking, :cmp, \'source_detail\', :payload)
                 ON CONFLICT (booking_uuid, kind) DO UPDATE
                    SET payload = appointment_booking_metadata.payload || EXCLUDED.payload, updated_at = NOW()',
                [
                    'booking' => $bookingUuid,
                    'cmp'     => $ctx->cmpId,
                    'payload' => json_encode($stages, JSON_UNESCAPED_UNICODE),
                ],
            );
        } catch (\Throwable $e) {
            error_log('[public] funnel record failed: ' . $e->getMessage());
        }
    }
}
