<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Clients\MessagingClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Features;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

/**
 * Appointment reminders: the rules, the timers, and the handoff to a provider.
 *
 * ## Appointments owns its own timers, on purpose
 *
 * There is no central automation engine in this fleet and there should not be.
 * A reminder is a fact about an appointment: when the appointment moves, the
 * reminder moves; when it is cancelled, the reminder is cancelled. The product
 * that knows the appointment changed is the only one that can keep that
 * promise, so the schedule lives here, in this product's own table, computed
 * from this product's own rules.
 *
 * ## It does not own delivery
 *
 * Sending is a provider relationship — template approval, sender identity,
 * delivery receipts — and belongs to whatever messaging product the deployment
 * has. See Clients/MessagingClient.php.
 *
 * ## With no provider connected
 *
 * Reminders are still computed and still scheduled, and `dispatchDue()` marks
 * them `not_sent` with the reason in `status_detail`. They are not silently
 * dropped and they are never reported as delivered. A reminder performance
 * panel showing 96% delivery on messages nobody sent is worse than an empty
 * one, because somebody trusts it.
 *
 * ## Voice
 *
 * A reminder phone call is a conversation, and conversations are Receptionist's.
 * A `voice` step is scheduled here and left for Receptionist to pick up; this
 * service will not try to place a call.
 */
final class ReminderService
{
    public const TABLE = 'appointment_reminders';

    /** How long after its due time a reminder is still worth sending. */
    private const STALE_AFTER_MINUTES = 90;

    public function __construct(
        private readonly Context $ctx,
        private readonly ?MessagingClient $messaging = null,
    ) {
    }

    /**
     * Compute and store the reminder schedule for a booking.
     *
     * Idempotent: existing scheduled reminders for the booking are cleared
     * first, which is what makes this safe to call again after a reschedule.
     * Reminders already sent are left alone — history, not schedule.
     */
    public function scheduleFor(string $bookingUuid): int
    {
        $booking = Db::first(
            'SELECT b.*, s.reminder_rule_uuid
               FROM ' . BookingService::TABLE . ' b
               JOIN appointment_services s ON s.service_uuid = b.service_uuid
              WHERE b.booking_uuid = :id AND b.cmp_id = :cmp',
            ['id' => $bookingUuid, 'cmp' => $this->ctx->cmpId],
        );

        if ($booking === null) {
            return 0;
        }
        if (!in_array((string) $booking['status'], BookingService::ACTIVE_STATUSES, true)) {
            return 0;
        }

        $startsAt = Clock::parse((string) $booking['starts_at']);
        if ($startsAt === null) {
            return 0;
        }

        $steps = $this->stepsFor($booking['reminder_rule_uuid'] ?? null);
        if ($steps === []) {
            return 0;
        }

        Db::run(
            'DELETE FROM ' . self::TABLE . "
              WHERE booking_uuid = :id AND cmp_id = :cmp AND status = 'scheduled'",
            ['id' => $bookingUuid, 'cmp' => $this->ctx->cmpId],
        );

        $now = Clock::now();
        $scheduled = 0;

        foreach ($steps as $step) {
            $offset = (int) $step['offset_minutes'];
            $dueAt = $offset === 0 ? $now : $startsAt->modify('-' . $offset . ' minutes');

            // A reminder whose moment has already gone is not scheduled at all.
            // Sending "your appointment is tomorrow" the morning after is
            // worse than sending nothing, and back-dating it into the table
            // would have dispatchDue() fire it immediately.
            if ($offset > 0 && $dueAt <= $now) {
                continue;
            }

            Db::insert(self::TABLE, [
                'reminder_uuid' => Uuid::v4(),
                'cmp_id'        => $this->ctx->cmpId,
                'booking_uuid'  => $bookingUuid,
                'step_uuid'     => $step['step_uuid'],
                'channel'       => (string) $step['channel'],
                'scheduled_for' => Clock::sql($dueAt),
                'status'        => 'scheduled',
                // Explicit rather than the column default: everything else in
                // this product measures time through Clock, and a row stamped
                // by the database drifts from the schedule it belongs to
                // whenever the two disagree.
                'created_at'    => Clock::sql($now),
                'updated_at'    => Clock::sql($now),
            ], 'reminder_uuid');

            $scheduled++;
        }

        return $scheduled;
    }

