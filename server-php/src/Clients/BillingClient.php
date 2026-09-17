<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Env;
use Aicountly\Api\Features;

/**
 * Aicountly Billing — commercial invoices for completed appointments.
 *
 * Some appointment businesses invoice: a consultancy bills for the hour, a
 * clinic raises a bill after the visit. That document is Billing's, not
 * Appointments'. Appointments asks for it when a booking policy says to, keeps
 * `billing_document_uuid`, and links out.
 *
 * There is no invoice table here, no line items, no tax logic, no numbering
 * series. Reimplementing any of that would put two products in the business of
 * knowing what a customer owes.
 */
final class BillingClient extends ApiClient
{
    public function service(): string
    {
        return 'billing';
    }

    protected function productionBase(): string
    {
        return 'https://billing.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://billing.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'BILLING_API_BASE';
    }

    public function configured(): bool
    {
        return Features::enabled('BILLING');
    }

    /** @param array<string, mixed> $payload */
    public function requestDocument(array $payload, string $idempotencyKey): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'billing_not_enabled'];
        }

        return $this->request('POST', 'v1/documents', $payload, [
            'X-Service-Key'   => Env::get('BILLING_SERVICE_KEY'),
            'Idempotency-Key' => $idempotencyKey,
        ], true);
    }
}
