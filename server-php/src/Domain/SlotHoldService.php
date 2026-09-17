<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Clients\CalendarClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

/**
 * The few minutes between choosing a slot and confirming it.
 *
 * ## The problem
 *
 * Two people open the same 3pm. One starts typing their phone number. Without
 * something in between, the other one books it, and the first finds out after
 * submitting a form — or worse, does not find out, and two of them turn up.
 *
 * ## Why the hold lives here and not in Calendar
 *
 * Calendar has no concept of a tentative claim that expires by itself, and it
 * should not: an expiring almost-event is booking-flow state, and putting it in
 * the product that owns diaries would mean Calendar growing a timer, a cleanup
 * job and an opinion about checkout length.
 *
 * So the hold is Appointments' own — it is booking coordination, which is
 * exactly this product's domain. Calendar stays canonical for the EVENT, which
 * is written only once the booking is real. A hold never becomes a calendar
 * event and never appears on anybody's diary.
 *
 * ## Why it is short
 *
 * It is the length of a checkout, not a reservation. Five minutes by default.
 * A hold long enough to be useful as a reservation is long enough to make a
 * popular practitioner look fully booked to everybody else.
 */
final class SlotHoldService
{
    public const TABLE = 'appointment_slot_holds';

    public function __construct(
        private readonly Context $ctx,
        private readonly ?CalendarClient $calendar = null,
    ) {
    }

    /**
     * Claim a slot for a few minutes.
     *
     * Revalidates against Calendar first: a hold placed on a slot that is
     * already taken is a promise this product cannot keep, and it would push
     * the failure to the end of the form instead of the start.
     *
     * @param array{
     *     service_uuid: string,
     *     member_uuid?: ?string,
     *     resource_uuids?: list<string>,
     *     starts_at: string,
     *     ends_at: string,
     *     bo_id?: int,
     *     held_by: string,
     *     held_by_kind?: string
     * } $request
     *
     * @return array{ok: bool, hold: ?array<string, mixed>, reason: ?string, code: ?string}
     */
    public function place(array $request): array
    {
        $start = Clock::parse($request['starts_at']);
        $end = Clock::parse($request['ends_at']);

        if ($start === null || $end === null || $end <= $start) {
            return ['ok' => false, 'hold' => null, 'reason' => 'That is not a valid time range.', 'code' => 'invalid_range'];
        }
        if ($start < Clock::now()) {
            return ['ok' => false, 'hold' => null, 'reason' => 'That slot is in the past.', 'code' => 'in_the_past'];
        }

        $memberUuid = $request['member_uuid'] ?? null;
        $resourceUuids = array_values(array_filter($request['resource_uuids'] ?? []));

        $availability = new AvailabilityService($this->ctx, $this->calendar);
        $check = $availability->revalidate(
            $request['service_uuid'],
            $memberUuid,
            $resourceUuids,
            $start,
            $end,
        );

        if (!$check['free']) {
            return [
                'ok'     => false,
                'hold'   => null,
                'reason' => $check['reason'],
                'code'   => $check['checked'] ? 'slot_taken' : 'calendar_unavailable',
            ];
        }

        $settings = Settings::for($this->ctx);
        $expiresAt = Clock::now()->modify('+' . (int) $settings['hold_duration_seconds'] . ' seconds');
        $holdUuid = Uuid::v4();

        // A hold row per resource as well as per member, because a room is
        // claimed for the same minutes and a second booking against a different
        // practitioner in the same room must see it.
        Db::transaction(function () use ($holdUuid, $request, $memberUuid, $resourceUuids, $start, $end, $expiresAt): void {
            Db::insert(self::TABLE, [
                'hold_uuid'     => $holdUuid,
                'cmp_id'        => $this->ctx->cmpId,
                'bo_id'         => (int) ($request['bo_id'] ?? $this->ctx->boId),
                'service_uuid'  => $request['service_uuid'],
                'member_uuid'   => $memberUuid,
                'resource_uuid' => $resourceUuids[0] ?? null,
                'starts_at'     => Clock::sql($start),
                'ends_at'       => Clock::sql($end),
                'held_by'       => substr($request['held_by'], 0, 128),
                'held_by_kind'  => $request['held_by_kind'] ?? 'user',
                'expires_at'    => Clock::sql($expiresAt),
            ], 'hold_uuid');

            foreach (array_slice($resourceUuids, 1) as $extra) {
                Db::insert(self::TABLE, [
                    'hold_uuid'     => Uuid::v4(),
                    'cmp_id'        => $this->ctx->cmpId,
                    'bo_id'         => (int) ($request['bo_id'] ?? $this->ctx->boId),
                    'service_uuid'  => $request['service_uuid'],
                    'member_uuid'   => null,
                    'resource_uuid' => $extra,
                    'starts_at'     => Clock::sql($start),
                    'ends_at'       => Clock::sql($end),
                    'held_by'       => substr($request['held_by'], 0, 128),
                    'held_by_kind'  => $request['held_by_kind'] ?? 'user',
                    'expires_at'    => Clock::sql($expiresAt),
                ], 'hold_uuid');
            }
        });

        return [
            'ok'   => true,
            'code' => null,
            'reason' => null,
            'hold' => [
                'hold_uuid'          => $holdUuid,
                'expires_at'         => Clock::iso($expiresAt),
                'expires_in_seconds' => (int) $settings['hold_duration_seconds'],
                'starts_at'          => Clock::iso($start),
                'ends_at'            => Clock::iso($end),
            ],
        ];
    }