    /** Stop the future reminders for a booking that is no longer happening. */
    public function cancelFor(string $bookingUuid, string $reason): int
    {
        return Db::run(
            'UPDATE ' . self::TABLE . "
                SET status = 'skipped', status_detail = :reason, updated_at = :now
              WHERE booking_uuid = :id AND cmp_id = :cmp AND status = 'scheduled'",
            [
                'reason' => substr($reason, 0, 200),
                'now'    => Clock::sql(Clock::now()),
                'id'     => $bookingUuid,
                'cmp'    => $this->ctx->cmpId,
            ],
        )->rowCount();
    }

    /**
     * Send everything due.
 *
     * Called by the scheduled worker (`bin/reminders.php`). Each reminder is
     * handled independently: one provider failure must not stop the rest of the
     * batch, because the rest of the batch is other people's appointments.
     *
     * @return array{sent: int, not_sent: int, skipped: int, failed: int}
     */
    public function dispatchDue(int $limit = 100): array
    {
        $now = Clock::now();

        $due = Db::all(
            'SELECT r.*, b.reference, b.status AS booking_status, b.starts_at, b.timezone,
                    b.client_name, b.client_email, b.client_phone, b.contact_uuid,
                    st.template_key, st.only_if_unconfirmed,
                    s.name AS service_name
               FROM ' . self::TABLE . ' r
               JOIN ' . BookingService::TABLE . ' b ON b.booking_uuid = r.booking_uuid
               JOIN appointment_services s ON s.service_uuid = b.service_uuid
          LEFT JOIN appointment_reminder_steps st ON st.step_uuid = r.step_uuid
              WHERE r.cmp_id = :cmp
                AND r.status = \'scheduled\'
                AND r.scheduled_for <= :now
              ORDER BY r.scheduled_for ASC
              LIMIT ' . max(1, min(500, $limit)),
            ['cmp' => $this->ctx->cmpId, 'now' => Clock::sql($now)],
        );

        $tally = ['sent' => 0, 'not_sent' => 0, 'skipped' => 0, 'failed' => 0];
        $client = $this->messaging ?? new MessagingClient();

        foreach ($due as $reminder) {
            $reminderUuid = (string) $reminder['reminder_uuid'];

            if (!in_array((string) $reminder['booking_status'], BookingService::ACTIVE_STATUSES, true)) {
                $this->settle($reminderUuid, 'skipped', 'The appointment is no longer active.');
                $tally['skipped']++;
                continue;
            }

            if ((bool) ($reminder['only_if_unconfirmed'] ?? false) && (string) $reminder['booking_status'] === 'CONFIRMED') {
                $this->settle($reminderUuid, 'skipped', 'Already confirmed.');
                $tally['skipped']++;
                continue;
            }

            $dueAt = Clock::parse((string) $reminder['scheduled_for']);
            if ($dueAt !== null && Clock::minutesBetween($dueAt, $now) > self::STALE_AFTER_MINUTES) {
                $this->settle($reminderUuid, 'skipped', 'Too late to be useful.');
                $tally['skipped']++;
                continue;
            }

            $channel = (string) $reminder['channel'];

            if ($channel === 'voice') {
                // Receptionist's, by design. Marked rather than attempted.
                $this->settle(
                    $reminderUuid,
                    Features::enabled('RECEPTIONIST') ? 'scheduled' : 'not_sent',
                    Features::enabled('RECEPTIONIST')
                        ? 'Waiting for Receptionist to place the call.'
                        : 'Receptionist integration is not connected, so the reminder call was not placed.',
                );
                $tally['not_sent']++;
                continue;
            }

            $recipient = $this->recipientFor($channel, $reminder);
            if ($recipient === null) {
                $this->settle($reminderUuid, 'not_sent', 'No ' . $channel . ' address for this client.');
                $tally['not_sent']++;
                continue;
            }

            if (!$client->configured()) {
                $this->settle($reminderUuid, 'not_sent', $client->unavailableMessage());
                $tally['not_sent']++;
                continue;
            }

            $result = $client->send([
                'channel'   => $channel,
                'to'        => $recipient,
                'template'  => (string) ($reminder['template_key'] ?? 'appointment_reminder'),
                'reference' => (string) $reminder['reference'],
                'variables' => [
                    'client_name'  => trim((string) $reminder['client_name']) ?: 'there',
                    'service_name' => (string) $reminder['service_name'],
                    'starts_at'    => (string) $reminder['starts_at'],
                    'timezone'     => (string) $reminder['timezone'],
                    'reference'    => (string) $reminder['reference'],
                ],
            ], 'appointment-reminder-' . $reminderUuid);

            if ($result['ok']) {
                $body = $result['body']['data'] ?? $result['body'] ?? [];
                Db::update(self::TABLE, [
                    'status'            => 'sent',
                    'status_detail'     => null,
                    'message_reference' => $this->trimOrNull($body['message_id'] ?? $body['id'] ?? null),
                    'sent_at'           => Clock::sql($now),
                    'attempts'          => (int) $reminder['attempts'] + 1,
                    'updated_at'        => Clock::sql($now),
                ], ['reminder_uuid' => $reminderUuid, 'cmp_id' => $this->ctx->cmpId]);
                $tally['sent']++;
                continue;
            }

            // One retry, then give up: a reminder that keeps failing is a
            // wrong number, and hammering it does not fix that.
            $attempts = (int) $reminder['attempts'] + 1;
            Db::update(self::TABLE, [
                'status'        => $attempts >= 2 ? 'failed' : 'scheduled',
                'status_detail' => substr((string) ($result['error'] ?? 'The provider refused the message.'), 0, 200),
                'attempts'      => $attempts,
                'scheduled_for' => $attempts >= 2
                    ? (string) $reminder['scheduled_for']
                    : Clock::sql($now->modify('+10 minutes')),
                'updated_at'    => Clock::sql($now),
            ], ['reminder_uuid' => $reminderUuid, 'cmp_id' => $this->ctx->cmpId]);

            $tally['failed']++;
        }

        return $tally;
    }

