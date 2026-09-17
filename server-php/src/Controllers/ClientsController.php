<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Clients\ContactsClient;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\BookingService;
use Aicountly\Api\Domain\ClientProfileService;
use Aicountly\Api\Http;
use Aicountly\Api\Idempotency;
use Aicountly\Api\Support\Clock;

/**
 * Clients, as an appointment business sees them.
 *
 * ## There is no client table here
 *
 * `index` reads this product's own bookings and groups them by
 * `contact_uuid` — how many times somebody has been, what they usually book,
 * when they last came. Their name, number and address come from Contacts.
 *
 * ## Searching and creating go to Contacts
 *
 * `search` proxies Contacts so the booking drawer offers real people. `create`
 * creates the contact THERE and returns the uuid, with an idempotency key,
 * because a client double-tapping Book on a bad connection must not become two
 * people in the directory.
 */
final class ClientsController extends Controller
{
    public static function index(): void
    {
        [, $ctx] = self::enter('appointments.clients.view');

        $params = Http::listParams(['last_appointment', 'appointments'], 'last_appointment');
        $where = ['b.cmp_id = :cmp', 'b.contact_uuid IS NOT NULL'];
        $bind = ['cmp' => $ctx->cmpId];

        if ($params['q'] !== '') {
            $where[] = '(b.client_name ILIKE :q OR b.client_phone ILIKE :q OR b.client_email ILIKE :q)';
            $bind['q'] = '%' . $params['q'] . '%';
        }

        $segment = strtolower(trim((string) (Http::param('segment') ?? '')));
        $having = match ($segment) {
            'new'       => ' HAVING COUNT(*) = 1',
            'returning' => ' HAVING COUNT(*) > 1',
            default     => '',
        };

        $whereSql = implode(' AND ', $where);

        $total = (int) Db::scalar(
            'SELECT COUNT(*) FROM (
                SELECT b.contact_uuid FROM ' . BookingService::TABLE . ' b
                 WHERE ' . $whereSql . ' GROUP BY b.contact_uuid' . $having . '
             ) AS grouped',
            $bind,
        );

        $rows = Db::all(
            "SELECT b.contact_uuid,
                    MAX(b.client_name) AS client_name,
                    MAX(b.client_phone) AS client_phone,
                    MAX(b.client_email) AS client_email,
                    COUNT(*) AS appointments,
                    COUNT(*) FILTER (WHERE b.status = 'COMPLETED') AS completed,
                    COUNT(*) FILTER (WHERE b.status = 'NO_SHOW') AS no_shows,
                    COUNT(*) FILTER (WHERE b.status = 'CANCELLED') AS cancelled,
                    MAX(b.starts_at) AS last_appointment,
                    MIN(b.starts_at) AS first_appointment,
                    (array_agg(s.name ORDER BY b.starts_at DESC))[1] AS usual_service
               FROM " . BookingService::TABLE . ' b
               JOIN appointment_services s ON s.service_uuid = b.service_uuid
              WHERE ' . $whereSql . '
              GROUP BY b.contact_uuid' . $having . '
              ORDER BY ' . ($params['sort'] === 'appointments' ? 'appointments' : 'MAX(b.starts_at)') . ' ' . $params['order'] . '
              LIMIT ' . $params['limit'] . ' OFFSET ' . $params['offset'],
            $bind,
        );

        $profiles = self::profilesFor($ctx, array_column($rows, 'contact_uuid'));

