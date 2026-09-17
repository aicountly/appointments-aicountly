<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * Shared entry work for every scoped endpoint.
 *
 * Authenticate, resolve the company scope, and CHECK THAT THIS SESSION MAY OPEN
 * THAT COMPANY — in that order, before a controller touches a row. The tenant
 * check is not optional and is not something an individual endpoint remembers
 * to do: it happens here, once, for all of them.
 */
abstract class Controller
{
    /** @return array{0: Auth, 1: Context} */
    protected static function enter(?string $permission = null): array
    {
        $auth = Auth::require();
        $ctx = Context::fromRequest();
        $ctx->assertAllowed($auth);

        if ($permission !== null) {
            Permissions::assert($ctx, $auth, $permission);
        }

        return [$auth, $ctx];
    }

    /**
     * Turn a domain service's failure into the right HTTP answer.
     *
     * The distinction that matters: `calendar_unavailable` is a 503 and
     * `slot_taken` is a 409. Both feel like "it did not work" to a caller, but
     * one means retry and the other means choose again — and a UI that cannot
     * tell them apart either offers a pointless Retry button or asks somebody
     * to re-pick a slot that was never the problem.
     */
    protected static function fail(?string $code, ?string $reason): never
    {
        $message = $reason ?? 'That could not be done.';

        match ($code) {
            'calendar_unavailable', 'payment_unavailable' => Http::error(503, (string) $code, $message, ['retryable' => true]),
            'slot_taken'        => Http::conflict($message, ['retryable' => false, 'reason' => 'slot_taken']),
            'illegal_transition' => Http::conflict($message, ['retryable' => false, 'reason' => 'illegal_transition']),
            'not_found', 'unknown_service', 'unknown_member' => Http::notFound($message),
            'notice_period', 'beyond_horizon', 'reschedule_limit',
            'mode_not_offered', 'invalid_range', 'in_the_past',
            'service_inactive', 'no_staff' => Http::validationFailed($message, ['reason' => (string) $code]),
            default => Http::error(422, $code ?? 'failed', $message),
        };
    }

    /**
     * A stable identity for a slot hold that is not a name and not a session key.
     *
     * A hold must be claimable only by whoever placed it, so it is keyed on the
     * session fingerprint — a hash that identifies the session without being
     * the session, and which never reaches a log or a response body.
     */
    protected static function holdOwner(Auth $auth): string
    {
        return $auth->fingerprint();
    }
}