    /**
     * What the client did after a reminder.
     *
     * Attributed to the most recent reminder sent before the action, which is
     * the honest attribution available: nobody clicks a link that says "I am
     * responding to the 4pm SMS". The dashboard labels it as such.
     */
    public function recordOutcome(string $bookingUuid, string $outcome): void
    {
        $outcome = strtolower(trim($outcome));
        if (!in_array($outcome, ['confirmed', 'rescheduled', 'cancelled', 'no_action'], true)) {
            return;
        }

        Db::run(
            'UPDATE ' . self::TABLE . "
                SET outcome = :outcome, outcome_at = :now, updated_at = :now
              WHERE cmp_id = :cmp
                AND booking_uuid = :booking
                AND outcome IS NULL
                AND status IN ('sent', 'delivered')
                AND reminder_uuid = (
                    SELECT reminder_uuid FROM " . self::TABLE . '
                     WHERE cmp_id = :cmp AND booking_uuid = :booking
                       AND status IN (\'sent\', \'delivered\') AND outcome IS NULL
                     ORDER BY sent_at DESC NULLS LAST
                     LIMIT 1
                )',
            [
                'outcome' => $outcome,
                'now'     => Clock::sql(Clock::now()),
                'cmp'     => $this->ctx->cmpId,
                'booking' => $bookingUuid,
            ],
        );
    }

    /**
     * Delivery and outcome figures for the Client Experience dashboard.
     *
     * Computed from THIS product's own reminder rows — what it asked for and
     * what the provider said. Not read out of a messaging database.
     *
     * @return array{channels: list<array<string, mixed>>, outcomes: list<array<string, mixed>>, total: int}
     */
    public function performance(string $fromSql, string $toSql): array
    {
        $channels = Db::all(
            "SELECT channel,
                    COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE status IN ('sent', 'delivered')) AS delivered,
                    COUNT(*) FILTER (WHERE status = 'not_sent') AS not_sent,
                    COUNT(*) FILTER (WHERE status = 'failed') AS failed
               FROM " . self::TABLE . '
              WHERE cmp_id = :cmp AND created_at >= :from AND created_at < :to
              GROUP BY channel
              ORDER BY total DESC',
            ['cmp' => $this->ctx->cmpId, 'from' => $fromSql, 'to' => $toSql],
        );

        $outcomes = Db::all(
            'SELECT COALESCE(outcome, \'no_action\') AS outcome, COUNT(*) AS total
               FROM ' . self::TABLE . "
              WHERE cmp_id = :cmp AND created_at >= :from AND created_at < :to
                AND status IN ('sent', 'delivered')
              GROUP BY 1
              ORDER BY total DESC",
            ['cmp' => $this->ctx->cmpId, 'from' => $fromSql, 'to' => $toSql],
        );

        $total = 0;
        $shaped = [];
        foreach ($channels as $row) {
            $rowTotal = (int) $row['total'];
            $total += $rowTotal;
            $shaped[] = [
                'channel'        => (string) $row['channel'],
                'total'          => $rowTotal,
                'delivered'      => (int) $row['delivered'],
                'not_sent'       => (int) $row['not_sent'],
                'failed'         => (int) $row['failed'],
                'delivered_rate' => $rowTotal > 0 ? round((int) $row['delivered'] / $rowTotal * 100, 1) : 0.0,
            ];
        }

        $shapedOutcomes = [];
        $answered = array_sum(array_map(static fn (array $r) => (int) $r['total'], $outcomes));
        foreach ($outcomes as $row) {
            $shapedOutcomes[] = [
                'outcome' => (string) $row['outcome'],
                'total'   => (int) $row['total'],
                'share'   => $answered > 0 ? round((int) $row['total'] / $answered * 100, 1) : 0.0,
            ];
        }

        return ['channels' => $shaped, 'outcomes' => $shapedOutcomes, 'total' => $total];
    }

