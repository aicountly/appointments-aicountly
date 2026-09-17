<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\Settings;
use Aicountly\Api\Http;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

/**
 * Public booking pages — the configuration side.
 *
 * ## The slug is the whole address
 *
 * `appointments.aicountly.com/b/{slug}`. No company id, no internal uuid, so a
 * booking link cannot be incremented into somebody else's business. Slugs are
 * unique across the whole deployment rather than per company, because two
 * companies cannot both own `/b/clinic`.
 *
 * ## Publishing is explicit
 *
 * A page is a draft until somebody publishes it, and an unpublished slug 404s
 * publicly. Building a booking page should not put it on the internet halfway
 * through.
 */
final class BookingPagesController extends Controller
{
    /** Reserved so a page can never shadow an app route or look like an internal path. */
    private const RESERVED_SLUGS = [
        'api', 'app', 'admin', 'auth', 'b', 'book', 'booking', 'dashboard',
        'health', 'login', 'logout', 'settings', 'static', 'assets', 'public',
    ];

    public static function index(): void
    {
        [, $ctx] = self::enter('appointments.booking.view');

        $rows = Db::all(
            'SELECT p.*, s.name AS service_name, m.display_label AS member_label
               FROM appointment_booking_pages p
          LEFT JOIN appointment_services s ON s.service_uuid = p.service_uuid
          LEFT JOIN appointment_team_members m ON m.member_uuid = p.member_uuid
              WHERE p.cmp_id = :cmp
              ORDER BY p.is_published DESC, p.kind, p.slug',
            ['cmp' => $ctx->cmpId],
        );

        Http::data([
            'pages'   => array_map([self::class, 'shape'], $rows),
            'enabled' => Settings::featureEnabled($ctx, 'PUBLIC_BOOKING'),
            'base_url' => self::publicBase(),
        ]);
    }

    public static function save(): void
    {
        [$auth, $ctx] = self::enter('appointments.booking_pages.manage');

        $body = Http::body();
        $pageUuid = trim((string) ($body['page_uuid'] ?? ''));
        $creating = !Uuid::isValid($pageUuid);

        $existing = null;
        if (!$creating) {
            $existing = Db::first(
                'SELECT * FROM appointment_booking_pages WHERE page_uuid = :id AND cmp_id = :cmp',
                ['id' => $pageUuid, 'cmp' => $ctx->cmpId],
            );
            if ($existing === null) {
                Http::notFound('That booking page does not exist.');
            }
        } else {
            $pageUuid = Uuid::v4();
        }

        $kind = strtolower(trim((string) ($body['kind'] ?? $existing['kind'] ?? 'business')));
        if (!in_array($kind, ['business', 'service', 'staff'], true)) {
            Http::validationFailed('Kind must be business, service or staff.');
        }

        $slug = self::normaliseSlug((string) ($body['slug'] ?? $existing['slug'] ?? ''));
        if ($slug === null) {
            Http::validationFailed(
                'A web address is required.',
                ['slug' => 'Use 3–60 lowercase letters, numbers and hyphens.'],
            );
        }
        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            Http::validationFailed('That web address is reserved.', ['slug' => '"' . $slug . '" cannot be used.']);
        }

        $clash = Db::first(
            'SELECT page_uuid, cmp_id FROM appointment_booking_pages WHERE lower(slug) = :slug',
            ['slug' => $slug],
        );
        if ($clash !== null && (string) $clash['page_uuid'] !== $pageUuid) {
            Http::conflict('That web address is already taken.', ['slug' => $slug]);
        }

        $serviceUuid = self::trimOrNull($body['service_uuid'] ?? ($existing['service_uuid'] ?? null));
        $memberUuid = self::trimOrNull($body['member_uuid'] ?? ($existing['member_uuid'] ?? null));

        if ($kind === 'service' && !Uuid::isValid((string) $serviceUuid)) {
            Http::validationFailed('A service page needs a service.');
        }
        if ($kind === 'staff' && !Uuid::isValid((string) $memberUuid)) {
            Http::validationFailed('A staff page needs a team member.');
        }

        $serviceUuids = [];
        foreach ((array) ($body['service_uuids'] ?? []) as $uuid) {
            if (Uuid::isValid((string) $uuid)) {
                $serviceUuids[] = (string) $uuid;
            }
        }

        $publish = array_key_exists('is_published', $body)
            ? (bool) $body['is_published']
            : (bool) ($existing['is_published'] ?? false);

        if ($publish && !Settings::featureEnabled($ctx, 'PUBLIC_BOOKING')) {
            Http::error(409, 'public_booking_disabled', 'Public booking is turned off for this company, so a page cannot be published.');
        }

        $values = [
            'slug'         => $slug,
            'kind'         => $kind,
            'service_uuid' => $kind === 'service' ? $serviceUuid : null,
            'member_uuid'  => $kind === 'staff' ? $memberUuid : null,
            'headline'     => substr(trim((string) ($body['headline'] ?? $existing['headline'] ?? '')), 0, 200),
            'intro'        => substr(trim((string) ($body['intro'] ?? $existing['intro'] ?? '')), 0, 2000),
            'brand_colour' => self::normaliseColour($body['brand_colour'] ?? ($existing['brand_colour'] ?? null)),
            'logo_url'     => self::normaliseHttpsUrl($body['logo_url'] ?? ($existing['logo_url'] ?? null)),
            'terms'        => substr(trim((string) ($body['terms'] ?? $existing['terms'] ?? '')), 0, 8000),
            'timezone'     => trim((string) ($body['timezone'] ?? $existing['timezone'] ?? '')) ?: Settings::for($ctx)['timezone'],
            'locale'       => substr(trim((string) ($body['locale'] ?? $existing['locale'] ?? 'en-IN')), 0, 16),
            'service_uuids' => $serviceUuids,
            'require_client_phone' => (bool) ($body['require_client_phone'] ?? $existing['require_client_phone'] ?? true),
            'require_client_email' => (bool) ($body['require_client_email'] ?? $existing['require_client_email'] ?? true),
            'is_published' => $publish,
            'published_at' => $publish
                ? ($existing['published_at'] ?? Clock::sql(Clock::now()))
                : null,
            'updated_at'   => Clock::sql(Clock::now()),
        ];

