<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Env;
use Aicountly\Api\Features;

/**
 * Aicountly Lobby (formerly Receptionist) — the voice front desk.
 *
 * ## Which way the calls go
 *
 * Mostly the other way. Lobby calls Appointments: find slots, hold one,
 * book it, reschedule, cancel. Those are Appointments' own routes and are the
 * real integration — see Routes.php and the service-key path in Auth.
 *
 * This client is the thin return leg. Appointments asks Lobby for two
 * things and nothing else:
 *
 *   1. aggregate contribution figures for the Intelligence dashboard — how many
 *      appointments the voice front desk initiated, booked, moved, cancelled;
 *   2. a deep link, so "Open in Lobby" goes somewhere.
 *
 * ## What it must never do
 *
 * Read a call, a recording, a transcript or a conversation. Those are
 * Lobby's and they stay there. Appointments records that a booking's
 * source was RECEPTIONIST and keeps `receptionist_session_uuid` as a reference
 * — that is the entire footprint, and it is a reference, not a copy.
 *
 * Lobby is not live yet, so the flag is off and every Lobby
 * surface says so rather than showing a panel of zeroes that look like a quiet
 * week.
 */
final class ReceptionistClient extends ApiClient
{
    public function service(): string
    {
        return 'receptionist';
    }

    protected function productionBase(): string
    {
        return 'https://lobby.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://lobby.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'RECEPTIONIST_API_BASE';
    }

    public function configured(): bool
    {
        return Features::enabled('RECEPTIONIST');
    }

    public function unavailableMessage(): string
    {
        return 'Lobby integration is not connected.';
    }

    /**
     * Counts only, for one period, for one company.
     *
     * @param array<string, mixed> $filters
     */
    public function contribution(array $filters): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'receptionist_not_enabled'];
        }

        return $this->request(
            'GET',
            'v1/appointments/contribution' . self::query($filters),
            null,
            ['X-Service-Key' => Env::get('RECEPTIONIST_SERVICE_KEY')],
        );
    }

    /** Where "Open in Lobby" goes. A route, not a URL from an API response. */
    public function deepLink(int $cmpId): string
    {
        return $this->base() . '/?cmp_id=' . $cmpId;
    }
}
