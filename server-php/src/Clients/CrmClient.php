<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Features;

/**
 * Relationship context from CRM, read and shown — never rebuilt.
 *
 * CRM owns Customer 360: the account, the relationship, the health score, the
 * timeline of everything that ever happened with this customer. Appointments
 * shows a slice of that next to a booking so the person taking the call knows
 * who they are talking to.
 *
 * It does not store any of it. An appointment carries `crm_account_uuid` and
 * nothing more, and the panel that shows relationship context is empty and says
 * so when CRM is unreachable — which is the honest answer, and a great deal
 * better than showing last quarter's health score as though it were today's.
 */
final class CrmClient extends ApiClient
{
    private string $authorization = '';

    public function service(): string
    {
        return 'crm';
    }

    protected function productionBase(): string
    {
        return 'https://crm.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://crm.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'CRM_API_BASE';
    }

    public function withSession(string $sesKey): self
    {
        $clone = clone $this;
        $clone->authorization = 'Bearer ' . trim($sesKey);

        return $clone;
    }

    public function configured(): bool
    {
        return Features::enabled('CRM');
    }

    /** The relationship summary for one contact — enough for a panel, not a copy of CRM. */
    public function accountForContact(string $contactUuid): array
    {
        return $this->request(
            'GET',
            'accounts/by-contact/' . rawurlencode($contactUuid),
            null,
            ['Authorization' => $this->authorization],
        );
    }
}
