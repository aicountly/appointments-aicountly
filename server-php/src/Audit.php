<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Append-only audit of THIS product's own actions.
 *
 * Calendar audits its events and Pay audits its transactions; neither is copied
 * here. What this records is what only Appointments knows: who cancelled inside
 * the notice period and why, who overrode a booking rule, who offered a
 * waitlisted client somebody else's released slot, who changed the no-show
 * policy the week the no-show rate moved.
 */
final class Audit
{
    public const TABLE = 'appointment_audit_log';

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public static function record(
        Context $ctx,
        Auth $auth,
        string $action,
        string $entityType,
        int|string|null $entityId,
        ?array $before = null,
        ?array $after = null,
        string $reason = '',
    ): void {
        try {
            Db::insert(self::TABLE, [
                'cmp_id'       => $ctx->cmpId,
                'bo_id'        => $ctx->boId,
                'actor_uuid'   => $auth->uuid,
                'actor_kind'   => $auth->kind,
                'source_app'   => $auth->sourceApp,
                'action'       => $action,
                'entity_type'  => $entityType,
                'entity_id'    => $entityId === null ? null : (string) $entityId,
                'before_state' => $before,
                'after_state'  => $after,
                'reason'       => $reason !== '' ? $reason : null,
                'ip_address'   => self::clientIp(),
                'created_at'   => gmdate('Y-m-d H:i:s'),
            ], 'audit_id');
        } catch (\Throwable $e) {
            // An audit write must never be the reason a booking fails. It is
            // logged loudly instead, because a silently missing audit row is
            // worse than a noisy one.
            error_log('[audit] failed to record ' . $action . ' on ' . $entityType . ': ' . $e->getMessage());
        }
    }

    /**
     * The same record, for something a client did on a public booking page.
     *
     * There is no Auth there by design, so the actor is the page and the
     * entity carries whatever identified the client.
     */
    public static function recordPublic(
        Context $ctx,
        string $action,
        string $entityType,
        int|string|null $entityId,
        ?array $after = null,
        string $reason = '',
    ): void {
        try {
            Db::insert(self::TABLE, [
                'cmp_id'       => $ctx->cmpId,
                'bo_id'        => $ctx->boId,
                'actor_uuid'   => 'public',
                'actor_kind'   => 'public',
                'source_app'   => 'booking_page',
                'action'       => $action,
                'entity_type'  => $entityType,
                'entity_id'    => $entityId === null ? null : (string) $entityId,
                'before_state' => null,
                'after_state'  => $after,
                'reason'       => $reason !== '' ? $reason : null,
                'ip_address'   => self::clientIp(),
                'created_at'   => gmdate('Y-m-d H:i:s'),
            ], 'audit_id');
        } catch (\Throwable $e) {
            error_log('[audit] failed to record public ' . $action . ': ' . $e->getMessage());
        }
    }

    private static function clientIp(): ?string
    {
        $candidates = [$_SERVER['HTTP_X_FORWARDED_FOR'] ?? '', $_SERVER['REMOTE_ADDR'] ?? ''];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            $first = trim(explode(',', $candidate)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }

        return null;
    }
}
