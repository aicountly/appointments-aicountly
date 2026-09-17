<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Features;

/**
 * Live reads and writes against the authoritative party directory.
 *
 * A client's identity — name, mobile, email, addresses — belongs to Contacts.
 * When a receptionist books somebody who has never been in before, the CONTACT
 * is created THERE and Appointments keeps the uuid. There is no client table
 * here and there will not be one: a second universal contact master is how a
 * business ends up with two spellings of the same person's mobile number and no
 * way to say which is right.
 *
 * What Appointments does keep against a `contact_uuid` is the appointment-shaped
 * part of knowing somebody: which slot they prefer, which practitioner they ask
 * for, whether they answer WhatsApp, how many times they have not turned up.
 * None of that is Contacts' business and none of it is stored there.
 */
final class ContactsClient extends ApiClient
{
    private string $authorization = '';

    public function service(): string
    {
        return 'contacts';
    }

    protected function productionBase(): string
    {
        return 'https://contacts.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://contacts.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'CONTACTS_API_BASE';
    }

    public function withSession(string $sesKey): self
    {
        $clone = clone $this;
        $clone->authorization = 'Bearer ' . trim($sesKey);

        return $clone;
    }

    public function configured(): bool
    {
        return Features::enabled('CONTACTS');
    }

    /** @param array<string, mixed> $filters */
    public function search(string $term, array $filters = []): array
    {
        return $this->request(
            'GET',
            'contacts' . self::query(['q' => $term, 'limit' => 20] + $filters),
            null,
            ['Authorization' => $this->authorization],
        );
    }

    public function contact(string $contactUuid): array
    {
        return $this->request('GET', 'contacts/' . rawurlencode($contactUuid), null, ['Authorization' => $this->authorization]);
    }

    /**
     * Create a contact from a booking.
     *
     * Idempotency-Key matters here more than anywhere else in this product: a
     * client who double-taps "Book" on a phone with one bar of signal must not
     * end up as two people in the directory.
     *
     * @param array<string, mixed> $payload
     */
    public function create(array $payload, string $idempotencyKey): array
    {
        return $this->request('POST', 'contacts', $payload, [
            'Authorization'   => $this->authorization,
            'Idempotency-Key' => $idempotencyKey,
        ], true);
    }
}
