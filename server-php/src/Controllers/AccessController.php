<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Clients\ManageClient;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;
use Aicountly\Api\Support\Clock;

/**
 * Who may do what in Appointments.
 *
 * ## Two rules that are the whole point
 *
 * NO ESCALATION. Anybody but the company owner may grant only permissions they
 * themselves hold. Otherwise `access.manage` is not a permission, it is a route
 * to every other permission, and the separation between a receptionist who
 * books and a manager who changes the cancellation policy is one profile edit
 * from meaningless.
 *
 * NO SELF-LOCKOUT. The last person who can manage access cannot remove that
 * ability from themselves. The alternative is a company whose permissions can
 * only be fixed by support.
 */
final class AccessController extends Controller
{
    public static function catalogue(): void
    {
        [$auth, $ctx] = self::enter('appointments.access.manage');

        Http::data([
            'catalogue' => Permissions::CATALOG,
            'grantable' => Permissions::grantable($ctx, $auth),
            'defaults'  => Permissions::DEFAULT_MEMBER_GRANTS,
        ]);
    }

    public static function profiles(): void
    {
        [, $ctx] = self::enter('appointments.access.manage');

        $rows = Db::all(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM ' . Permissions::TABLE_ASSIGNMENTS . ' a
                      WHERE a.profile_id = p.profile_id) AS member_count
               FROM ' . Permissions::TABLE_PROFILES . ' p
              WHERE p.cmp_id = :cmp
              ORDER BY p.name',
            ['cmp' => $ctx->cmpId],
        );

