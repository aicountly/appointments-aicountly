<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * This product's own permissions, layered over the portal identity.
 *
 * The distinctions that matter in a booking business: a receptionist books and
 * reschedules all day but must not be able to change the cancellation policy or
 * republish the public booking page; a practitioner sees their own diary; an
 * owner sees the money. Those are different permissions here for that reason.
 *
 * ENFORCED IN THE BACKEND. Hiding a menu item in React is a courtesy, not a
 * control — the API route is one curl away.
 */
final class Permissions
{
    public const TABLE_PROFILES    = 'appointment_permission_profiles';
    public const TABLE_ASSIGNMENTS = 'appointment_permission_assignments';

    /** @var array<string, array<string, string>> */
    public const CATALOG = [
        'Dashboards' => [
            'appointments.dashboard.view' => 'Open the Appointments dashboards',
            'appointments.reports.view'   => 'View appointment reports and exports',
        ],
        'Booking' => [
            'appointments.booking.view'     => 'View appointments',
            'appointments.booking.create'   => 'Book an appointment',
            'appointments.booking.edit'     => 'Reschedule or amend an appointment',
            'appointments.booking.cancel'   => 'Cancel an appointment',
            'appointments.booking.override' => 'Book outside the booking rules (overbook, short notice)',
        ],
        'Clients' => [
            'appointments.clients.view'   => 'View client appointment profiles',
            'appointments.clients.manage' => 'Edit appointment preferences and intake answers',
        ],
        'Configuration' => [
            'appointments.services.manage'      => 'Manage services, durations and buffers',
            'appointments.team.manage'          => 'Manage team availability and eligibility',
            'appointments.resources.manage'     => 'Manage locations, rooms and resources',
            'appointments.waitlist.manage'      => 'Manage the waitlist and offer slots',
            'appointments.forms.manage'         => 'Build and edit booking forms',
            'appointments.booking_pages.manage' => 'Publish and edit public booking pages',
            'appointments.reminders.manage'     => 'Change reminder rules and channels',
        ],
        'Administration' => [
            'appointments.settings.manage'     => 'Change Appointments settings and policies',
            'appointments.integrations.manage' => 'Connect and configure integrations',
            'appointments.access.manage'       => 'Manage Appointments permission profiles',
        ],
    ];

    /**
     * What a company gets before anybody has configured anything.
     *
     * Without this, the first person into a brand-new company sees a working
     * sign-in and a wall of refusals, which reads as a broken product rather
     * than as an administrative step nobody has taken yet. The owner (portal
     * acs_type 1) holds everything regardless; this is for everybody else on
     * day one, and it deliberately excludes the settings and access
     * permissions — those stay the owner's until they are granted.
     *
     * @var list<string>
     */
    public const DEFAULT_MEMBER_GRANTS = [
        'appointments.dashboard.view',
        'appointments.booking.view',
        'appointments.clients.view',
    ];

    /** @var array<string, list<string>> */
    private static array $cache = [];

    public static function assert(Context $ctx, Auth $auth, string $permission): void
    {
        if (!self::allows($ctx, $auth, $permission)) {
            Http::forbidden('You do not have permission to ' . self::describe($permission) . '.');
        }
    }

    public static function allows(Context $ctx, Auth $auth, string $permission): bool
    {
        if ($auth->isService()) {
            return true;
        }
        if ($auth->accessType() === 1) {
            return true;
        }

        return in_array($permission, self::granted($ctx, $auth), true);
    }

    /** @return list<string> */
    public static function granted(Context $ctx, Auth $auth): array
    {
        $key = $ctx->cmpId . ':' . $auth->uuid;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        if ($auth->isService() || $auth->accessType() === 1) {
            return self::$cache[$key] = self::all();
        }

        try {
            $rows = Db::all(
                'SELECT p.permissions
                 FROM ' . self::TABLE_ASSIGNMENTS . ' a
                 JOIN ' . self::TABLE_PROFILES . ' p ON p.profile_id = a.profile_id
                 WHERE a.cmp_id = :cmp AND a.user_uuid = :uuid AND p.is_active = TRUE',
                ['cmp' => $ctx->cmpId, 'uuid' => $auth->uuid],
            );
        } catch (\Throwable $e) {
            error_log('[permissions] lookup failed: ' . $e->getMessage());

            return self::$cache[$key] = [];
        }

        if ($rows === []) {
            return self::$cache[$key] = self::DEFAULT_MEMBER_GRANTS;
        }

        $granted = [];
        foreach ($rows as $row) {
            foreach (Db::jsonColumn($row['permissions'] ?? null) as $permission) {
                if (is_string($permission)) {
                    $granted[$permission] = true;
                }
            }
        }

        return self::$cache[$key] = array_keys($granted);
    }

    /**
     * Drop the memoised grants for a user.
     *
     * The cache is per-request, which is right for reads: `granted()` is called
     * several times while rendering a screen. But an endpoint that CHANGES
     * somebody's profile and then reports the result would answer from the
     * grants it read before the change — including, when the caller edits their
     * own access, telling them the edit did nothing.
     */
    public static function forget(?Context $ctx = null, ?Auth $auth = null): void
    {
        if ($ctx === null || $auth === null) {
            self::$cache = [];

            return;
        }

        unset(self::$cache[$ctx->cmpId . ':' . $auth->uuid]);
    }

    /**
     * The permissions this caller may hand to somebody else.
     *
     * A company owner may grant anything. Anybody else may grant only what they
     * themselves hold — otherwise `access.manage` is not a permission, it is a
     * route to every other permission.
     *
     * @return list<string>
     */
    public static function grantable(Context $ctx, Auth $auth): array
    {
        if ($auth->isService() || $auth->accessType() === 1) {
            return self::all();
        }

        return self::granted($ctx, $auth);
    }

    /** @return list<string> */
    public static function all(): array
    {
        $out = [];
        foreach (self::CATALOG as $group) {
            foreach (array_keys($group) as $permission) {
                $out[] = $permission;
            }
        }

        return $out;
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    private static function describe(string $permission): string
    {
        foreach (self::CATALOG as $group) {
            if (isset($group[$permission])) {
                return strtolower($group[$permission]);
            }
        }

        return 'do that';
    }
}
