<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Support\Clock;

/**
 * The same request twice is the same appointment once.
 *
 * ## Who needs this
 *
 * A client on a phone with one bar taps Book, sees nothing happen, taps again.
 * Receptionist's call times out mid-booking and retries. Both are normal and
 * both must produce ONE appointment — the second attempt should get the first
 * attempt's answer, not a second booking and not a "that slot is taken" error
 * caused by its own first attempt.
 *
 * ## How
 *
 * The caller sends `Idempotency-Key`. The first request runs, and its status
 * and body are stored against the key. A repeat replays them verbatim. There is
 * no attempt to decide whether the second request is "the same" beyond the key:
 * a key is a promise from the caller, and second-guessing it means guessing.
 *
 * ## No key, no protection
 *
 * A mutation with no key runs normally. That is a deliberate choice: refusing
 * the request would break every caller that has not been updated, and silently
 * generating a key server-side would protect nothing — two taps produce two
 * requests and two keys.
 *
 * The public booking page always sends one, because that is the caller most
 * likely to be on a bad connection and least able to recover.
 */
final class Idempotency
{
    public const TABLE = 'appointment_idempotency_keys';

    /** Keys older than this are housekeeping, not protection. */
    private const RETENTION_DAYS = 7;

    /**
     * Replay a previous answer, or null to go ahead.
     *
     * @return array{status: int, body: array<string, mixed>}|null
     */
    public static function replay(Context $ctx, string $scope, ?string $key): ?array
    {
        $key = self::normalise($key);
        if ($key === null) {
            return null;
        }

        $row = Db::first(
            'SELECT response_status, response_body FROM ' . self::TABLE . '
              WHERE cmp_id = :cmp AND scope = :scope AND idempotency_key = :key',
            ['cmp' => $ctx->cmpId, 'scope' => $scope, 'key' => $key],
        );

        if ($row === null) {
            return null;
        }

        return [
            'status' => (int) $row['response_status'],
            'body'   => Db::jsonColumn($row['response_body'] ?? null),
        ];
    }

    /**
     * Record what was answered, so a repeat gets the same thing.
     *
     * ON CONFLICT DO NOTHING rather than an upsert: if two copies of the same
     * request genuinely raced past replay(), the FIRST answer is the real one
     * and overwriting it with the second would hand the caller two different
     * truths depending on which retry landed last.
     *
     * @param array<string, mixed> $body
     */
    public static function remember(Context $ctx, string $scope, ?string $key, int $status, array $body): void
    {
        $key = self::normalise($key);
        if ($key === null) {
            return;
        }

        try {
            Db::run(
                'INSERT INTO ' . self::TABLE . ' (idempotency_key, cmp_id, scope, response_status, response_body)
                 VALUES (:key, :cmp, :scope, :status, :body)
                 ON CONFLICT (cmp_id, scope, idempotency_key) DO NOTHING',
                [
                    'key'    => $key,
                    'cmp'    => $ctx->cmpId,
                    'scope'  => $scope,
                    'status' => $status,
                    'body'   => json_encode($body, JSON_UNESCAPED_UNICODE),
                ],
            );
        } catch (\Throwable $e) {
            // Never the reason a successful booking looks like a failure.
            error_log('[idempotency] could not record ' . $scope . ': ' . $e->getMessage());
        }
    }

    /** The header, for the routes that read it. */
    public static function fromRequest(): ?string
    {
        return self::normalise(Http::header('Idempotency-Key'));
    }

    public static function purge(Context $ctx): int
    {
        return Db::run(
            'DELETE FROM ' . self::TABLE . ' WHERE cmp_id = :cmp AND created_at < :cutoff',
            ['cmp' => $ctx->cmpId, 'cutoff' => Clock::sql(Clock::now()->modify('-' . self::RETENTION_DAYS . ' days'))],
        )->rowCount();
    }

    private static function normalise(?string $key): ?string
    {
        $key = trim((string) $key);
        if ($key === '' || strlen($key) > 200) {
            return null;
        }

        // Only what a caller can reasonably generate, so the column cannot be
        // used to smuggle anything odd into a primary key.
        return preg_match('/^[A-Za-z0-9._:\-]{8,200}$/', $key) === 1 ? $key : null;
    }
}
