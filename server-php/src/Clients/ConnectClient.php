<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Env;
use Aicountly\Api\Features;

/**
 * Aicountly Connect — video and chat meeting rooms.
 *
 * An appointment whose mode is AICOUNTLY_CONNECT needs a room. Appointments
 * asks for one, keeps the returned `connect_room_uuid` and the join URL, and
 * puts the URL into the Calendar event so it appears where the practitioner and
 * the client will actually look for it.
 *
 * The room itself, who joined, how long they stayed, the recording — all
 * Connect's. Appointments holds a reference and a link.
 *
 * WHEN CONNECT IS NOT AVAILABLE the other three modes keep working: in person,
 * phone, and a video link somebody pasted in from elsewhere. That is the whole
 * reason appointment mode is an enum and not a boolean — an appointment product
 * that stops taking bookings because a video service is down has confused its
 * own job with somebody else's.
 */
final class ConnectClient extends ApiClient
{
    public function service(): string
    {
        return 'connect';
    }

    protected function productionBase(): string
    {
        return 'https://connect.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://connect.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'CONNECT_API_BASE';
    }

    public function configured(): bool
    {
        return Features::enabled('CONNECT');
    }

    public function unavailableMessage(): string
    {
        return 'Aicountly Connect is not connected, so video appointments cannot be created yet.';
    }

    /**
     * A room for one appointment.
     *
     * @param array{subject:string, starts_at:string, ends_at:string, host_uuid:string, reference:string} $payload
     */
    public function createRoom(array $payload, string $idempotencyKey): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'connect_not_enabled'];
        }

        return $this->request('POST', 'v1/rooms', $payload, [
            'X-Service-Key'   => Env::get('CONNECT_SERVICE_KEY'),
            'Idempotency-Key' => $idempotencyKey,
        ], true);
    }

    public function closeRoom(string $roomUuid): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'connect_not_enabled'];
        }

        return $this->request('DELETE', 'v1/rooms/' . rawurlencode($roomUuid), null, [
            'X-Service-Key' => Env::get('CONNECT_SERVICE_KEY'),
        ]);
    }
}
