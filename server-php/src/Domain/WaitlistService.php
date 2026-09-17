<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Clients\CalendarClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;
use DateTimeImmutable;

/**
 * The waitlist, and the matching that makes it worth keeping.
 *
 * A waitlist nobody acts on is a list of disappointed people. What makes this
 * one useful is that the moment a slot is released — a cancellation, a
 * reschedule, a gap somebody opened up — the product can say WHO it suits and
 * WHY, so a receptionist has a call to make rather than a list to read.
 *
 * ## The matching is explainable
 *
 * Every match carries the reasons it matched: the service, the practitioner
 * they asked for, the daypart, how long they have been waiting. No score
 * without reasons, for the same reason as the no-show indicators — a number
 * nobody can question is a number nobody should act on.
 *
 * ## Offers expire
 *
 * Offering a slot to three people at once and giving it to whoever answers
 * first is how a waitlist becomes a lottery. An offer holds the slot for one
 * client for a bounded time; when it lapses the slot goes back and the next
 * match is offered.
 *
 * ## Reaching people is somebody else's job
 *
 * This service decides who to offer a slot to. Telling them goes through
 * ReminderService and the messaging provider, or through Receptionist for a
 * call. Neither is reimplemented here.
 */
final class WaitlistService
{
    public const TABLE = 'appointment_waitlist';

    /** How long an offered slot is held for one client. */
    private const OFFER_WINDOW_MINUTES = 120;

    /** More matches than this and somebody is reading, not calling. */
    private const MAX_MATCHES = 10;

    /**
     * Reason => weight. Ordering a call list, not predicting anything.
     *
     * @var array<string, array{weight: int, label: string}>
     */
    private const REASONS = [
        'service_match'    => ['weight' => 40, 'label' => 'Wants this service'],
        'preferred_member' => ['weight' => 25, 'label' => 'Asked for this practitioner'],
        'any_member'       => ['weight' => 10, 'label' => 'Happy with anyone'],
        'daypart_match'    => ['weight' => 15, 'label' => 'Suits the time of day they asked for'],
        'weekday_match'    => ['weight' => 10, 'label' => 'Suits the days they asked for'],
        'location_match'   => ['weight' => 10, 'label' => 'At the location they asked for'],
        'waiting_longest'  => ['weight' => 10, 'label' => 'Has been waiting longest'],
        'high_priority'    => ['weight' => 15, 'label' => 'Marked as a priority'],
    ];

