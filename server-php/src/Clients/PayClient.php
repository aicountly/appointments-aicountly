<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Env;
use Aicountly\Api\Features;

/**
 * Deposits and prepayments, through Aicountly Pay.
 *
 * ## The split
 *
 * Appointments decides WHETHER money is required: this service takes a ₹500
 * deposit, that one is payable in full before the slot is confirmed, a no-show
 * is charged half. Those are booking policy and they live here.
 *
 * Pay decides everything about the money itself: the gateway, the payment link,
 * the transaction, the status, the refund, the settlement. Appointments keeps
 * `payment_request_uuid` and the last status Pay reported, so a screen can say
 * "deposit pending" without asking on every render. It stores no card data, no
 * gateway reference, no amount collected, and it never calls Razorpay or Stripe
 * itself — if it did, there would be two products that think they know what a
 * client has paid.
 *
 * ## Pay is not live yet
 *
 * So this client is complete and the flag is off. `APPOINTMENTS_PAY_ENABLED=1`
 * plus `PAY_SERVICE_KEY` is all that turns it on; nothing else in Appointments
 * needs to change. Until then every deposit surface says plainly that Pay is
 * not enabled, and a service whose policy requires a deposit cannot be booked
 * online — which is the safe failure, because the alternative is confirming an
 * appointment on a payment that was never taken.
 */
final class PayClient extends ApiClient
{
    public function service(): string
    {
        return 'pay';
    }

    protected function productionBase(): string
    {
        return 'https://pay.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://pay.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'PAY_API_BASE';
    }

    public function configured(): bool
    {
        return Features::enabled('PAY');
    }

    /** The one sentence every disabled Pay surface shows. Kept here so it reads the same everywhere. */
    public function unavailableMessage(): string
    {
        return 'Aicountly Pay integration is not enabled yet.';
    }

    /**
     * Ask Pay for a payment request against a booking.
     *
     * @param array{amount_minor:int, currency:string, purpose:string, reference:string, contact_uuid?:string, description?:string} $payload
     */
    public function createPaymentRequest(array $payload, string $idempotencyKey): array
    {
        if (!$this->configured()) {
            return $this->disabled();
        }

        return $this->request('POST', 'v1/payment-requests', $payload, $this->headers() + [
            'Idempotency-Key' => $idempotencyKey,
        ], true);
    }

    public function paymentRequest(string $paymentRequestUuid): array
    {
        if (!$this->configured()) {
            return $this->disabled();
        }

        return $this->request('GET', 'v1/payment-requests/' . rawurlencode($paymentRequestUuid), null, $this->headers());
    }

    /**
     * Deposit totals for the Intelligence dashboard.
     *
     * Read from Pay on the request that draws the panel. There is no deposits
     * table here to go stale.
     *
     * @param array<string, mixed> $filters
     */
    public function summary(array $filters): array
    {
        if (!$this->configured()) {
            return $this->disabled();
        }

        return $this->request('GET', 'v1/payment-requests/summary' . self::query($filters), null, $this->headers());
    }

    public function refund(string $paymentRequestUuid, int $amountMinor, string $reason, string $idempotencyKey): array
    {
        if (!$this->configured()) {
            return $this->disabled();
        }

        return $this->request('POST', 'v1/payment-requests/' . rawurlencode($paymentRequestUuid) . '/refunds', [
            'amount_minor' => $amountMinor,
            'reason'       => $reason,
        ], $this->headers() + ['Idempotency-Key' => $idempotencyKey], true);
    }

    /** @return array{ok:bool, status:int, body:null, error:string} */
    private function disabled(): array
    {
        return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'pay_not_enabled'];
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Service-Key' => Env::get('PAY_SERVICE_KEY')];
    }
}
