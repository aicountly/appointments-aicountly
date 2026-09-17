<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Audit;
use Aicountly\Api\Clients\CalendarClient;
use Aicountly\Api\Clients\ConnectClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;
use DateTimeImmutable;

/**
 * The appointment lifecycle.
 *
 * ## The sequence that matters
 *
 *   revalidate against Calendar  →  write the booking  →  write the Calendar
 *   event  →  record the event id
 *
 * In that order, and the middle step is inside a transaction that the Calendar
 * call is NOT. Two reasons:
 *
 *  1. A booking that exists without its calendar event is recoverable — it is
 *     flagged `calendar_sync_state = 'failed'`, it blocks its own slot through
 *     AvailabilityService::internalBlocks(), and it shows on Live Operations as
 *     needing attention. A calendar event that exists without its booking is
 *     an orphan on somebody's diary that nothing in this product will ever
 *     clean up.
 *
 *  2. Holding a database transaction open across an HTTP call to another
 *     product is how one slow dependency becomes a table full of locks.
 *
 * ## What is NOT stored here
 *
 * The event. Its title, its attendees, its recurrence, whether it synced to
 * Google. Calendar owns all of that. This table holds `calendar_event_uuid` and
 * the booking's own agreed time — see the comment on the migration for why
 * those two are not the same thing.
 */
final class BookingService
{
    public const TABLE = 'appointment_bookings';

