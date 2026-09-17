<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Env;
use Aicountly\Api\Features;

/**
 * Outbound reminders and confirmations — WhatsApp, SMS and email.
 *
 * ## What Appointments owns and what it does not
 *
 * The RULES are ours: 24 hours before, 4 hours before, the confirmation the
 * moment a slot is taken, who gets which channel, what happens when a client
 * does not answer. So is the SCHEDULING of them — Appointments runs its own
 * reminder timers, because a reminder is a fact about an appointment and there
 * is no central automation engine in this fleet to defer to.
 *
 * The DELIVERY is not ours. The provider relationship, the template approval,
 * the sender identity, the delivery receipts: all of that belongs to whatever
 * messaging product the deployment has, and this client is how we ask it.
 *
 * ## Until a provider is connected
 *
 * Reminders are still computed, still scheduled, still visible on the dashboard
 * — and marked `not_sent (messaging not connected)`. They are not quietly
 * dropped and they are certainly not shown as delivered. A reminder performance
 * panel that reports 96% delivery on messages nobody sent is worse than an
 * empty one.
 */
final class MessagingClient extends ApiClient
{
    /** @var list<string> */
    public const CHANNELS = ['email', 'sms', 'whatsapp', 'voice'];

    public function service(): string
    {
        return 'messaging';
    }

    protected function productionBase(): string
    {
        return 'https://messaging.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://messaging.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'MESSAGING_API_BASE';
    }

    public function configured(): bool
    {
        return Features::enabled('MESSAGING');
    }

    public function unavailableMessage(): string
    {
        return 'No messaging provider is connected, so reminders are scheduled but not delivered.';
    }

    /**
     * Send one message.
     *
     * Voice goes to Receptionist rather than here — a reminder phone call is a
     * conversation, and conversations are Receptionist's. ReminderService routes
     * it there; this client refuses it so the boundary cannot be blurred by a
     * future caller.
     *
     * @param array{channel:string, to:string, template:string, variables:array<string,mixed>, reference:string} $payload
     */
    public function send(array $payload, string $idempotencyKey): array
    {
        $channel = strtolower((string) ($payload['channel'] ?? ''));

        if ($channel === 'voice') {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'voice_belongs_to_receptionist'];
        }
        if (!in_array($channel, self::CHANNELS, true)) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'unknown_channel'];
        }
        if (!$this->configured()) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'messaging_not_enabled'];
        }

        return $this->request('POST', 'v1/messages', $payload, [
            'X-Service-Key'   => Env::get('MESSAGING_SERVICE_KEY'),
            'Idempotency-Key' => $idempotencyKey,
        ], true);
    }

    /**
     * Delivery outcomes for the Client Experience dashboard.
     *
     * @param array<string, mixed> $filters
     */
    public function deliveryStats(array $filters): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'messaging_not_enabled'];
        }

        return $this->request('GET', 'v1/messages/stats' . self::query($filters), null, [
            'X-Service-Key' => Env::get('MESSAGING_SERVICE_KEY'),
        ]);
    }
}