    /** @return list<array<string, mixed>> */
    private function stepsFor(?string $ruleUuid): array
    {
        if ($ruleUuid !== null) {
            $steps = Db::all(
                'SELECT * FROM appointment_reminder_steps
                  WHERE reminder_rule_uuid = :rule AND cmp_id = :cmp AND is_active = TRUE
                  ORDER BY offset_minutes DESC',
                ['rule' => $ruleUuid, 'cmp' => $this->ctx->cmpId],
            );
            if ($steps !== []) {
                return $steps;
            }
        }

        return Db::all(
            'SELECT st.* FROM appointment_reminder_steps st
               JOIN appointment_reminder_rules r ON r.reminder_rule_uuid = st.reminder_rule_uuid
              WHERE st.cmp_id = :cmp AND r.is_default = TRUE AND r.is_active = TRUE AND st.is_active = TRUE
              ORDER BY st.offset_minutes DESC',
            ['cmp' => $this->ctx->cmpId],
        );
    }

    /** @param array<string, mixed> $reminder */
    private function recipientFor(string $channel, array $reminder): ?string
    {
        $value = match ($channel) {
            'email'          => (string) $reminder['client_email'],
            'sms', 'whatsapp' => (string) $reminder['client_phone'],
            default          => '',
        };

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function settle(string $reminderUuid, string $status, string $detail): void
    {
        Db::update(self::TABLE, [
            'status'        => $status,
            'status_detail' => substr($detail, 0, 200),
            'updated_at'    => Clock::sql(Clock::now()),
        ], ['reminder_uuid' => $reminderUuid, 'cmp_id' => $this->ctx->cmpId]);
    }

    private function trimOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
