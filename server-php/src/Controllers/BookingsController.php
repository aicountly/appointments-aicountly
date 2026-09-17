<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Clients\CalendarClient;
use Aicountly\Api\Clients\ContactsClient;
use Aicountly\Api\Clients\CrmClient;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\BookingService;
use Aicountly\Api\Domain\ClientProfileService;
use Aicountly\Api\Domain\ReminderService;
use Aicountly\Api\Domain\WaitlistService;
use Aicountly\Api\Http;
use Aicountly\Api\Idempotency;
use Aicountly\Api\Permissions;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

/**
 * Appointments: list, read, book, move, cancel, and walk through the day.
 *
 * ## Booking is idempotent
 *
 * `Idempotency-Key` is honoured on create, reschedule and cancel. A client on a
 * bad connection tapping Book twice, or Receptionist retrying a timed-out call,
 * gets the first answer replayed rather than a second appointment. See
 * Idempotency.
 *
 * ## The detail view reads Calendar
 *
 * `show()` returns the booking's agreed time from this database AND the event's
 * current time from Calendar, separately, so a practitioner who moved the event
 * in Google produces a visible discrepancy rather than a silent disagreement
 * between two screens.
 */
final class BookingsController extends Controller
{
    public static function index(): void
    {
        [, $ctx] = self::enter('appointments.booking.view');

        $params = Http::listParams(['starts_at', 'created_at', 'reference'], 'starts_at', 'asc');
        [$scope, $bind] = $ctx->scopeClause('b');

        $where = [$scope];

        $status = strtoupper(trim((string) (Http::param('status') ?? '')));
        if ($status !== '' && isset(BookingService::TRANSITIONS[$status])) {
            $where[] = 'b.status = :status';
            $bind['status'] = $status;
        }

        if (Http::param('upcoming') !== null) {
            $where[] = 'b.starts_at >= :now';
            $bind['now'] = Clock::sql(Clock::now());
        }

        $from = Clock::parse(Http::param('from'));
        if ($from !== null) {
            $where[] = 'b.starts_at >= :from';
            $bind['from'] = Clock::sql($from);
        }

        $to = Clock::parse(Http::param('to'));
        if ($to !== null) {
            $where[] = 'b.starts_at < :to';
            $bind['to'] = Clock::sql($to);
        }

        $memberUuid = trim((string) (Http::param('member_uuid') ?? ''));
        if ($memberUuid !== '' && Uuid::isValid($memberUuid)) {
            $where[] = 'b.member_uuid = :member';
            $bind['member'] = $memberUuid;
        }

        $serviceUuid = trim((string) (Http::param('service_uuid') ?? ''));
        if ($serviceUuid !== '' && Uuid::isValid($serviceUuid)) {
            $where[] = 'b.service_uuid = :service';
            $bind['service'] = $serviceUuid;
        }

        $contactUuid = trim((string) (Http::param('contact_uuid') ?? ''));
        if ($contactUuid !== '') {
            $where[] = 'b.contact_uuid = :contact';
            $bind['contact'] = $contactUuid;
        }

        // The Overview panels link here with these, so the filtered list a
        // reader lands on is exactly the count they clicked.
        if (Http::param('calendar') === 'failed') {
            $where[] = "b.calendar_sync_state = 'failed'";
        }

        if (Http::param('forms') === 'pending') {
            $where[] = 's.form_uuid IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM appointment_booking_metadata m
                 WHERE m.booking_uuid = b.booking_uuid AND m.kind = \'form_answers\'
            )';
        }

        if (Http::param('payment') === 'pending') {
            $where[] = "s.deposit_required = TRUE AND (b.payment_status IS NULL OR b.payment_status <> 'paid')";
        }

        if ($params['q'] !== '') {
            $where[] = '(b.reference ILIKE :q OR b.client_name ILIKE :q OR b.client_phone ILIKE :q OR b.client_email ILIKE :q)';
            $bind['q'] = '%' . $params['q'] . '%';
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) Db::scalar(
            'SELECT COUNT(*) FROM ' . BookingService::TABLE . ' b
               JOIN appointment_services s ON s.service_uuid = b.service_uuid
              WHERE ' . $whereSql,
            $bind,
        );