    /**
     * The live hold with this id, held by this caller.
     *
     * The `held_by` check is what stops one browser consuming another's hold by
     * guessing an id — which is worth saying out loud, because "the hold exists"
     * and "the hold is yours" are different questions and only one of them is
     * safe to answer yes to.
     *
     * @return array<string, mixed>|null
     */
    public function claim(string $holdUuid, string $heldBy): ?array
    {
        if (!Uuid::isValid($holdUuid)) {
            return null;
        }

        return Db::first(
            'SELECT * FROM ' . self::TABLE . '
              WHERE hold_uuid = :id
                AND cmp_id = :cmp
                AND held_by = :by
                AND consumed_by_booking IS NULL
                AND released_at IS NULL
                AND expires_at > :now',
            [
                'id'  => $holdUuid,
                'cmp' => $this->ctx->cmpId,
                'by'  => substr($heldBy, 0, 128),
                'now' => Clock::sql(Clock::now()),
            ],
        );
    }

    /** Mark a hold (and its sibling resource rows) as the booking it became. */
    public function consume(string $holdUuid, string $bookingUuid): void
    {
        $hold = Db::first(
            'SELECT starts_at, ends_at, held_by FROM ' . self::TABLE . ' WHERE hold_uuid = :id AND cmp_id = :cmp',
            ['id' => $holdUuid, 'cmp' => $this->ctx->cmpId],
        );

        if ($hold === null) {
            return;
        }

        Db::run(
            'UPDATE ' . self::TABLE . '
                SET consumed_by_booking = :booking
              WHERE cmp_id = :cmp
                AND held_by = :by
                AND starts_at = :starts
                AND ends_at = :ends
                AND consumed_by_booking IS NULL
                AND released_at IS NULL',
            [
                'booking' => $bookingUuid,
                'cmp'     => $this->ctx->cmpId,
                'by'      => $hold['held_by'],
                'starts'  => $hold['starts_at'],
                'ends'    => $hold['ends_at'],
            ],
        );
    }

    /** Give a slot back early — the client closed the tab, or chose another time. */
    public function release(string $holdUuid, string $heldBy): bool
    {
        $count = Db::run(
            'UPDATE ' . self::TABLE . '
                SET released_at = :now
              WHERE hold_uuid = :id AND cmp_id = :cmp AND held_by = :by
                AND consumed_by_booking IS NULL AND released_at IS NULL',
            [
                'now' => Clock::sql(Clock::now()),
                'id'  => $holdUuid,
                'cmp' => $this->ctx->cmpId,
                'by'  => substr($heldBy, 0, 128),
            ],
        )->rowCount();

        return $count > 0;
    }

    /**
     * Delete holds that expired more than a day ago.
     *
     * Expired holds stop blocking availability the moment they expire — the
     * availability query filters on `expires_at > now()`, so nothing depends on
     * this running. It is housekeeping, kept as an explicit call rather than a
     * cron because a booking product with a table that only ever grows is a
     * booking product that gets slower every month.
     */
    public function purgeExpired(): int
    {
        return Db::run(
            'DELETE FROM ' . self::TABLE . '
              WHERE cmp_id = :cmp
                AND consumed_by_booking IS NULL
                AND expires_at < :cutoff',
            ['cmp' => $this->ctx->cmpId, 'cutoff' => Clock::sql(Clock::now()->modify('-1 day'))],
        )->rowCount();
    }

    /** How many slots are being held right now — the Live Operations "being booked" count. */
    public function liveCount(): int
    {
        return (int) Db::scalar(
            'SELECT COUNT(*) FROM ' . self::TABLE . '
              WHERE cmp_id = :cmp AND consumed_by_booking IS NULL AND released_at IS NULL AND expires_at > :now',
            ['cmp' => $this->ctx->cmpId, 'now' => Clock::sql(Clock::now())],
        );
    }
}
