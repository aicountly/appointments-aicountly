<?php

declare(strict_types=1);

namespace Aicountly\Api;

use PDOException;

/**
 * What /api/health can honestly say about this deployment.
 *
 * "The process is up" and "the app works" are different claims and only one of
 * them is useful. A deploy can go green, and a monitor stay quiet, on an app
 * whose every real endpoint answers 503 because its database was never created.
 *
 * WHAT IT MUST NOT SAY. This endpoint is UNAUTHENTICATED and public. The
 * driver's own message names the database, the role and the host — PostgreSQL's
 * "no pg_hba.conf entry for host X, user Y, database Z" hands all three to
 * anyone who asks. So the reason is reduced to a category here and the detail
 * goes to the error log, where the person fixing it can read it and a passer-by
 * cannot.
 *
 * It reports integration configuration too, as booleans: whether Calendar has a
 * base and a key, whether Pay is enabled. Never the keys themselves.
 */
final class Health
{
    private const MIGRATIONS_TABLE = 'appointment_sql_migrations';

    /** @return array<string, mixed> */
    public static function database(): array
    {
        try {
            $pdo = Db::connect();
        } catch (PDOException $e) {
            return [
                'reachable' => false,
                'reason'    => self::categorise($e->getMessage()),
                'schema'    => null,
            ];
        } catch (\Throwable $e) {
            error_log('[health] database check failed: ' . $e->getMessage());

            return ['reachable' => false, 'reason' => 'error', 'schema' => null];
        }

        $onDisk = count(glob(__DIR__ . '/../database/migrations/*.sql') ?: []);

        try {
            $applied = (int) $pdo->query('SELECT COUNT(*) FROM ' . self::MIGRATIONS_TABLE)->fetchColumn();
        } catch (\Throwable) {
            // No bookkeeping table means migrate.php has never run here. That is
            // a normal state on a host somebody has only just created.
            $applied = 0;
        }

        return [
            'reachable' => true,
            'reason'    => null,
            'schema'    => [
                'applied' => $applied,
                'pending' => max(0, $onDisk - $applied),
                'ready'   => $onDisk > 0 && $applied >= $onDisk,
            ],
        ];
    }

    /**
     * Whether each integration is configured — never how.
     *
     * Calendar is called out separately from the rest because it is the only
     * one Appointments cannot work without: no Calendar means no availability
     * and no bookings, where no Pay simply means deposits are off.
     *
     * @return array<string, mixed>
     */
    public static function integrations(): array
    {
        return [
            'calendar' => [
                'required'   => true,
                'configured' => Env::get('CALENDAR_SERVICE_KEY') !== '',
            ],
            'contacts'     => ['required' => false, 'configured' => Features::enabled('CONTACTS')],
            'crm'          => ['required' => false, 'configured' => Features::enabled('CRM')],
            'pay'          => ['required' => false, 'configured' => Features::enabled('PAY')],
            'receptionist' => ['required' => false, 'configured' => Features::enabled('RECEPTIONIST')],
            'connect'      => ['required' => false, 'configured' => Features::enabled('CONNECT')],
            'billing'      => ['required' => false, 'configured' => Features::enabled('BILLING')],
            'messaging'    => ['required' => false, 'configured' => Features::enabled('MESSAGING')],
            'ai'           => ['required' => false, 'configured' => Features::enabled('AI')],
        ];
    }

    /**
     * A category a stranger may see, from a message they may not.
     *
     * The full driver text is logged, because the person who has to fix this
     * needs the database and role names that the category deliberately omits.
     */
    private static function categorise(string $message): string
    {
        error_log('[health] database unreachable: ' . $message);

        $m = strtolower($message);

        return match (true) {
            str_contains($m, 'not configured')       => 'not_configured',
            str_contains($m, 'pg_hba'),
            str_contains($m, 'password authentication'),
            str_contains($m, 'role ') && str_contains($m, 'does not exist') => 'refused',
            str_contains($m, 'does not exist')       => 'no_such_database',
            str_contains($m, 'connection refused'),
            str_contains($m, 'could not connect'),
            str_contains($m, 'timeout')              => 'unreachable',
            str_contains($m, 'could not find driver') => 'driver_missing',
            default                                   => 'error',
        };
    }
}