        $rows = Db::all(
            'SELECT b.*, s.name AS service_name, s.duration_minutes, s.form_uuid,
                    m.display_label AS member_label, r.name AS resource_name
               FROM ' . BookingService::TABLE . ' b
               JOIN appointment_services s ON s.service_uuid = b.service_uuid
          LEFT JOIN appointment_team_members m ON m.member_uuid = b.member_uuid
          LEFT JOIN appointment_resources r ON r.resource_uuid = b.resource_uuid
              WHERE ' . $whereSql . '
              ORDER BY b.' . $params['sort'] . ' ' . $params['order'] . '
              LIMIT ' . $params['limit'] . ' OFFSET ' . $params['offset'],
            $bind,
        );

        Http::list(
            array_map([self::class, 'shape'], $rows),
            $total,
            $params['limit'],
            $params['offset'],
        );
    }

    public static function show(string $bookingUuid): void
    {
        [$auth, $ctx] = self::enter('appointments.booking.view');

        $row = Db::first(
            'SELECT b.*, s.name AS service_name, s.duration_minutes, s.form_uuid,
                    s.deposit_required, s.deposit_minor, s.currency,
                    m.display_label AS member_label, m.user_uuid AS member_user_uuid,
                    r.name AS resource_name
               FROM ' . BookingService::TABLE . ' b
               JOIN appointment_services s ON s.service_uuid = b.service_uuid
          LEFT JOIN appointment_team_members m ON m.member_uuid = b.member_uuid
          LEFT JOIN appointment_resources r ON r.resource_uuid = b.resource_uuid
              WHERE b.booking_uuid = :id AND b.cmp_id = :cmp',
            ['id' => $bookingUuid, 'cmp' => $ctx->cmpId],
        );

        if ($row === null) {
            Http::notFound('That appointment does not exist.');
        }

        $booking = self::shape($row);

        // The form answers, if a form was filled in. Behind the clients
        // permission, because intake answers are not general reading.
        $metadata = [];
        if (Permissions::allows($ctx, $auth, 'appointments.clients.view')) {
            foreach (Db::all(
                'SELECT kind, payload FROM appointment_booking_metadata WHERE booking_uuid = :id AND cmp_id = :cmp',
                ['id' => $bookingUuid, 'cmp' => $ctx->cmpId],
            ) as $meta) {
                $metadata[(string) $meta['kind']] = Db::jsonColumn($meta['payload'] ?? null);
            }
        }

        Http::data([
            'booking'   => $booking,
            'metadata'  => $metadata,
            'reminders' => array_map(static fn (array $r) => [
                'reminder_uuid' => (string) $r['reminder_uuid'],
                'channel'       => (string) $r['channel'],
                'scheduled_for' => (string) $r['scheduled_for'],
                'status'        => (string) $r['status'],
                'status_detail' => $r['status_detail'],
                'outcome'       => $r['outcome'],
            ], Db::all(
                'SELECT * FROM ' . ReminderService::TABLE . '
                  WHERE booking_uuid = :id AND cmp_id = :cmp ORDER BY scheduled_for',
                ['id' => $bookingUuid, 'cmp' => $ctx->cmpId],
            )),
            // Calendar's current view of the event, alongside ours. When they
            // disagree the UI says so rather than quietly preferring one.
            'calendar'  => self::calendarEventState($auth, $row),
            'client'    => self::clientContext($auth, $ctx, $row),
            'history'   => self::rescheduleChain($ctx, $bookingUuid),
        ]);
    }

    public static function create(): void
    {
        [$auth, $ctx] = self::enter('appointments.booking.create');

        $key = Idempotency::fromRequest();
        $replay = Idempotency::replay($ctx, 'booking.create', $key);
        if ($replay !== null) {
            Http::json($replay['status'], $replay['body']);
        }

        $body = Http::body();

        if (!empty($body['override_rules'])) {
            Permissions::assert($ctx, $auth, 'appointments.booking.override');
        }

        $result = (new BookingService($ctx, $auth))->create($body + [
            'hold_owner' => self::holdOwner($auth),
        ]);

        if (!$result['ok']) {
            self::fail($result['code'], $result['reason']);
        }

        $booking = $result['booking'] ?? [];

        // Bookkeeping that belongs to this product: the client's appointment
        // profile, and the waitlist entry this booking satisfied.
        if (!empty($booking['contact_uuid'])) {
            ClientProfileService::recordBooking($ctx, (string) $booking['contact_uuid']);
        }
        $waitlistUuid = trim((string) ($body['waitlist_uuid'] ?? ''));
        if ($waitlistUuid !== '' && Uuid::isValid($waitlistUuid)) {
            (new WaitlistService($ctx))->markBooked($waitlistUuid, (string) $booking['booking_uuid']);
        }

        $payload = ['data' => ['booking' => self::shape(self::withService($ctx, $booking))]];
        Idempotency::remember($ctx, 'booking.create', $key, 201, $payload);

        Http::json(201, $payload);
    }

    public static function reschedule(string $bookingUuid): void
    {
        [$auth, $ctx] = self::enter('appointments.booking.edit');

        $key = Idempotency::fromRequest();
        $replay = Idempotency::replay($ctx, 'booking.reschedule:' . $bookingUuid, $key);
        if ($replay !== null) {
            Http::json($replay['status'], $replay['body']);
        }

        $body = Http::body();

        if (!empty($body['override_rules'])) {
            Permissions::assert($ctx, $auth, 'appointments.booking.override');
        }

        $result = (new BookingService($ctx, $auth))->reschedule($bookingUuid, $body + [
            'hold_owner' => self::holdOwner($auth),
        ]);

        if (!$result['ok']) {
            self::fail($result['code'], $result['reason']);
        }

        $payload = ['data' => ['booking' => self::shape(self::withService($ctx, $result['booking'] ?? []))]];
        Idempotency::remember($ctx, 'booking.reschedule:' . $bookingUuid, $key, 200, $payload);

        Http::json(200, $payload);
    }

    public static function cancel(string $bookingUuid): void
    {
        [$auth, $ctx] = self::enter('appointments.booking.cancel');

        $key = Idempotency::fromRequest();
        $replay = Idempotency::replay($ctx, 'booking.cancel:' . $bookingUuid, $key);
        if ($replay !== null) {
            Http::json($replay['status'], $replay['body']);
        }

        $body = Http::body();
        $reason = trim((string) ($body['reason'] ?? ''));

        if ($reason === '') {
            // Required, because a cancellation with no reason is a row nobody
            // can learn anything from, and the no-show and policy work in this
            // product depends on knowing why slots came free.
            Http::validationFailed('A reason is required to cancel an appointment.');
        }

        $result = (new BookingService($ctx, $auth))->cancel($bookingUuid, $reason, !empty($body['override_rules']));

        if (!$result['ok']) {
            self::fail($result['code'], $result['reason']);
        }

        $payload = [
            'data' => [
                'booking' => self::shape(self::withService($ctx, $result['booking'] ?? [])),
                // Reported so the UI can say "this was inside the notice
                // period" rather than the caller having to work it out.
                'late_cancellation' => $result['late'],
            ],
        ];
        Idempotency::remember($ctx, 'booking.cancel:' . $bookingUuid, $key, 200, $payload);

        Http::json(200, $payload);
    }

    /**
     * Any other status change: confirm, arrive, start, complete, no-show.
     *
     * One route with the target in the path rather than five near-identical
     * ones, because the rules about which are legal live in
     * BookingService::TRANSITIONS and are the same for all of them.
     */
    public static function transition(string $bookingUuid, string $action): void
    {
        $target = match (strtolower($action)) {
            'confirm'  => 'CONFIRMED',
            'arrive'   => 'ARRIVED',
            'start'    => 'IN_PROGRESS',
            'complete' => 'COMPLETED',
            'no-show'  => 'NO_SHOW',
            default    => null,
        };

        if ($target === null) {
            Http::notFound('There is no "' . $action . '" action.');
        }

        // Marking somebody a no-show has consequences for them — a fee, a
        // prepay requirement, a risk indicator on their next booking — so it
        // needs the cancel permission rather than the looser edit one.
        $permission = $target === 'NO_SHOW'
            ? 'appointments.booking.cancel'
            : 'appointments.booking.edit';

        [$auth, $ctx] = self::enter($permission);

        $body = Http::body();
        $result = (new BookingService($ctx, $auth))->transition(
            $bookingUuid,
            $target,
            trim((string) ($body['reason'] ?? '')),
        );

        if (!$result['ok']) {
            self::fail($result['code'], $result['reason']);
        }

        // A confirmation that came after a reminder is what makes the reminder
        // performance panel worth reading.
        if (in_array($target, ['CONFIRMED', 'ARRIVED'], true)) {
            (new ReminderService($ctx))->recordOutcome($bookingUuid, 'confirmed');
        }

        Http::data(['booking' => self::shape(self::withService($ctx, $result['booking'] ?? []))]);
    }

    /**
     * Retry the Calendar write for a booking whose event never got created.
     *
     * The failure is visible on Live Operations and in the Needs Attention
     * panel; this is the button behind it.
     */
    public static function retryCalendar(string $bookingUuid): void
    {
        [$auth, $ctx] = self::enter('appointments.booking.edit');

        $service = new BookingService($ctx, $auth);
        $booking = $service->row($bookingUuid);

        if ($booking === null) {
            Http::notFound('That appointment does not exist.');
        }

        $written = $service->writeCalendarEvent($bookingUuid);
        $fresh = $service->row($bookingUuid) ?? [];

        Audit::record($ctx, $auth, 'booking.calendar_retry', 'booking', $bookingUuid, [
            'calendar_sync_state' => $booking['calendar_sync_state'],
        ], ['calendar_sync_state' => $fresh['calendar_sync_state'] ?? null]);

        if (!$written) {
            Http::error(503, 'calendar_unavailable', (string) ($fresh['calendar_sync_error'] ?? 'Calendar could not be reached.'), [
                'retryable' => true,
            ]);
        }

        Http::data(['booking' => self::shape(self::withService($ctx, $fresh))]);
    }

    // -----------------------------------------------------------------------

    /**
     * Calendar's current view of this booking's event.
     *
     * Deliberately separate from the booking's own times. When the event has
     * been moved elsewhere, `matches_booking` is false and the UI shows both —
     * which is the only honest thing to do when two products disagree and only
     * one of them owns the answer.
     *
     * @param array<string, mixed> $booking
     * @return array<string, mixed>
     */
    private static function calendarEventState(\Aicountly\Api\Auth $auth, array $booking): array
    {
        $eventUuid = trim((string) ($booking['calendar_event_uuid'] ?? ''));
        $subscriber = trim((string) ($booking['calendar_subscriber_uuid'] ?? ''));

        if ($eventUuid === '' || $subscriber === '') {
            return [
                'state'   => (string) ($booking['calendar_sync_state'] ?? 'pending'),
                'message' => $booking['calendar_sync_error'] ?? 'No calendar entry has been created yet.',
                'event'   => null,
                'matches_booking' => null,
            ];
        }

        $client = new CalendarClient();
        if (!$client->configured()) {
            return [
                'state'   => 'unavailable',
                'message' => 'Aicountly Calendar is not configured for this deployment.',
                'event'   => null,
                'matches_booking' => null,
            ];
        }

        $result = $client->forSubscriber($subscriber)->event($eventUuid);

        if (!$result['ok']) {
            return [
                'state'   => 'unavailable',
                'message' => 'Aicountly Calendar is temporarily unavailable, so the calendar entry could not be read.',
                'event'   => null,
                'matches_booking' => null,
            ];
        }

        $event = $result['body']['data']['event'] ?? $result['body']['event'] ?? null;
        if (!is_array($event)) {
            return ['state' => 'unavailable', 'message' => 'Calendar returned no event.', 'event' => null, 'matches_booking' => null];
        }

        $eventStart = Clock::parse((string) ($event['start_at'] ?? ''));
        $bookingStart = Clock::parse((string) ($booking['starts_at'] ?? ''));

        return [
            'state'   => 'synced',
            'message' => null,
            'event'   => [
                'event_uuid' => (string) ($event['id'] ?? $eventUuid),
                'title'      => (string) ($event['title'] ?? ''),
                'start_at'   => $eventStart !== null ? Clock::iso($eventStart) : null,
                'end_at'     => (string) ($event['end_at'] ?? ''),
                'status'     => (string) ($event['status'] ?? ''),
                'source'     => (string) ($event['source'] ?? ''),
            ],
            'matches_booking' => ($eventStart !== null && $bookingStart !== null)
                ? $eventStart->getTimestamp() === $bookingStart->getTimestamp()
                : null,
        ];
    }

    /**
     * The client, from Contacts and CRM, plus this product's own profile.
     *
     * All three are optional and each degrades on its own: a CRM that is down
     * removes the relationship panel and leaves the rest.
     *
     * @param array<string, mixed> $booking
     * @return array<string, mixed>
     */
    private static function clientContext(\Aicountly\Api\Auth $auth, \Aicountly\Api\Context $ctx, array $booking): array
    {
        $contactUuid = trim((string) ($booking['contact_uuid'] ?? ''));

        $out = [
            'contact_uuid' => $contactUuid !== '' ? $contactUuid : null,
            'captured'     => [
                'name'  => (string) ($booking['client_name'] ?? ''),
                'email' => (string) ($booking['client_email'] ?? ''),
                'phone' => (string) ($booking['client_phone'] ?? ''),
            ],
            'contact'      => null,
            'contact_message' => null,
            'relationship' => null,
            'relationship_message' => null,
            'appointment_profile' => null,
        ];

        if ($contactUuid === '') {
            return $out;
        }

        $out['appointment_profile'] = ClientProfileService::for($ctx, $contactUuid);

        $contacts = new ContactsClient();
        if ($contacts->configured() && $auth->sesKey() !== '') {
            $result = $contacts->withSession($auth->sesKey())->contact($contactUuid);
            if ($result['ok']) {
                $out['contact'] = $result['body']['data'] ?? $result['body'] ?? null;
            } else {
                $out['contact_message'] = 'Aicountly Contacts is temporarily unavailable, so the stored details are shown.';
            }
        } else {
            $out['contact_message'] = 'Aicountly Contacts is not connected for this deployment.';
        }

        $crm = new CrmClient();
        if ($crm->configured() && $auth->sesKey() !== '') {
            $result = $crm->withSession($auth->sesKey())->accountForContact($contactUuid);
            if ($result['ok']) {
                $out['relationship'] = $result['body']['data'] ?? $result['body'] ?? null;
            } else {
                $out['relationship_message'] = 'Aicountly CRM did not answer, so relationship context is not shown.';
            }
        } else {
            $out['relationship_message'] = 'Aicountly CRM is not connected for this deployment.';
        }

        return $out;
    }

    /**
     * The chain of appointments this one was moved from or to.
     *
     * @return list<array<string, mixed>>
     */
    private static function rescheduleChain(\Aicountly\Api\Context $ctx, string $bookingUuid): array
    {
        $rows = Db::all(
            'SELECT booking_uuid, reference, starts_at, status, cancellation_reason, created_at
               FROM ' . BookingService::TABLE . '
              WHERE cmp_id = :cmp
                AND (booking_uuid = :id OR rescheduled_from_uuid = :id
                     OR booking_uuid = (SELECT rescheduled_from_uuid FROM ' . BookingService::TABLE . '
                                         WHERE booking_uuid = :id AND cmp_id = :cmp))
              ORDER BY created_at',
            ['cmp' => $ctx->cmpId, 'id' => $bookingUuid],
        );

        if (count($rows) < 2) {
            return [];
        }

        return array_map(static fn (array $row) => [
            'booking_uuid' => (string) $row['booking_uuid'],
            'reference'    => (string) $row['reference'],
            'starts_at'    => (string) $row['starts_at'],
            'status'       => (string) $row['status'],
            'reason'       => $row['cancellation_reason'],
            'is_current'   => (string) $row['booking_uuid'] === $bookingUuid,
        ], $rows);
    }

    /** @param array<string, mixed> $booking @return array<string, mixed> */
    private static function withService(\Aicountly\Api\Context $ctx, array $booking): array
    {
        if ($booking === [] || isset($booking['service_name'])) {
            return $booking;
        }

        $extra = Db::first(
            'SELECT s.name AS service_name, s.duration_minutes, s.form_uuid,
                    m.display_label AS member_label, r.name AS resource_name
               FROM appointment_services s
          LEFT JOIN appointment_team_members m ON m.member_uuid = :member AND m.cmp_id = :cmp
          LEFT JOIN appointment_resources r ON r.resource_uuid = :resource AND r.cmp_id = :cmp
              WHERE s.service_uuid = :service AND s.cmp_id = :cmp',
            [
                'service'  => $booking['service_uuid'] ?? null,
                'member'   => $booking['member_uuid'] ?? null,
                'resource' => $booking['resource_uuid'] ?? null,
                'cmp'      => $ctx->cmpId,
            ],
        );

        return $booking + ($extra ?? []);
    }

    /**
     * The wire shape of a booking.
     *
     * `starts_at` here is the AGREED time — the booking's own record of what
     * was arranged. Calendar remains the authority on where the event
     * currently sits; `show()` returns both.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        $startsAt = Clock::parse((string) ($row['starts_at'] ?? ''));
        $endsAt = Clock::parse((string) ($row['ends_at'] ?? ''));

        return [
            'booking_uuid'   => (string) ($row['booking_uuid'] ?? ''),
            'reference'      => (string) ($row['reference'] ?? ''),
            'status'         => (string) ($row['status'] ?? ''),
            'mode'           => (string) ($row['mode'] ?? ''),
            'booking_source' => (string) ($row['booking_source'] ?? ''),
            'starts_at'      => $startsAt !== null ? Clock::iso($startsAt) : null,
            'ends_at'        => $endsAt !== null ? Clock::iso($endsAt) : null,
            'timezone'       => (string) ($row['timezone'] ?? 'Asia/Kolkata'),
            'duration_minutes' => isset($row['duration_minutes']) ? (int) $row['duration_minutes'] : null,
            'service'        => [
                'service_uuid' => (string) ($row['service_uuid'] ?? ''),
                'name'         => (string) ($row['service_name'] ?? ''),
                'form_uuid'    => $row['form_uuid'] ?? null,
            ],
            'member'         => $row['member_uuid'] === null ? null : [
                'member_uuid' => (string) $row['member_uuid'],
                'label'       => $row['member_label'],
            ],
            'resource'       => $row['resource_uuid'] === null ? null : [
                'resource_uuid' => (string) $row['resource_uuid'],
                'name'          => $row['resource_name'] ?? null,
            ],
            'client'         => [
                'contact_uuid' => $row['contact_uuid'] ?? null,
                'name'         => (string) ($row['client_name'] ?? ''),
                'email'        => (string) ($row['client_email'] ?? ''),
                'phone'        => (string) ($row['client_phone'] ?? ''),
            ],
            'bo_id'          => (int) ($row['bo_id'] ?? 0),
            'notes'          => (string) ($row['notes'] ?? ''),
            'internal_notes' => (string) ($row['internal_notes'] ?? ''),
            // References to other products, never their data.
            'references'     => [
                'calendar_event_uuid'   => $row['calendar_event_uuid'] ?? null,
                'payment_request_uuid'  => $row['payment_request_uuid'] ?? null,
                'connect_room_uuid'     => $row['connect_room_uuid'] ?? null,
                'connect_join_url'      => $row['connect_join_url'] ?? null,
                'receptionist_session_uuid' => $row['receptionist_session_uuid'] ?? null,
                'crm_account_uuid'      => $row['crm_account_uuid'] ?? null,
                'billing_document_uuid' => $row['billing_document_uuid'] ?? null,
            ],
            'calendar'       => [
                'state' => (string) ($row['calendar_sync_state'] ?? 'pending'),
                'error' => $row['calendar_sync_error'] ?? null,
            ],
            'payment_status' => $row['payment_status'] ?? null,
            'lifecycle'      => [
                'confirmed_at'   => $row['confirmed_at'] ?? null,
                'arrived_at'     => $row['arrived_at'] ?? null,
                'started_at'     => $row['started_at'] ?? null,
                'completed_at'   => $row['completed_at'] ?? null,
                'cancelled_at'   => $row['cancelled_at'] ?? null,
                'no_show_marked_at' => $row['no_show_marked_at'] ?? null,
                'cancellation_reason' => $row['cancellation_reason'] ?? null,
                'reschedule_count' => (int) ($row['reschedule_count'] ?? 0),
                'rescheduled_from' => $row['rescheduled_from_uuid'] ?? null,
            ],
            'rules_overridden' => (bool) ($row['rules_overridden'] ?? false),
            'override_reason'  => $row['override_reason'] ?? null,
            'created_at'       => $row['created_at'] ?? null,
        ];
    }
}