    /**
     * Which transitions are legal.
     *
     * Written out rather than checked ad hoc, because "can a NO_SHOW be marked
     * COMPLETED" is a question that gets answered differently in three places
     * otherwise, and one of those places is a receptionist clicking Start on a
     * cancelled appointment.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        'DRAFT'       => ['PENDING', 'CONFIRMED', 'CANCELLED'],
        'PENDING'     => ['CONFIRMED', 'ARRIVED', 'CANCELLED', 'NO_SHOW', 'RESCHEDULED'],
        'CONFIRMED'   => ['ARRIVED', 'IN_PROGRESS', 'CANCELLED', 'NO_SHOW', 'RESCHEDULED', 'COMPLETED'],
        'ARRIVED'     => ['IN_PROGRESS', 'COMPLETED', 'CANCELLED', 'NO_SHOW'],
        'IN_PROGRESS' => ['COMPLETED', 'CANCELLED'],
        'COMPLETED'   => [],
        'RESCHEDULED' => [],
        'CANCELLED'   => [],
        'NO_SHOW'     => ['COMPLETED'],   // they turned up after all
    ];

    /** @var list<string> Statuses that still occupy a slot. */
    public const ACTIVE_STATUSES = ['DRAFT', 'PENDING', 'CONFIRMED', 'ARRIVED', 'IN_PROGRESS'];

    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
        private readonly ?CalendarClient $calendar = null,
        private readonly ?ConnectClient $connect = null,
    ) {
    }

    /**
     * Book an appointment.
     *
     * @param array<string, mixed> $input
     * @return array{ok: bool, booking: ?array<string, mixed>, reason: ?string, code: ?string}
     */
    public function create(array $input): array
    {
        $availability = new AvailabilityService($this->ctx, $this->calendar);

        try {
            $service = $availability->service((string) ($input['service_uuid'] ?? ''));
        } catch (\Throwable) {
            return ['ok' => false, 'booking' => null, 'reason' => 'That service does not exist.', 'code' => 'unknown_service'];
        }

        if (!$service['is_active']) {
            return ['ok' => false, 'booking' => null, 'reason' => 'That service is no longer offered.', 'code' => 'service_inactive'];
        }

        $start = Clock::parse((string) ($input['starts_at'] ?? ''));
        if ($start === null) {
            return ['ok' => false, 'booking' => null, 'reason' => 'A start time is required.', 'code' => 'invalid_range'];
        }

        $memberUuid = $this->resolveMember($input, $service, $availability, $start);
        if ($memberUuid === null) {
            return ['ok' => false, 'booking' => null, 'reason' => 'No practitioner is available for that service.', 'code' => 'no_staff'];
        }

        $member = $availability->member($memberUuid);
        if ($member === null) {
            return ['ok' => false, 'booking' => null, 'reason' => 'That team member is not part of this company.', 'code' => 'unknown_member'];
        }

        $duration = (int) ($input['duration_minutes'] ?? $service['duration_minutes']);
        $end = Clock::parse((string) ($input['ends_at'] ?? '')) ?? $start->modify('+' . $duration . ' minutes');
        if ($end <= $start) {
            return ['ok' => false, 'booking' => null, 'reason' => 'The appointment must end after it starts.', 'code' => 'invalid_range'];
        }

        $mode = $this->resolveMode($input, $service);
        if ($mode === null) {
            return [
                'ok'      => false,
                'booking' => null,
                'reason'  => 'That appointment mode is not offered for this service.',
                'code'    => 'mode_not_offered',
            ];
        }

        $resourceUuids = $availability->requiredResourceUuids((string) $service['service_uuid']);
        $override = (bool) ($input['override_rules'] ?? false);

        // The booking rules: notice, horizon, and whether a deposit is payable
        // at all in a deployment with no Pay.
        $ruleCheck = $this->checkRules($service, $start, $override);
        if (!$ruleCheck['ok']) {
            return ['ok' => false, 'booking' => null, 'reason' => $ruleCheck['reason'], 'code' => $ruleCheck['code']];
        }

        // ------------------------------------------------------------------
        // The last check before anything is written. A hold shortens the
        // window this covers; it does not remove the need for it, because the
        // practitioner may have added something to their own Google calendar
        // in the meantime.
        // ------------------------------------------------------------------
        $holdUuid = $this->consumableHold($input);
        $check = $availability->revalidate(
            (string) $service['service_uuid'],
            $memberUuid,
            $resourceUuids,
            $start,
            $end,
            null,
            $holdUuid,
        );

        if (!$check['free']) {
            return [
                'ok'      => false,
                'booking' => null,
                'reason'  => $check['reason'],
                'code'    => $check['checked'] ? 'slot_taken' : 'calendar_unavailable',
            ];
        }

        // ------------------------------------------------------------------
        // Write the booking. Transaction covers OUR tables only.
        // ------------------------------------------------------------------
        $bookingUuid = Uuid::v4();
        $settings = Settings::for($this->ctx);
        $rule = $this->rulesFor($service);

        $status = $this->initialStatus($service, $rule, $settings, $input);

        $row = Db::transaction(function () use (
            $bookingUuid, $service, $member, $memberUuid, $resourceUuids,
            $start, $end, $mode, $status, $input, $settings, $override, $holdUuid
        ): array {
            $reference = Settings::nextReference($this->ctx);

            Db::insert(self::TABLE, [
                'booking_uuid'   => $bookingUuid,
                'cmp_id'         => $this->ctx->cmpId,
                'bo_id'          => (int) ($input['bo_id'] ?? $this->ctx->boId),
                'reference'      => $reference,
                'service_uuid'   => $service['service_uuid'],
                'member_uuid'    => $memberUuid,
                'resource_uuid'  => $resourceUuids[0] ?? null,
                'contact_uuid'   => $this->trimOrNull($input['contact_uuid'] ?? null),
                'client_name'    => substr(trim((string) ($input['client_name'] ?? '')), 0, 200),
                'client_email'   => substr(trim((string) ($input['client_email'] ?? '')), 0, 200),
                'client_phone'   => substr(trim((string) ($input['client_phone'] ?? '')), 0, 40),
                'starts_at'      => Clock::sql($start),
                'ends_at'        => Clock::sql($end),
                'timezone'       => (string) ($input['timezone'] ?? $member['timezone'] ?? $settings['timezone']),
                'status'         => $status,
                'mode'           => $mode,
                // Proven by the credential. A body claiming RECEPTIONIST from a
                // browser session gets STAFF_BOOKING.
                'booking_source' => $this->resolveSource($input),
                'calendar_subscriber_uuid' => (string) $member['calendar_subscriber_uuid'],
                'calendar_sync_state' => 'pending',
                'receptionist_session_uuid' => $this->trimOrNull($input['receptionist_session_uuid'] ?? null),
                'crm_account_uuid'   => $this->trimOrNull($input['crm_account_uuid'] ?? null),
                'notes'          => substr(trim((string) ($input['notes'] ?? '')), 0, 4000),
                'internal_notes' => substr(trim((string) ($input['internal_notes'] ?? '')), 0, 4000),
                'rules_overridden' => $override,
                'override_reason'  => $override ? substr(trim((string) ($input['override_reason'] ?? '')), 0, 500) : null,
                'confirmed_at'     => $status === 'CONFIRMED' ? Clock::sql(Clock::now()) : null,
                'confirmed_by_uuid' => $status === 'CONFIRMED' ? $this->auth->uuid : null,
                'created_by_uuid'  => $this->auth->uuid,
                'created_by_kind'  => $this->auth->kind,
            ], 'booking_uuid');

            if ($holdUuid !== null) {
                (new SlotHoldService($this->ctx, $this->calendar))->consume($holdUuid, $bookingUuid);
            }

            if (isset($input['form_answers']) && is_array($input['form_answers'])) {
                $this->saveMetadata($bookingUuid, 'form_answers', $input['form_answers']);
            }

            return $this->row($bookingUuid) ?? [];
        });

        // ------------------------------------------------------------------
        // Outside the transaction: the other products.
        // ------------------------------------------------------------------
        if ($mode === 'AICOUNTLY_CONNECT') {
            $this->attachConnectRoom($bookingUuid, $row, $service, $start, $end, (string) $member['user_uuid']);
        }

        $this->writeCalendarEvent($bookingUuid);

        (new ReminderService($this->ctx))->scheduleFor($bookingUuid);

        $fresh = $this->row($bookingUuid) ?? $row;

        Audit::record($this->ctx, $this->auth, 'booking.created', 'booking', $bookingUuid, null, [
            'reference' => $fresh['reference'] ?? null,
            'status'    => $fresh['status'] ?? null,
            'starts_at' => $fresh['starts_at'] ?? null,
            'source'    => $fresh['booking_source'] ?? null,
        ]);

        return ['ok' => true, 'booking' => $fresh, 'reason' => null, 'code' => null];
    }

    /**
     * Move an appointment.
     *
     * A reschedule is a NEW booking linked to the old one, not an edit. The old
     * one becomes RESCHEDULED and keeps its history — which is what lets a
     * no-show risk indicator say "moved three times" and a client profile show
     * what actually happened rather than only where it ended up.
     *
     * @param array<string, mixed> $input
     * @return array{ok: bool, booking: ?array<string, mixed>, reason: ?string, code: ?string}
     */
    public function reschedule(string $bookingUuid, array $input): array
    {
        $existing = $this->row($bookingUuid);
        if ($existing === null) {
            return ['ok' => false, 'booking' => null, 'reason' => 'That appointment does not exist.', 'code' => 'not_found'];
        }
        if (!$this->canTransition((string) $existing['status'], 'RESCHEDULED')) {
            return [
                'ok'      => false,
                'booking' => null,
                'reason'  => 'A ' . strtolower((string) $existing['status']) . ' appointment cannot be rescheduled.',
                'code'    => 'illegal_transition',
            ];
        }

        $service = (new AvailabilityService($this->ctx, $this->calendar))->service((string) $existing['service_uuid']);
        $rule = $this->rulesFor($service);
        $override = (bool) ($input['override_rules'] ?? false);

        if (!$override && (int) $existing['reschedule_count'] >= (int) $rule['max_reschedules']) {
            return [
                'ok'      => false,
                'booking' => null,
                'reason'  => 'This appointment has already been moved ' . (int) $existing['reschedule_count'] . ' times.',
                'code'    => 'reschedule_limit',
            ];
        }

        $noticeHours = (int) $rule['reschedule_notice_hours'];
        $startsAt = Clock::parse((string) $existing['starts_at']);
        if (!$override && $startsAt !== null && $noticeHours > 0) {
            if (Clock::minutesBetween(Clock::now(), $startsAt) < $noticeHours * 60) {
                return [
                    'ok'      => false,
                    'booking' => null,
                    'reason'  => 'This appointment needs ' . $noticeHours . ' hours\' notice to move.',
                    'code'    => 'notice_period',
                ];
            }
        }

        $created = $this->create([
            'service_uuid'   => $existing['service_uuid'],
            'member_uuid'    => $input['member_uuid'] ?? $existing['member_uuid'],
            'starts_at'      => $input['starts_at'] ?? null,
            'ends_at'        => $input['ends_at'] ?? null,
            'bo_id'          => $existing['bo_id'],
            'contact_uuid'   => $existing['contact_uuid'],
            'client_name'    => $existing['client_name'],
            'client_email'   => $existing['client_email'],
            'client_phone'   => $existing['client_phone'],
            'mode'           => $input['mode'] ?? $existing['mode'],
            'timezone'       => $existing['timezone'],
            'notes'          => $existing['notes'],
            'internal_notes' => $existing['internal_notes'],
            'hold_uuid'      => $input['hold_uuid'] ?? null,
            'hold_owner'     => $input['hold_owner'] ?? null,
            'override_rules' => $override,
            'override_reason' => $input['override_reason'] ?? null,
        ]);

        if (!$created['ok']) {
            return $created;
        }

        $newUuid = (string) $created['booking']['booking_uuid'];

        Db::update(self::TABLE, [
            'rescheduled_from_uuid' => $bookingUuid,
            'reschedule_count'      => (int) $existing['reschedule_count'] + 1,
            'updated_at'            => Clock::sql(Clock::now()),
        ], ['booking_uuid' => $newUuid, 'cmp_id' => $this->ctx->cmpId]);

        // Release the old slot: cancel the event, then close the booking.
        $this->cancelCalendarEvent($existing);

        Db::update(self::TABLE, [
            'status'              => 'RESCHEDULED',
            'cancellation_reason' => substr(trim((string) ($input['reason'] ?? 'Rescheduled')), 0, 500),
            'updated_at'          => Clock::sql(Clock::now()),
        ], ['booking_uuid' => $bookingUuid, 'cmp_id' => $this->ctx->cmpId]);

        (new ReminderService($this->ctx))->cancelFor($bookingUuid, 'Appointment rescheduled');

        Audit::record($this->ctx, $this->auth, 'booking.rescheduled', 'booking', $bookingUuid, [
            'starts_at' => $existing['starts_at'],
        ], [
            'moved_to'  => $newUuid,
            'starts_at' => $created['booking']['starts_at'] ?? null,
        ], (string) ($input['reason'] ?? ''));

        return ['ok' => true, 'booking' => $this->row($newUuid), 'reason' => null, 'code' => null];
    }

    /**
     * Cancel.
     *
     * The Calendar event is CANCELLED rather than deleted wherever possible, so
     * the practitioner can still see what was there. The slot is freed either
     * way, because a cancelled event does not occupy time.
     *
     * @return array{ok: bool, booking: ?array<string, mixed>, reason: ?string, code: ?string, late: bool}
     */
    public function cancel(string $bookingUuid, string $reason, bool $override = false): array
    {
        $existing = $this->row($bookingUuid);
        if ($existing === null) {
            return ['ok' => false, 'booking' => null, 'reason' => 'That appointment does not exist.', 'code' => 'not_found', 'late' => false];
        }
        if (!$this->canTransition((string) $existing['status'], 'CANCELLED')) {
            return [
                'ok'      => false,
                'booking' => null,
                'reason'  => 'A ' . strtolower((string) $existing['status']) . ' appointment cannot be cancelled.',
                'code'    => 'illegal_transition',
                'late'    => false,
            ];
        }

        $service = (new AvailabilityService($this->ctx, $this->calendar))->service((string) $existing['service_uuid']);
        $rule = $this->rulesFor($service);
        $startsAt = Clock::parse((string) $existing['starts_at']);
        $noticeHours = (int) $rule['cancellation_notice_hours'];

        // "Late" is recorded, not refused. Somebody cancelling two hours before
        // is a fact the business needs on a dashboard and possibly a fee; it is
        // not a reason to keep a slot blocked that everybody knows is free.
        $late = $startsAt !== null
            && $noticeHours > 0
            && Clock::minutesBetween(Clock::now(), $startsAt) < $noticeHours * 60;

        $this->cancelCalendarEvent($existing);

        Db::update(self::TABLE, [
            'status'              => 'CANCELLED',
            'cancelled_at'        => Clock::sql(Clock::now()),
            'cancelled_by_uuid'   => $this->auth->uuid,
            'cancellation_reason' => substr(trim($reason), 0, 500),
            'updated_at'          => Clock::sql(Clock::now()),
        ], ['booking_uuid' => $bookingUuid, 'cmp_id' => $this->ctx->cmpId]);

        (new ReminderService($this->ctx))->cancelFor($bookingUuid, 'Appointment cancelled');

        if ($late && !empty($existing['contact_uuid'])) {
            ClientProfileService::recordLateCancellation($this->ctx, (string) $existing['contact_uuid']);
        }

        if (!empty($existing['connect_room_uuid'])) {
            ($this->connect ?? new ConnectClient())->closeRoom((string) $existing['connect_room_uuid']);
        }

        Audit::record($this->ctx, $this->auth, 'booking.cancelled', 'booking', $bookingUuid, [
            'status' => $existing['status'],
        ], ['status' => 'CANCELLED', 'late' => $late], $reason);

        return ['ok' => true, 'booking' => $this->row($bookingUuid), 'reason' => null, 'code' => null, 'late' => $late];
    }

    /**
     * Any other status change: confirm, arrive, start, complete, no-show.
     *
     * @return array{ok: bool, booking: ?array<string, mixed>, reason: ?string, code: ?string}
     */
    public function transition(string $bookingUuid, string $target, string $reason = ''): array
    {
        $target = strtoupper(trim($target));
        $existing = $this->row($bookingUuid);

        if ($existing === null) {
            return ['ok' => false, 'booking' => null, 'reason' => 'That appointment does not exist.', 'code' => 'not_found'];
        }
        if ($target === 'CANCELLED') {
            $cancelled = $this->cancel($bookingUuid, $reason);

            return ['ok' => $cancelled['ok'], 'booking' => $cancelled['booking'], 'reason' => $cancelled['reason'], 'code' => $cancelled['code']];
        }
        if (!$this->canTransition((string) $existing['status'], $target)) {
            return [
                'ok'      => false,
                'booking' => null,
                'reason'  => 'An appointment cannot go from ' . strtolower((string) $existing['status'])
                    . ' to ' . strtolower($target) . '.',
                'code'    => 'illegal_transition',
            ];
        }

        $now = Clock::sql(Clock::now());
        $patch = ['status' => $target, 'updated_at' => $now];

        match ($target) {
            'CONFIRMED'   => $patch += ['confirmed_at' => $now, 'confirmed_by_uuid' => $this->auth->uuid],
            'ARRIVED'     => $patch += ['arrived_at' => $now],
            'IN_PROGRESS' => $patch += ['started_at' => $now],
            'COMPLETED'   => $patch += ['completed_at' => $now],
            'NO_SHOW'     => $patch += ['no_show_marked_at' => $now],
            default       => null,
        };

        Db::update(self::TABLE, $patch, ['booking_uuid' => $bookingUuid, 'cmp_id' => $this->ctx->cmpId]);

        if (in_array($target, ['COMPLETED', 'NO_SHOW'], true)) {
            (new ReminderService($this->ctx))->cancelFor($bookingUuid, 'Appointment ' . strtolower($target));
            if (!empty($existing['contact_uuid'])) {
                ClientProfileService::recordOutcome($this->ctx, (string) $existing['contact_uuid'], $target);
            }
        }

        if ($target === 'CONFIRMED') {
            // A confirmed appointment is no longer tentative on the diary.
            $this->syncCalendarStatus($existing, 'confirmed');
        }

        Audit::record($this->ctx, $this->auth, 'booking.status_changed', 'booking', $bookingUuid, [
            'status' => $existing['status'],
        ], ['status' => $target], $reason);

        return ['ok' => true, 'booking' => $this->row($bookingUuid), 'reason' => null, 'code' => null];
    }

    public function canTransition(string $from, string $to): bool
    {
        return in_array(strtoupper($to), self::TRANSITIONS[strtoupper($from)] ?? [], true);
    }

    /** @return array<string, mixed>|null */
    public function row(string $bookingUuid): ?array
    {
        if (!Uuid::isValid($bookingUuid)) {
            return null;
        }

        return Db::first(
            'SELECT * FROM ' . self::TABLE . ' WHERE booking_uuid = :id AND cmp_id = :cmp',
            ['id' => $bookingUuid, 'cmp' => $this->ctx->cmpId],
        );
    }

    // =======================================================================
    // Calendar
    // =======================================================================

    /**
     * Write the Calendar event for a booking, and record its id.
     *
     * Deliberately best-effort and deliberately loud on failure. A booking with
     * `calendar_sync_state = 'failed'` still blocks its slot here and appears
     * on Live Operations, so a Calendar outage costs a retry rather than a
     * double booking.
     */
    public function writeCalendarEvent(string $bookingUuid): bool
    {
        $booking = $this->row($bookingUuid);
        if ($booking === null) {
            return false;
        }
        if (($booking['calendar_sync_state'] ?? '') === 'synced' && !empty($booking['calendar_event_uuid'])) {
            return true;
        }

        $subscriber = trim((string) ($booking['calendar_subscriber_uuid'] ?? ''));
        if ($subscriber === '') {
            Db::update(self::TABLE, ['calendar_sync_state' => 'not_required'], [
                'booking_uuid' => $bookingUuid, 'cmp_id' => $this->ctx->cmpId,
            ]);

            return true;
        }

        $client = $this->calendar ?? new CalendarClient();
        if (!$client->configured()) {
            $this->markCalendarFailure($bookingUuid, 'Calendar service key is not configured for this deployment.');

            return false;
        }

        $service = Db::first(
            'SELECT name FROM appointment_services WHERE service_uuid = :id AND cmp_id = :cmp',
            ['id' => $booking['service_uuid'], 'cmp' => $this->ctx->cmpId],
        );

        $clientLabel = trim((string) ($booking['client_name'] ?? '')) ?: 'Client';
        $title = trim(sprintf('%s — %s', $service['name'] ?? 'Appointment', $clientLabel));

        $description = trim(implode("\n", array_filter([
            'Aicountly Appointments · ' . (string) $booking['reference'],
            $booking['mode'] === 'AICOUNTLY_CONNECT' && !empty($booking['connect_join_url'])
                ? 'Join: ' . $booking['connect_join_url'] : null,
            trim((string) ($booking['notes'] ?? '')) !== '' ? (string) $booking['notes'] : null,
        ])));

        $result = $client->forSubscriber($subscriber)->createEvent([
            'title'       => $title,
            'description' => $description,
            'start_at'    => Clock::iso(Clock::parse((string) $booking['starts_at']) ?? Clock::now()),
            'end_at'      => Clock::iso(Clock::parse((string) $booking['ends_at']) ?? Clock::now()),
            'timezone'    => (string) $booking['timezone'],
            'category'    => 'meeting',
            'status'      => $booking['status'] === 'CONFIRMED' ? 'confirmed' : 'tentative',
            'source'      => 'aicountly_native',
        ]);

        if (!$result['ok']) {
            $this->markCalendarFailure($bookingUuid, (string) ($result['error'] ?? 'Calendar rejected the event.'));

            return false;
        }

        $event = $result['body']['data']['event'] ?? $result['body']['event'] ?? $result['body']['data'] ?? [];
        $eventUuid = trim((string) ($event['id'] ?? ''));

        if ($eventUuid === '') {
            $this->markCalendarFailure($bookingUuid, 'Calendar accepted the event but returned no id.');

            return false;
        }

        Db::update(self::TABLE, [
            'calendar_event_uuid' => $eventUuid,
            'calendar_sync_state' => 'synced',
            'calendar_sync_error' => null,
            'updated_at'          => Clock::sql(Clock::now()),
        ], ['booking_uuid' => $bookingUuid, 'cmp_id' => $this->ctx->cmpId]);

        return true;
    }

    /** @param array<string, mixed> $booking */
    private function cancelCalendarEvent(array $booking): void
    {
        $eventUuid = trim((string) ($booking['calendar_event_uuid'] ?? ''));
        $subscriber = trim((string) ($booking['calendar_subscriber_uuid'] ?? ''));

        if ($eventUuid === '' || $subscriber === '') {
            return;
        }

        $result = ($this->calendar ?? new CalendarClient())->forSubscriber($subscriber)->cancelEvent($eventUuid);

        if (!$result['ok']) {
            // Logged, not raised. The appointment IS cancelled as far as this
            // business is concerned; an event left on a diary is a smaller
            // problem than a cancel button that appears not to work.
            error_log('[booking] could not cancel calendar event ' . $eventUuid . ': ' . (string) ($result['error'] ?? 'unknown'));
        }
    }

    /** @param array<string, mixed> $booking */
    private function syncCalendarStatus(array $booking, string $calendarStatus): void
    {
        $eventUuid = trim((string) ($booking['calendar_event_uuid'] ?? ''));
        $subscriber = trim((string) ($booking['calendar_subscriber_uuid'] ?? ''));

        if ($eventUuid === '' || $subscriber === '') {
            return;
        }

        ($this->calendar ?? new CalendarClient())
            ->forSubscriber($subscriber)
            ->updateEvent($eventUuid, ['status' => $calendarStatus]);
    }

    private function markCalendarFailure(string $bookingUuid, string $error): void
    {
        error_log('[booking] calendar event failed for ' . $bookingUuid . ': ' . $error);

        Db::update(self::TABLE, [
            'calendar_sync_state' => 'failed',
            'calendar_sync_error' => substr($error, 0, 500),
            'updated_at'          => Clock::sql(Clock::now()),
        ], ['booking_uuid' => $bookingUuid, 'cmp_id' => $this->ctx->cmpId]);
    }

    // =======================================================================
    // Connect
    // =======================================================================

    /** @param array<string, mixed> $booking @param array<string, mixed> $service */
    private function attachConnectRoom(
        string $bookingUuid,
        array $booking,
        array $service,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        string $hostUuid,
    ): void {
        $client = $this->connect ?? new ConnectClient();
        if (!$client->configured()) {
            return;
        }

        $result = $client->createRoom([
            'subject'   => (string) $service['name'],
            'starts_at' => Clock::iso($start),
            'ends_at'   => Clock::iso($end),
            'host_uuid' => $hostUuid,
            'reference' => (string) ($booking['reference'] ?? $bookingUuid),
        ], 'appointment-room-' . $bookingUuid);

        if (!$result['ok']) {
            error_log('[booking] Connect room failed for ' . $bookingUuid . ': ' . (string) ($result['error'] ?? 'unknown'));

            return;
        }

        $room = $result['body']['data'] ?? $result['body'] ?? [];

        Db::update(self::TABLE, [
            'connect_room_uuid' => $this->trimOrNull($room['room_uuid'] ?? $room['id'] ?? null),
            'connect_join_url'  => $this->trimOrNull($room['join_url'] ?? $room['url'] ?? null),
            'updated_at'        => Clock::sql(Clock::now()),
        ], ['booking_uuid' => $bookingUuid, 'cmp_id' => $this->ctx->cmpId]);
    }

    // =======================================================================
    // Rules and helpers
    // =======================================================================

    /**
     * @param array<string, mixed> $service
     * @return array{ok: bool, reason: ?string, code: ?string}
     */
    private function checkRules(array $service, DateTimeImmutable $start, bool $override): array
    {
        if ($override) {
            return ['ok' => true, 'reason' => null, 'code' => null];
        }

        $now = Clock::now();
        $notice = (int) $service['min_notice_minutes'];

        if ($notice > 0 && Clock::minutesBetween($now, $start) < $notice) {
            $hours = intdiv($notice, 60);
            $phrase = $hours >= 1
                ? $hours . ' hour' . ($hours === 1 ? '' : 's')
                : $notice . ' minutes';

            return ['ok' => false, 'reason' => 'This service needs ' . $phrase . ' notice.', 'code' => 'notice_period'];
        }

        $horizon = $now->modify('+' . (int) $service['booking_horizon_days'] . ' days');
        if ($start > $horizon) {
            return [
                'ok'     => false,
                'reason' => 'This service can only be booked ' . (int) $service['booking_horizon_days'] . ' days ahead.',
                'code'   => 'beyond_horizon',
            ];
        }

        // A deposit-required service in a deployment with no Pay cannot be
        // taken online: confirming it would say money had been collected when
        // nothing collected it.
        if ($service['deposit_required'] && !Settings::featureEnabled($this->ctx, 'PAY')) {
            if (!$this->auth->isService() && $this->auth->kind === 'public') {
                return [
                    'ok'     => false,
                    'reason' => 'This service requires a deposit and the payment service is not available yet.',
                    'code'   => 'payment_unavailable',
                ];
            }
        }

        return ['ok' => true, 'reason' => null, 'code' => null];
    }

    /** @param array<string, mixed> $service @return array<string, mixed> */
    public function rulesFor(array $service): array
    {
        $ruleUuid = $service['cancellation_rule_uuid'] ?? null;

        if ($ruleUuid !== null) {
            $row = Db::first(
                'SELECT * FROM appointment_booking_rules WHERE rule_uuid = :id AND cmp_id = :cmp',
                ['id' => $ruleUuid, 'cmp' => $this->ctx->cmpId],
            );
            if ($row !== null) {
                return $row;
            }
        }

        $fallback = Db::first(
            'SELECT * FROM appointment_booking_rules WHERE cmp_id = :cmp AND is_default = TRUE',
            ['cmp' => $this->ctx->cmpId],
        );

        return $fallback ?? [
            'cancellation_notice_hours' => 24,
            'reschedule_notice_hours'   => 12,
            'max_reschedules'           => 3,
            'no_show_after_minutes'     => 15,
            'requires_confirmation'     => false,
            'allow_client_cancellation' => true,
            'allow_client_reschedule'   => true,
            'auto_release_unconfirmed_hours' => null,
            'late_cancellation_fee_minor' => null,
            'no_show_fee_minor'         => null,
        ];
    }

    /**
     * @param array<string, mixed> $service
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $input
     */
    private function initialStatus(array $service, array $rule, array $settings, array $input): string
    {
        if (isset($input['status']) && $input['status'] === 'DRAFT') {
            return 'DRAFT';
        }
        if ($rule['requires_confirmation']) {
            return 'PENDING';
        }
        if ($service['deposit_required'] && Settings::featureEnabled($this->ctx, 'PAY')) {
            // The slot is held as PENDING until Pay says the deposit landed.
            return 'PENDING';
        }
        if ($this->auth->kind === 'public' && !$settings['auto_confirm_online']) {
            return 'PENDING';
        }

        return 'CONFIRMED';
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $service */
    private function resolveMember(array $input, array $service, AvailabilityService $availability, DateTimeImmutable $start): ?string
    {
        $requested = $this->trimOrNull($input['member_uuid'] ?? null);
        if ($requested !== null) {
            return $requested;
        }

        // "Any available" means the highest-priority eligible person who is
        // actually free, so it is resolved against real availability rather
        // than by picking the first row.
        $result = $availability->findSlots([
            'service_uuid' => (string) $service['service_uuid'],
            'from'         => $start->modify('-1 minute'),
            'to'           => $start->modify('+1 minute'),
            'bo_id'        => $input['bo_id'] ?? null,
            'limit'        => 1,
        ]);

        if ($result['slots'] !== []) {
            return (string) $result['slots'][0]['member_uuid'];
        }

        $members = $availability->eligibleMembers($service, null, $input['bo_id'] ?? null);

        return $members === [] ? null : (string) $members[0]['member_uuid'];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $service */
    private function resolveMode(array $input, array $service): ?string
    {
        $requested = strtoupper(trim((string) ($input['mode'] ?? '')));
        $offered = array_map('strtoupper', array_filter($service['modes'], 'is_string'));

        if ($offered === []) {
            $offered = ['IN_PERSON'];
        }
        if ($requested === '') {
            return $offered[0];
        }
        if (!in_array($requested, $offered, true)) {
            return null;
        }
        if ($requested === 'AICOUNTLY_CONNECT' && !Settings::featureEnabled($this->ctx, 'CONNECT')) {
            // Not an error: the appointment happens, it just does not get a
            // room. A video service being down is not a reason to refuse a
            // booking that could equally be a phone call.
            return 'PHONE';
        }

        return $requested;
    }

    /** @param array<string, mixed> $input */
    private function resolveSource(array $input): string
    {
        if ($this->auth->kind === 'public') {
            return 'ONLINE_BOOKING';
        }

        $claimed = strtoupper(trim((string) ($input['booking_source'] ?? '')));

        // A staff member may legitimately record that a booking came off a
        // waitlist offer — that is workflow, not provenance. Everything else
        // is decided by the credential.
        if ($claimed === 'WAITLIST_OFFER' && !$this->auth->isService()) {
            return 'WAITLIST_OFFER';
        }

        return $this->auth->provenBookingSource();
    }

    /** @param array<string, mixed> $input */
    private function consumableHold(array $input): ?string
    {
        $holdUuid = $this->trimOrNull($input['hold_uuid'] ?? null);
        $owner = $this->trimOrNull($input['hold_owner'] ?? null) ?? $this->auth->fingerprint();

        if ($holdUuid === null) {
            return null;
        }

        $hold = (new SlotHoldService($this->ctx, $this->calendar))->claim($holdUuid, $owner);

        return $hold === null ? null : $holdUuid;
    }

    /** @param array<string, mixed> $payload */
    private function saveMetadata(string $bookingUuid, string $kind, array $payload): void
    {
        Db::run(
            'INSERT INTO appointment_booking_metadata (booking_uuid, cmp_id, kind, payload)
             VALUES (:booking, :cmp, :kind, :payload)
             ON CONFLICT (booking_uuid, kind)
             DO UPDATE SET payload = EXCLUDED.payload, updated_at = NOW()',
            [
                'booking' => $bookingUuid,
                'cmp'     => $this->ctx->cmpId,
                'kind'    => $kind,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ],
        );
    }

    private function trimOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