        if ($creating) {
            Db::insert('appointment_booking_pages', $values + [
                'page_uuid' => $pageUuid,
                'cmp_id'    => $ctx->cmpId,
                'bo_id'     => (int) ($body['bo_id'] ?? $ctx->boId),
            ], 'page_uuid');
        } else {
            Db::update('appointment_booking_pages', $values, ['page_uuid' => $pageUuid, 'cmp_id' => $ctx->cmpId]);
        }

        // Publishing is an outward-facing change, so it is audited as its own
        // action rather than folded into a generic update.
        Audit::record(
            $ctx,
            $auth,
            $creating ? 'booking_page.created' : ((bool) ($existing['is_published'] ?? false) !== $publish
                ? ($publish ? 'booking_page.published' : 'booking_page.unpublished')
                : 'booking_page.updated'),
            'booking_page',
            $pageUuid,
            $existing === null ? null : ['slug' => $existing['slug'], 'is_published' => $existing['is_published']],
            ['slug' => $slug, 'is_published' => $publish],
        );

        $row = Db::first(
            'SELECT p.*, s.name AS service_name, m.display_label AS member_label
               FROM appointment_booking_pages p
          LEFT JOIN appointment_services s ON s.service_uuid = p.service_uuid
          LEFT JOIN appointment_team_members m ON m.member_uuid = p.member_uuid
              WHERE p.page_uuid = :id AND p.cmp_id = :cmp',
            ['id' => $pageUuid, 'cmp' => $ctx->cmpId],
        ) ?? [];

        Http::data(['page' => self::shape($row)], $creating ? 201 : 200);
    }

    public static function delete(string $pageUuid): void
    {
        [$auth, $ctx] = self::enter('appointments.booking_pages.manage');

        $existing = Db::first(
            'SELECT slug, is_published FROM appointment_booking_pages WHERE page_uuid = :id AND cmp_id = :cmp',
            ['id' => $pageUuid, 'cmp' => $ctx->cmpId],
        );

        if ($existing === null) {
            Http::notFound('That booking page does not exist.');
        }

        Db::run(
            'DELETE FROM appointment_booking_pages WHERE page_uuid = :id AND cmp_id = :cmp',
            ['id' => $pageUuid, 'cmp' => $ctx->cmpId],
        );

        Audit::record($ctx, $auth, 'booking_page.deleted', 'booking_page', $pageUuid, $existing, null);

        Http::data([
            'deleted' => true,
            // Worth saying: a published link that somebody has shared or
            // bookmarked stops working the moment the page goes.
            'note'    => (bool) $existing['is_published']
                ? 'Any link to /b/' . $existing['slug'] . ' will no longer work.'
                : null,
        ]);
    }

    // -----------------------------------------------------------------------

    public static function publicBase(): string
    {
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));

        return $host === '' ? 'https://appointments.aicountly.com/b' : 'https://' . $host . '/b';
    }

    private static function normaliseSlug(string $slug): ?string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
        $slug = trim(preg_replace('/-+/', '-', $slug) ?? '', '-');

        if (strlen($slug) < 3 || strlen($slug) > 60) {
            return null;
        }

        return $slug;
    }

    private static function normaliseColour(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? strtolower($value) : null;
    }

    /**
     * An https URL or nothing.
     *
     * The logo is rendered on a public page, so an http or javascript: URL here
     * would be a mixed-content warning at best and an injection at worst.
     */
    private static function normaliseHttpsUrl(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return str_starts_with(strtolower($value), 'https://') ? $value : null;
    }

    private static function trimOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function shape(array $row): array
    {
        $slug = (string) ($row['slug'] ?? '');

        return [
            'page_uuid'    => (string) ($row['page_uuid'] ?? ''),
            'slug'         => $slug,
            'public_url'   => $slug === '' ? null : self::publicBase() . '/' . $slug,
            'kind'         => (string) ($row['kind'] ?? 'business'),
            'service'      => $row['service_uuid'] === null ? null : [
                'service_uuid' => (string) $row['service_uuid'],
                'name'         => $row['service_name'] ?? null,
            ],
            'member'       => $row['member_uuid'] === null ? null : [
                'member_uuid' => (string) $row['member_uuid'],
                'label'       => $row['member_label'] ?? null,
            ],
            'headline'     => (string) ($row['headline'] ?? ''),
            'intro'        => (string) ($row['intro'] ?? ''),
            'brand_colour' => $row['brand_colour'] ?? null,
            'logo_url'     => $row['logo_url'] ?? null,
            'terms'        => (string) ($row['terms'] ?? ''),
            'timezone'     => (string) ($row['timezone'] ?? 'Asia/Kolkata'),
            'locale'       => (string) ($row['locale'] ?? 'en-IN'),
            'service_uuids' => Db::jsonColumn($row['service_uuids'] ?? null),
            'require_client_phone' => (bool) ($row['require_client_phone'] ?? true),
            'require_client_email' => (bool) ($row['require_client_email'] ?? true),
            'is_published' => (bool) ($row['is_published'] ?? false),
            'published_at' => $row['published_at'] ?? null,
            'bo_id'        => (int) ($row['bo_id'] ?? 0),
        ];
    }
}