        Http::list(
            array_map(static function (array $row) use ($profiles): array {
                $contactUuid = (string) $row['contact_uuid'];
                $profile = $profiles[$contactUuid] ?? null;

                return [
                    'contact_uuid' => $contactUuid,
                    'label'        => trim((string) $row['client_name']) ?: 'Client',
                    'phone'        => (string) $row['client_phone'],
                    'email'        => (string) $row['client_email'],
                    'appointments' => (int) $row['appointments'],
                    'completed'    => (int) $row['completed'],
                    'no_shows'     => (int) $row['no_shows'],
                    'cancelled'    => (int) $row['cancelled'],
                    'segment'      => (int) $row['appointments'] > 1 ? 'returning' : 'new',
                    'usual_service' => $row['usual_service'],
                    'last_appointment'  => $row['last_appointment'],
                    'first_appointment' => $row['first_appointment'],
                    'preferred_channel' => $profile['preferred_channel'] ?? null,
                    'preferred_member_uuid' => $profile['preferred_member_uuid'] ?? null,
                ];
            }, $rows),
            $total,
            $params['limit'],
            $params['offset'],
        );
    }

    public static function show(string $contactUuid): void
    {
        [$auth, $ctx] = self::enter('appointments.clients.view');

        $history = Db::all(
            'SELECT b.booking_uuid, b.reference, b.starts_at, b.status, b.mode, b.booking_source,
                    s.name AS service_name, m.display_label AS member_label
               FROM ' . BookingService::TABLE . ' b
               JOIN appointment_services s ON s.service_uuid = b.service_uuid
          LEFT JOIN appointment_team_members m ON m.member_uuid = b.member_uuid
              WHERE b.cmp_id = :cmp AND b.contact_uuid = :contact
              ORDER BY b.starts_at DESC
              LIMIT 50',
            ['cmp' => $ctx->cmpId, 'contact' => $contactUuid],
        );

        if ($history === []) {
            Http::notFound('That client has no appointments with this company.');
        }

        $contact = null;
        $contactMessage = null;
        $client = new ContactsClient();

        if ($client->configured() && $auth->sesKey() !== '') {
            $result = $client->withSession($auth->sesKey())->contact($contactUuid);
            if ($result['ok']) {
                $contact = $result['body']['data'] ?? $result['body'] ?? null;
            } else {
                $contactMessage = 'Aicountly Contacts is temporarily unavailable, so the details captured at booking are shown.';
            }
        } else {
            $contactMessage = 'Aicountly Contacts is not connected for this deployment.';
        }

        Http::data([
            'contact_uuid'    => $contactUuid,
            // Canonical identity, from Contacts.
            'contact'         => $contact,
            'contact_message' => $contactMessage,
            // What Appointments knows and Contacts does not.
            'appointment_profile' => ClientProfileService::for($ctx, $contactUuid),
            'history'         => array_map(static fn (array $row) => [
                'booking_uuid' => (string) $row['booking_uuid'],
                'reference'    => (string) $row['reference'],
                'starts_at'    => (string) $row['starts_at'],
                'status'       => (string) $row['status'],
                'mode'         => (string) $row['mode'],
                'source'       => (string) $row['booking_source'],
                'service_name' => (string) $row['service_name'],
                'member_label' => $row['member_label'],
            ], $history),
        ]);
    }

    /**
     * Appointment preferences: which practitioner, which slot, which channel.
     *
     * Appointment-specific and stored here. A change of phone number is not
     * one of these — that goes to Contacts, and the UI links there.
     */
    public static function updatePreferences(string $contactUuid): void
    {
        [$auth, $ctx] = self::enter('appointments.clients.manage');

        $before = ClientProfileService::for($ctx, $contactUuid);
        $after = ClientProfileService::update($ctx, $contactUuid, Http::body());

        Audit::record($ctx, $auth, 'client_preferences.updated', 'contact', $contactUuid, [
            'preferred_member_uuid' => $before['preferred_member_uuid'] ?? null,
            'preferred_channel'     => $before['preferred_channel'] ?? null,
        ], [
            'preferred_member_uuid' => $after['preferred_member_uuid'] ?? null,
            'preferred_channel'     => $after['preferred_channel'] ?? null,
        ]);

        Http::data(['appointment_profile' => $after]);
    }

    /**
     * Search Contacts for the booking drawer.
     *
     * A proxy, not a cache. Nothing from the results is stored.
     */
    public static function search(): void
    {
        [$auth, $ctx] = self::enter('appointments.booking.view');

        $term = trim((string) (Http::param('q') ?? ''));
        if (mb_strlen($term) < 2) {
            Http::data(['contacts' => [], 'source' => 'contacts', 'message' => null]);
        }

        $client = new ContactsClient();

        if (!$client->configured() || $auth->sesKey() === '') {
            // Fall back to people this product has booked before, so the
            // drawer is still usable without Contacts.
            Http::data([
                'contacts' => self::searchOwnBookings($ctx, $term),
                'source'   => 'appointments',
                'message'  => 'Aicountly Contacts is not connected, so this searches clients who have booked here before.',
            ]);
        }

        $result = $client->withSession($auth->sesKey())->search($term);

        if (!$result['ok']) {
            Http::data([
                'contacts' => self::searchOwnBookings($ctx, $term),
                'source'   => 'appointments',
                'message'  => 'Aicountly Contacts did not answer, so this searches clients who have booked here before.',
            ]);
        }

        $rows = $result['body']['data'] ?? $result['body']['contacts'] ?? [];

        Http::data([
            'contacts' => array_map(static fn (array $row) => [
                'contact_uuid' => (string) ($row['contact_uuid'] ?? $row['uuid'] ?? $row['id'] ?? ''),
                'label'        => trim((string) ($row['name'] ?? $row['display_name'] ?? '')) ?: 'Contact',
                'phone'        => (string) ($row['mobile'] ?? $row['phone'] ?? ''),
                'email'        => (string) ($row['email'] ?? ''),
            ], array_filter(is_array($rows) ? $rows : [], 'is_array')),
            'source'  => 'contacts',
            'message' => null,
        ]);
    }

    /**
     * Create a client in Contacts.
     *
     * The contact is created THERE and only the uuid comes back. There is no
     * local client record to create and none is created.
     */
    public static function create(): void
    {
        [$auth, $ctx] = self::enter('appointments.booking.create');

        $body = Http::body();
        $name = trim((string) ($body['name'] ?? ''));

        if ($name === '') {
            Http::validationFailed('A name is required.');
        }
        if (trim((string) ($body['phone'] ?? '')) === '' && trim((string) ($body['email'] ?? '')) === '') {
            Http::validationFailed('A phone number or an email address is required.');
        }

        $client = new ContactsClient();

        if (!$client->configured() || $auth->sesKey() === '') {
            // Not an error: the booking can carry the captured details and be
            // matched to a contact later. Said plainly so the UI can too.
            Http::data([
                'contact_uuid' => null,
                'captured'     => ['name' => $name, 'phone' => $body['phone'] ?? '', 'email' => $body['email'] ?? ''],
                'message'      => 'Aicountly Contacts is not connected, so the details will be kept with the appointment '
                    . 'and can be linked to a contact later.',
            ], 200);
        }

        $key = Idempotency::fromRequest() ?? ('appointments-contact-' . substr(hash('sha256', $ctx->cmpId . '|' . $name . '|' . ($body['phone'] ?? '') . '|' . ($body['email'] ?? '')), 0, 32));

        $result = $client->withSession($auth->sesKey())->create([
            'name'   => $name,
            'mobile' => trim((string) ($body['phone'] ?? '')),
            'email'  => trim((string) ($body['email'] ?? '')),
            'source' => 'appointments',
        ], $key);

        if (!$result['ok']) {
            Http::error(503, 'contacts_unavailable', 'Aicountly Contacts could not create the client. Please retry.', [
                'retryable' => true,
            ]);
        }

        $created = $result['body']['data'] ?? $result['body'] ?? [];

        Http::data([
            'contact_uuid' => (string) ($created['contact_uuid'] ?? $created['uuid'] ?? $created['id'] ?? ''),
            'captured'     => ['name' => $name, 'phone' => $body['phone'] ?? '', 'email' => $body['email'] ?? ''],
            'message'      => null,
        ], 201);
    }

    // -----------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private static function searchOwnBookings(\Aicountly\Api\Context $ctx, string $term): array
    {
        $rows = Db::all(
            'SELECT DISTINCT ON (contact_uuid, client_name)
                    contact_uuid, client_name, client_phone, client_email
               FROM ' . BookingService::TABLE . '
              WHERE cmp_id = :cmp
                AND (client_name ILIKE :q OR client_phone ILIKE :q OR client_email ILIKE :q)
              ORDER BY contact_uuid, client_name
              LIMIT 20',
            ['cmp' => $ctx->cmpId, 'q' => '%' . $term . '%'],
        );

        return array_map(static fn (array $row) => [
            'contact_uuid' => $row['contact_uuid'],
            'label'        => trim((string) $row['client_name']) ?: 'Client',
            'phone'        => (string) $row['client_phone'],
            'email'        => (string) $row['client_email'],
        ], $rows);
    }

    /**
     * @param list<mixed> $contactUuids
     * @return array<string, array<string, mixed>>
     */
    private static function profilesFor(\Aicountly\Api\Context $ctx, array $contactUuids): array
    {
        $unique = [];
        foreach ($contactUuids as $uuid) {
            $uuid = trim((string) ($uuid ?? ''));
            if ($uuid !== '') {
                $unique[$uuid] = true;
            }
        }
        if ($unique === []) {
            return [];
        }

        $placeholders = [];
        $params = ['cmp' => $ctx->cmpId];
        foreach (array_keys($unique) as $index => $uuid) {
            $key = 'c' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $uuid;
        }

        $out = [];
        foreach (Db::all(
            'SELECT * FROM ' . ClientProfileService::TABLE . '
              WHERE cmp_id = :cmp AND contact_uuid IN (' . implode(', ', $placeholders) . ')',
            $params,
        ) as $row) {
            $out[(string) $row['contact_uuid']] = $row;
        }

        return $out;
    }
}
