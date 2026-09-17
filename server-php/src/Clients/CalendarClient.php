<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Env;

/**
 * Live reads and writes against calendar.aicountly.com.
 *
 * CALENDAR OWNS TIME. Not "mostly owns" — owns. There is no calendar_events
 * table in this database, no nightly import, no cache table, no mirror. An
 * appointment row here holds `calendar_event_uuid` and the Appointments-owned
 * facts about the booking (which service, which client, which policy). When a
 * screen needs to know WHEN the appointment is, it asks Calendar on that
 * request.
 *
 * That is not purity for its own sake. A staff member moves a meeting in Google
 * Calendar; Google syncs it to Aicountly Calendar; a copied start time in this
 * database is now wrong and nothing will ever tell it so. Every "why does the
 * dashboard disagree with my phone" bug in a scheduling product starts there.
 *
 * ## How Appointments talks to it
 *
 * With a SERVICE KEY, not the user's session. A receptionist booking a
 * therapist's 3pm is not the therapist, and a public booking page has no
 * session at all. The staff member whose diary is being read or written is
 * named in X-Actor-Uuid, which the Calendar side reads only after the key has
 * matched. See docs/CALENDAR_API_INTEGRATION.md.
 *
 * ## What it must never do
 *
 * Talk to Google, Microsoft or Apple. External calendar synchronisation is
 * Calendar's, entirely. Appointments does not hold a provider token, does not
 * know what a CalDAV collection is, and reports external-calendar status by
 * repeating what Calendar told it.
 */
final class CalendarClient extends ApiClient
{
    /** The staff member whose calendar this instance acts on. */
    private string $actorUuid = '';

    /** Set when a human session is available, for the routes Calendar accepts it on. */
    private string $sesKey = '';

    public function service(): string
    {
        return 'calendar';
    }

    protected function productionBase(): string
    {
        return 'https://calendar.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://calendar.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'CALENDAR_API_BASE';
    }

    /**
     * Act on this subscriber's calendar.
     *
     * Every method below needs one: Calendar has no concept of "the company's
     * calendar", only a person's. A booking against a room is still written to
     * the practitioner's diary, because that is where a human looks.
     */
    public function forSubscriber(string $subscriberUuid): self
    {
        $clone = clone $this;
        $clone->actorUuid = trim($subscriberUuid);

        return $clone;
    }

    public function withSession(string $sesKey): self
    {
        $clone = clone $this;
        $clone->sesKey = trim($sesKey);

        return $clone;
    }

    /**
     * True when this deployment can reach Calendar at all.
     *
     * Checked before a booking is offered, not after it is attempted: a booking
     * flow that discovers on submit that it never had a calendar has already
     * wasted somebody's afternoon.
     */
    public function configured(): bool
    {
        return Env::get('CALENDAR_SERVICE_KEY') !== '';
    }

    /**
     * Busy intervals for several people at once.
     *
     * ONE CALL PER SCREEN, not one per staff member per row. A week's capacity
     * heatmap across eight practitioners is this, once.
     *
     * @param list<string> $subscriberUuids
     * @return array{ok:bool, status:int, body:?array, error:?string}
     */
    public function freeBusy(array $subscriberUuids, string $startIso, string $endIso): array
    {
        $subscribers = implode(',', array_values(array_unique(array_filter(array_map('trim', $subscriberUuids)))));

        return $this->request(
            'GET',
            'calendar/free-busy' . self::query([
                'subscribers' => $subscribers,
                'start'       => $startIso,
                'end'         => $endIso,
            ]),
            null,
            $this->headers(),
        );
    }

    /**
     * The last check before a booking is written.
     *
     * `required: true` — this one gets the long timeout, because the user is
     * watching and a wrong answer double-books a room.
     *
     * @param list<string> $subscriberUuids
     * @param list<string> $ignoreEventIds  the event being rescheduled, so it does not clash with itself
     */
    public function conflictCheck(array $subscriberUuids, string $startIso, string $endIso, array $ignoreEventIds = []): array
    {
        return $this->request('POST', 'calendar/conflict-check', [
            'subscribers'      => array_values(array_unique(array_filter(array_map('trim', $subscriberUuids)))),
            'start_at'         => $startIso,
            'end_at'           => $endIso,
            'ignore_event_ids' => array_values(array_filter($ignoreEventIds)),
        ], $this->headers(), true);
    }

    /** Events on one subscriber's calendar, with their detail. Used by the Appointments calendar UI. */
    public function events(string $startIso, string $endIso): array
    {
        return $this->request(
            'GET',
            'calendar/events' . self::query(['start' => $startIso, 'end' => $endIso]),
            null,
            $this->headers(),
        );
    }

    public function event(string $eventUuid): array
    {
        return $this->request('GET', 'calendar/events/' . rawurlencode($eventUuid), null, $this->headers());
    }

    /**
     * Create the event that IS the appointment's place in time.
     *
     * Everything Appointments knows about the booking that Calendar does not
     * need — the service, the policy, the intake answers — stays here. What
     * goes over is what makes a diary entry readable to the person whose diary
     * it is.
     *
     * @param array<string, mixed> $payload
     */
    public function createEvent(array $payload): array
    {
        return $this->request('POST', 'calendar/events', $payload, $this->headers(), true);
    }

    /** @param array<string, mixed> $patch */
    public function updateEvent(string $eventUuid, array $patch): array
    {
        return $this->request('PATCH', 'calendar/events/' . rawurlencode($eventUuid), $patch, $this->headers(), true);
    }

    /**
     * Cancel, rather than delete, wherever the caller has a choice.
     *
     * A cancelled event keeps the history on the staff member's calendar and
     * stops occupying time; a deleted one simply vanishes, and the practitioner
     * who is asked "what happened to my 3pm" has nothing to look at.
     */
    public function cancelEvent(string $eventUuid): array
    {
        return $this->request('PATCH', 'calendar/events/' . rawurlencode($eventUuid), [
            'status' => 'cancelled',
        ], $this->headers(), true);
    }

    public function deleteEvent(string $eventUuid): array
    {
        return $this->request('DELETE', 'calendar/events/' . rawurlencode($eventUuid), null, $this->headers(), true);
    }

    /**
     * External calendar connection status, repeated verbatim for the Capacity
     * dashboard. Appointments does not interpret it and certainly does not
     * produce it — Google and Outlook are Calendar's business.
     */
    public function providerAccounts(): array
    {
        if ($this->sesKey === '') {
            // Provider accounts are human-session only on the Calendar side, by
            // design: a product service key must not be able to enumerate or
            // disturb somebody's Google connection.
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'calendar_provider_accounts_need_session'];
        }

        return $this->request('GET', 'calendar/provider-accounts', null, ['Authorization' => 'Bearer ' . $this->sesKey]);
    }

    public function health(): array
    {
        return $this->request('GET', 'health', null, []);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $key = Env::get('CALENDAR_SERVICE_KEY');

        if ($key !== '' && $this->actorUuid !== '') {
            return [
                'X-Service-Key' => $key,
                'X-Actor-Uuid'  => $this->actorUuid,
            ];
        }

        // No service key configured: fall back to the caller's own session,
        // which works for the one case that needs no impersonation — a staff
        // member looking at their own diary. Everything else fails loudly at
        // configured(), which is where an administrator can see it.
        if ($this->sesKey !== '') {
            return ['Authorization' => 'Bearer ' . $this->sesKey];
        }

        return [];
    }
}