        Http::data([
            'profiles' => array_map(static fn (array $row) => [
                'profile_id'   => (int) $row['profile_id'],
                'name'         => (string) $row['name'],
                'description'  => (string) $row['description'],
                'permissions'  => Db::jsonColumn($row['permissions'] ?? null),
                'is_active'    => (bool) $row['is_active'],
                'member_count' => (int) $row['member_count'],
            ], $rows),
        ]);
    }

    public static function saveProfile(): void
    {
        [$auth, $ctx] = self::enter('appointments.access.manage');

        $body = Http::body();
        $name = trim((string) ($body['name'] ?? ''));

        if ($name === '') {
            Http::validationFailed('A profile name is required.');
        }

        $requested = array_values(array_unique(array_filter(
            array_map('strval', (array) ($body['permissions'] ?? [])),
            static fn (string $p) => Permissions::exists($p),
        )));

        // The escalation check. Not "warn and save" — refuse, and name what
        // was refused, so the person editing knows which line to remove.
        $grantable = Permissions::grantable($ctx, $auth);
        $beyond = array_values(array_diff($requested, $grantable));

        if ($beyond !== []) {
            Http::forbidden(
                'You cannot grant permissions you do not hold yourself: ' . implode(', ', $beyond) . '.',
            );
        }

        $profileId = (int) ($body['profile_id'] ?? 0);
        $existing = null;

        if ($profileId > 0) {
            $existing = Db::first(
                'SELECT * FROM ' . Permissions::TABLE_PROFILES . ' WHERE profile_id = :id AND cmp_id = :cmp',
                ['id' => $profileId, 'cmp' => $ctx->cmpId],
            );
            if ($existing === null) {
                Http::notFound('That profile does not exist.');
            }
        }

        $values = [
            'name'        => substr($name, 0, 120),
            'description' => substr(trim((string) ($body['description'] ?? '')), 0, 500),
            'permissions' => $requested,
            'is_active'   => (bool) ($body['is_active'] ?? true),
            'updated_at'  => Clock::sql(Clock::now()),
        ];

        if ($existing === null) {
            $profileId = (int) Db::insert(Permissions::TABLE_PROFILES, $values + [
                'cmp_id'          => $ctx->cmpId,
                'created_by_uuid' => $auth->uuid,
            ], 'profile_id');
        } else {
            // Editing a profile the caller is themselves assigned to, in a way
            // that would remove their own access management, is the lockout
            // this refuses.
            self::assertNoSelfLockout($ctx, $auth, $profileId, $requested);
            Db::update(Permissions::TABLE_PROFILES, $values, ['profile_id' => $profileId, 'cmp_id' => $ctx->cmpId]);
        }

        // The caller's grants are memoised per request, so an edit to their own
        // profile would otherwise be reported from the values read before it.
        Permissions::forget($ctx, $auth);

        Audit::record(
            $ctx,
            $auth,
            $existing === null ? 'access_profile.created' : 'access_profile.updated',
            'access_profile',
            (string) $profileId,
            $existing === null ? null : ['permissions' => Db::jsonColumn($existing['permissions'] ?? null)],
            ['name' => $name, 'permissions' => $requested],
        );

        Http::data([
            'profile' => [
                'profile_id'  => $profileId,
                'name'        => $values['name'],
                'description' => $values['description'],
                'permissions' => $requested,
                'is_active'   => $values['is_active'],
            ],
        ], $existing === null ? 201 : 200);
    }

    public static function deleteProfile(string $profileId): void
    {
        [$auth, $ctx] = self::enter('appointments.access.manage');

        $id = (int) $profileId;
        $existing = Db::first(
            'SELECT * FROM ' . Permissions::TABLE_PROFILES . ' WHERE profile_id = :id AND cmp_id = :cmp',
            ['id' => $id, 'cmp' => $ctx->cmpId],
        );

        if ($existing === null) {
            Http::notFound('That profile does not exist.');
        }

        self::assertNoSelfLockout($ctx, $auth, $id, []);

        Db::run(
            'DELETE FROM ' . Permissions::TABLE_PROFILES . ' WHERE profile_id = :id AND cmp_id = :cmp',
            ['id' => $id, 'cmp' => $ctx->cmpId],
        );

        Permissions::forget($ctx, $auth);

        Audit::record($ctx, $auth, 'access_profile.deleted', 'access_profile', (string) $id, [
            'name' => $existing['name'],
        ], null);

        Http::data(['deleted' => true]);
    }

    public static function members(): void
    {
        [$auth, $ctx] = self::enter('appointments.access.manage');

        $rows = Db::all(
            'SELECT a.assignment_id, a.user_uuid, a.created_at, p.profile_id, p.name, p.permissions
               FROM ' . Permissions::TABLE_ASSIGNMENTS . ' a
               JOIN ' . Permissions::TABLE_PROFILES . ' p ON p.profile_id = a.profile_id
              WHERE a.cmp_id = :cmp
              ORDER BY a.created_at',
            ['cmp' => $ctx->cmpId],
        );

        $names = self::manageNames($auth, $ctx);

        Http::data([
            'members' => array_map(static fn (array $row) => [
                'assignment_id' => (int) $row['assignment_id'],
                'user_uuid'     => (string) $row['user_uuid'],
                'label'         => $names[(string) $row['user_uuid']] ?? (string) $row['user_uuid'],
                'profile'       => [
                    'profile_id'  => (int) $row['profile_id'],
                    'name'        => (string) $row['name'],
                    'permissions' => Db::jsonColumn($row['permissions'] ?? null),
                ],
                'assigned_at'   => (string) $row['created_at'],
            ], $rows),
            'names_available' => $names !== [],
        ]);
    }

    public static function assign(): void
    {
        [$auth, $ctx] = self::enter('appointments.access.manage');

        $body = Http::body();
        $userUuid = trim((string) ($body['user_uuid'] ?? ''));
        $profileId = (int) ($body['profile_id'] ?? 0);

        if ($userUuid === '' || $profileId <= 0) {
            Http::validationFailed('user_uuid and profile_id are required.');
        }

        $profile = Db::first(
            'SELECT * FROM ' . Permissions::TABLE_PROFILES . ' WHERE profile_id = :id AND cmp_id = :cmp',
            ['id' => $profileId, 'cmp' => $ctx->cmpId],
        );

        if ($profile === null) {
            Http::notFound('That profile does not exist.');
        }

        // Assigning a profile is granting its permissions, so it faces the
        // same escalation check as editing one.
        $beyond = array_values(array_diff(
            Db::jsonColumn($profile['permissions'] ?? null),
            Permissions::grantable($ctx, $auth),
        ));

        if ($beyond !== []) {
            Http::forbidden(
                'That profile includes permissions you do not hold: ' . implode(', ', $beyond) . '.',
            );
        }

        Db::run(
            'INSERT INTO ' . Permissions::TABLE_ASSIGNMENTS . ' (cmp_id, user_uuid, profile_id, assigned_by_uuid)
             VALUES (:cmp, :user, :profile, :by)
             ON CONFLICT (cmp_id, user_uuid, profile_id) DO NOTHING',
            ['cmp' => $ctx->cmpId, 'user' => $userUuid, 'profile' => $profileId, 'by' => $auth->uuid],
        );

        Permissions::forget();

        Audit::record($ctx, $auth, 'access.assigned', 'user', $userUuid, null, [
            'profile_id' => $profileId,
            'profile'    => $profile['name'],
        ]);

        Http::data(['assigned' => true], 201);
    }

    public static function unassign(string $assignmentId): void
    {
        [$auth, $ctx] = self::enter('appointments.access.manage');

        $id = (int) $assignmentId;
        $existing = Db::first(
            'SELECT a.*, p.permissions FROM ' . Permissions::TABLE_ASSIGNMENTS . ' a
               JOIN ' . Permissions::TABLE_PROFILES . ' p ON p.profile_id = a.profile_id
              WHERE a.assignment_id = :id AND a.cmp_id = :cmp',
            ['id' => $id, 'cmp' => $ctx->cmpId],
        );

        if ($existing === null) {
            Http::notFound('That assignment does not exist.');
        }

        if ((string) $existing['user_uuid'] === $auth->uuid) {
            self::assertNoSelfLockout($ctx, $auth, (int) $existing['profile_id'], []);
        }

        Db::run(
            'DELETE FROM ' . Permissions::TABLE_ASSIGNMENTS . ' WHERE assignment_id = :id AND cmp_id = :cmp',
            ['id' => $id, 'cmp' => $ctx->cmpId],
        );

        Permissions::forget();

        Audit::record($ctx, $auth, 'access.unassigned', 'user', (string) $existing['user_uuid'], [
            'profile_id' => $existing['profile_id'],
        ], null);

        Http::data(['unassigned' => true]);
    }

    /**
     * Refuse a change that would leave nobody able to manage access.
     *
     * The company owner is exempt: the portal's `acs_type 1` holds everything
     * regardless of what is configured here, so an owner can always undo any
     * of this.
     *
     * @param list<string> $newPermissions what the profile will hold after the change
     */
    private static function assertNoSelfLockout(
        \Aicountly\Api\Context $ctx,
        \Aicountly\Api\Auth $auth,
        int $profileId,
        array $newPermissions,
    ): void {
        if ($auth->accessType() === 1) {
            return;
        }

        $assigned = Db::first(
            'SELECT 1 FROM ' . Permissions::TABLE_ASSIGNMENTS . '
              WHERE cmp_id = :cmp AND user_uuid = :user AND profile_id = :profile',
            ['cmp' => $ctx->cmpId, 'user' => $auth->uuid, 'profile' => $profileId],
        );

        if ($assigned === null) {
            return;
        }

        if (in_array('appointments.access.manage', $newPermissions, true)) {
            return;
        }

        // Do they hold it through some OTHER profile? If so, this change is
        // safe.
        $elsewhere = Db::all(
            'SELECT p.permissions FROM ' . Permissions::TABLE_ASSIGNMENTS . ' a
               JOIN ' . Permissions::TABLE_PROFILES . ' p ON p.profile_id = a.profile_id
              WHERE a.cmp_id = :cmp AND a.user_uuid = :user AND a.profile_id <> :profile
                AND p.is_active = TRUE',
            ['cmp' => $ctx->cmpId, 'user' => $auth->uuid, 'profile' => $profileId],
        );

        foreach ($elsewhere as $row) {
            if (in_array('appointments.access.manage', Db::jsonColumn($row['permissions'] ?? null), true)) {
                return;
            }
        }

        Http::forbidden(
            'That change would remove your own ability to manage Appointments access. '
            . 'Ask the company owner to make it, or give somebody else access management first.',
        );
    }

    /** @return array<string, string> */
    private static function manageNames(\Aicountly\Api\Auth $auth, \Aicountly\Api\Context $ctx): array
    {
        if ($auth->isService() || $auth->sesKey() === '') {
            return [];
        }

        try {
            $result = (new ManageClient())->withSession($auth->sesKey())->companyMembers($ctx->cmpId);
        } catch (\Throwable) {
            return [];
        }

        if (!$result['ok']) {
            return [];
        }

        $rows = $result['body']['data'] ?? $result['body']['members'] ?? $result['body'] ?? [];
        $out = [];

        foreach (is_array($rows) ? $rows : [] as $person) {
            if (!is_array($person)) {
                continue;
            }
            $uuid = trim((string) ($person['uuid_aictly'] ?? $person['user_uuid'] ?? $person['uuid'] ?? ''));
            $name = trim((string) ($person['name'] ?? $person['full_name'] ?? $person['user_name'] ?? ''));
            if ($uuid !== '' && $name !== '') {
                $out[$uuid] = $name;
            }
        }

        return $out;
    }

    /** People in this company who could be given a profile. */
    public static function people(): void
    {
        [$auth, $ctx] = self::enter('appointments.access.manage');

        $names = self::manageNames($auth, $ctx);

        if ($names === []) {
            Http::error(503, 'manage_unavailable', 'Aicountly Manage is temporarily unavailable, so the list of people cannot be loaded.', [
                'retryable' => true,
            ]);
        }

        $assigned = [];
        foreach (Db::all(
            'SELECT DISTINCT user_uuid FROM ' . Permissions::TABLE_ASSIGNMENTS . ' WHERE cmp_id = :cmp',
            ['cmp' => $ctx->cmpId],
        ) as $row) {
            $assigned[(string) $row['user_uuid']] = true;
        }

        $people = [];
        foreach ($names as $uuid => $name) {
            $people[] = [
                'user_uuid'    => $uuid,
                'label'        => $name,
                'has_profile'  => isset($assigned[$uuid]),
            ];
        }

        Http::data(['people' => $people]);
    }
}
