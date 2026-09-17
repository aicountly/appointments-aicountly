<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

/**
 * Live reads against manage.aicountly.com.
 *
 * Manage owns the company and the branch. This product stores `cmp_id` and
 * `bo_id` as bare references and nothing else — no company name, no branch
 * master, no addresses. A screen that needs the branch's name or address asks
 * here, on that request.
 *
 * That matters for a booking product specifically: the branch address is what
 * goes on a confirmation message telling somebody where to turn up. A copied
 * address is an address that stays wrong after the clinic moves, and the person
 * who finds out is standing outside the old one.
 *
 * Locations in Appointments are branches from Manage with appointment-shaped
 * configuration attached — opening hours for bookings, which rooms exist, which
 * services are offered there. The configuration is ours; the branch is not.
 */
final class ManageClient extends ApiClient
{
    private string $authorization = '';

    public function service(): string
    {
        return 'manage';
    }

    protected function productionBase(): string
    {
        return 'https://manage.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://manage.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'MANAGE_API_BASE';
    }

    public function withSession(string $sesKey): self
    {
        $this->authorization = 'Bearer ' . $sesKey;

        return $this;
    }

    /**
     * Company and its branches, in one call.
     *
     * Deliberately one call and memoised for the request: the context check runs
     * on every scoped endpoint, and asking per endpoint would put Manage in the
     * hot path of every screen this product draws.
     */
    public function companyInfo(int $cmpId): array
    {
        return $this->request('GET', 'companyinfo' . self::query(['comp_id' => $cmpId]), null, ['Authorization' => $this->authorization]);
    }

    /** Companies this session may open — the company switcher. */
    public function companies(array $filters = []): array
    {
        return $this->request('GET', 'companies' . self::query($filters), null, ['Authorization' => $this->authorization]);
    }
}