    public function __construct(
        private readonly Context $ctx,
        private readonly ?CalendarClient $calendar = null,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function add(array $input): array
    {
        $waitlistUuid = Uuid::v4();

        Db::insert(self::TABLE, [
            'waitlist_uuid' => $waitlistUuid,
            'cmp_id'        => $this->ctx->cmpId,
            'bo_id'         => (int) ($input['bo_id'] ?? $this->ctx->boId),
            'contact_uuid'  => $this->trimOrNull($input['contact_uuid'] ?? null),
            'client_name'   => substr(trim((string) ($input['client_name'] ?? '')), 0, 200),
            'client_phone'  => substr(trim((string) ($input['client_phone'] ?? '')), 0, 40),
            'client_email'  => substr(trim((string) ($input['client_email'] ?? '')), 0, 200),
            'service_uuid'  => $input['service_uuid'],
            'preferred_member_uuid' => $this->trimOrNull($input['preferred_member_uuid'] ?? null),
            'earliest_date' => (string) $input['earliest_date'],
            'latest_date'   => (string) $input['latest_date'],
            'daypart'       => $this->normaliseDaypart($input['daypart'] ?? 'any'),
            'weekdays'      => array_values(array_filter(
                array_map('intval', (array) ($input['weekdays'] ?? [])),
                static fn (int $d) => $d >= 0 && $d <= 6,
            )),
            'priority'      => max(0, min(100, (int) ($input['priority'] ?? 0))),
            'notes'         => substr(trim((string) ($input['notes'] ?? '')), 0, 2000),
            'expires_at'    => isset($input['expires_at'])
                ? Clock::sql(Clock::parse((string) $input['expires_at']) ?? Clock::now()->modify('+90 days'))
                : Clock::sql(Clock::now()->modify('+90 days')),
            'created_by_uuid' => $this->trimOrNull($input['created_by_uuid'] ?? null),
        ], 'waitlist_uuid');

        return $this->row($waitlistUuid) ?? [];
    }

    /**
     * Who would take this slot, and why.
     *
     * The freed slot is described rather than looked up: the caller has just
     * released it and knows exactly what it is, and re-deriving it would be a
     * second Calendar round trip for no new information.
     *
     * @return list<array{
     *     waitlist_uuid: string, client_label: string, contact_uuid: ?string,
     *     score: int, reasons: list<array{key: string, label: string}>,
     *     waiting_days: int, priority: int, preferred_member_uuid: ?string
     * }>
     */
    public function matchesForSlot(
        string $serviceUuid,
        ?string $memberUuid,
        int $boId,
        DateTimeImmutable $start,
        int $limit = self::MAX_MATCHES,
    ): array {
        if (!Settings::featureEnabled($this->ctx, 'WAITLIST')) {
            return [];
        }

        $settings = Settings::for($this->ctx);
        $zone = Clock::zone($settings['timezone']);
        $localDate = $start->setTimezone($zone)->format('Y-m-d');
        $daypart = Clock::daypart($start, $zone);
        $dow = Clock::localDayOfWeek($start, $zone);

        $candidates = Db::all(
            "SELECT * FROM " . self::TABLE . "
              WHERE cmp_id = :cmp
                AND service_uuid = :service
                AND status = 'waiting'
                AND earliest_date <= :date
                AND latest_date >= :date
                AND (expires_at IS NULL OR expires_at > :now)
                AND (bo_id = 0 OR :bo = 0 OR bo_id = :bo)
              ORDER BY priority DESC, created_at ASC
              LIMIT 200",
            [
                'cmp'     => $this->ctx->cmpId,
                'service' => $serviceUuid,
                'date'    => $localDate,
                'now'     => Clock::sql(Clock::now()),
                'bo'      => $boId,
            ],
        );

        $now = Clock::now();
        $matches = [];

        foreach ($candidates as $row) {
            $reasons = ['service_match'];

            $preferred = $this->trimOrNull($row['preferred_member_uuid'] ?? null);
            if ($preferred !== null) {
                if ($memberUuid !== null && $preferred !== $memberUuid) {
                    // They asked for somebody specific and this is not them.
                    // Not a weak match — not a match.
                    continue;
                }
                $reasons[] = 'preferred_member';
            } else {
                $reasons[] = 'any_member';
            }

            $wantedDaypart = (string) $row['daypart'];
            if ($wantedDaypart !== 'any') {
                if ($wantedDaypart !== $daypart) {
                    continue;
                }
                $reasons[] = 'daypart_match';
            }

            $weekdays = Db::jsonColumn($row['weekdays'] ?? null);
            if ($weekdays !== []) {
                if (!in_array($dow, array_map('intval', $weekdays), true)) {
                    continue;
                }
                $reasons[] = 'weekday_match';
            }

            if ((int) $row['bo_id'] > 0 && (int) $row['bo_id'] === $boId) {
                $reasons[] = 'location_match';
            }

            $createdAt = Clock::parse((string) $row['created_at']);
            $waitingDays = $createdAt === null ? 0 : intdiv(max(0, Clock::minutesBetween($createdAt, $now)), 1440);
            if ($waitingDays >= 7) {
                $reasons[] = 'waiting_longest';
            }
            if ((int) $row['priority'] > 0) {
                $reasons[] = 'high_priority';
            }

            $score = 0;
            $shaped = [];
            foreach ($reasons as $key) {
                $meta = self::REASONS[$key] ?? null;
                if ($meta === null) {
                    continue;
                }
                $score += $meta['weight'];
                $shaped[] = ['key' => $key, 'label' => $meta['label']];
            }

            $matches[] = [
                'waitlist_uuid'         => (string) $row['waitlist_uuid'],
                'client_label'          => trim((string) $row['client_name']) ?: 'Client',
                'contact_uuid'          => $this->trimOrNull($row['contact_uuid'] ?? null),
                // Capped so the UI never shows 105%. The weights add to more
                // than 100 on a perfect match, which is fine for ordering and
                // silly to display.
                'score'                 => min(100, $score),
                'reasons'               => $shaped,
                'waiting_days'          => $waitingDays,
                'priority'              => (int) $row['priority'],
                'preferred_member_uuid' => $preferred,
            ];
        }

        usort($matches, static function (array $a, array $b): int {
            return $b['score'] <=> $a['score']
                ?: $b['priority'] <=> $a['priority']
                ?: $b['waiting_days'] <=> $a['waiting_days'];
        });

        return array_slice($matches, 0, max(1, min(self::MAX_MATCHES, $limit)));
    }

    /**
     * Offer a slot to one waitlisted client.
     *
     * One client, not several. The slot is recorded against the entry with an
     * expiry, and no other match is offered the same slot until it lapses.
     *
     * @return array{ok: bool, entry: ?array<string, mixed>, reason: ?string}
     */
    public function offer(string $waitlistUuid, DateTimeImmutable $slotStart): array
    {
        $entry = $this->row($waitlistUuid);
        if ($entry === null) {
            return ['ok' => false, 'entry' => null, 'reason' => 'That waitlist entry does not exist.'];
        }
        if ((string) $entry['status'] !== 'waiting') {
            return ['ok' => false, 'entry' => null, 'reason' => 'That entry is already ' . $entry['status'] . '.'];
        }

        $now = Clock::now();

        Db::update(self::TABLE, [
            'status'             => 'offered',
            'offered_at'         => Clock::sql($now),
            'offered_slot_start' => Clock::sql($slotStart),
            'offer_expires_at'   => Clock::sql($now->modify('+' . self::OFFER_WINDOW_MINUTES . ' minutes')),
            'updated_at'         => Clock::sql($now),
        ], ['waitlist_uuid' => $waitlistUuid, 'cmp_id' => $this->ctx->cmpId]);

        return ['ok' => true, 'entry' => $this->row($waitlistUuid), 'reason' => null];
    }

    /** The offer was taken. */
    public function markBooked(string $waitlistUuid, string $bookingUuid): void
    {
        Db::update(self::TABLE, [
            'status'              => 'booked',
            'booked_booking_uuid' => $bookingUuid,
            'updated_at'          => Clock::sql(Clock::now()),
        ], ['waitlist_uuid' => $waitlistUuid, 'cmp_id' => $this->ctx->cmpId]);
    }

    public function withdraw(string $waitlistUuid): void
    {
        Db::update(self::TABLE, [
            'status'     => 'withdrawn',
            'updated_at' => Clock::sql(Clock::now()),
        ], ['waitlist_uuid' => $waitlistUuid, 'cmp_id' => $this->ctx->cmpId]);
    }

    /**
     * Put lapsed offers and expired entries back where they belong.
     *
     * An offer nobody answered returns to `waiting` — the client did not stop
     * wanting an appointment, they just missed one phone call.
     *
     * @return array{released: int, expired: int}
     */
    public function sweep(): array
    {
        $now = Clock::sql(Clock::now());

        $released = Db::run(
            'UPDATE ' . self::TABLE . "
                SET status = 'waiting', offered_at = NULL, offered_slot_start = NULL,
                    offer_expires_at = NULL, updated_at = :now
              WHERE cmp_id = :cmp AND status = 'offered' AND offer_expires_at < :now",
            ['now' => $now, 'cmp' => $this->ctx->cmpId],
        )->rowCount();

        $expired = Db::run(
            'UPDATE ' . self::TABLE . "
                SET status = 'expired', updated_at = :now
              WHERE cmp_id = :cmp AND status IN ('waiting', 'offered')
                AND expires_at IS NOT NULL AND expires_at < :now",
            ['now' => $now, 'cmp' => $this->ctx->cmpId],
        )->rowCount();

        return ['released' => $released, 'expired' => $expired];
    }

    /** @return array<string, mixed>|null */
    public function row(string $waitlistUuid): ?array
    {
        if (!Uuid::isValid($waitlistUuid)) {
            return null;
        }

        $row = Db::first(
            'SELECT * FROM ' . self::TABLE . ' WHERE waitlist_uuid = :id AND cmp_id = :cmp',
            ['id' => $waitlistUuid, 'cmp' => $this->ctx->cmpId],
        );

        if ($row !== null) {
            $row['weekdays'] = Db::jsonColumn($row['weekdays'] ?? null);
        }

        return $row;
    }

    /** How many people are waiting, per service — the dashboard count. */
    public function demandByService(): array
    {
        return Db::all(
            "SELECT w.service_uuid, s.name AS service_name, COUNT(*) AS waiting
               FROM " . self::TABLE . " w
               JOIN appointment_services s ON s.service_uuid = w.service_uuid
              WHERE w.cmp_id = :cmp AND w.status = 'waiting'
              GROUP BY w.service_uuid, s.name
              ORDER BY waiting DESC",
            ['cmp' => $this->ctx->cmpId],
        );
    }

    private function normaliseDaypart(mixed $daypart): string
    {
        $daypart = strtolower(trim((string) $daypart));

        return in_array($daypart, ['morning', 'afternoon', 'evening'], true) ? $daypart : 'any';
    }

    private function trimOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
