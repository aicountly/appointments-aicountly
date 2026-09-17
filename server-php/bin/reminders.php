<?php

declare(strict_types=1);

/**
 * Send due reminders, sweep the waitlist, and tidy up.
 *
 *   php server-php/bin/reminders.php                  every company with settings
 *   php server-php/bin/reminders.php --cmp=7          one company
 *   php server-php/bin/reminders.php --dry-run        report what would happen
 *
 * ## Why a cron and not a queue
 *
 * APPOINTMENTS OWNS ITS OWN TIMERS — there is no central automation engine in
 * this fleet to hand a reminder to. What a reminder needs is "run this every
 * few minutes and send what is due", which a cron does correctly and a queue
 * does with more moving parts. The schedule itself lives in the database
 * (appointment_reminders), so nothing is lost if this does not run for an hour:
 * the next pass sends what is still worth sending and skips what is not.
 *
 * ## Run it every five minutes
 *
 *   *\/5 * * * * /usr/bin/php /home/<user>/public_html/api/bin/reminders.php >> /home/<user>/logs/appointments-reminders.log 2>&1
 *
 * Reminders more than 90 minutes past due are skipped rather than sent — see
 * ReminderService::STALE_AFTER_MINUTES. "Your appointment is tomorrow" arriving
 * the morning after is worse than nothing.
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Domain\ReminderService;
use Aicountly\Api\Domain\SlotHoldService;
use Aicountly\Api\Domain\WaitlistService;

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);

$onlyCompany = null;
foreach ($args as $arg) {
    if (preg_match('/^--cmp=(\d+)$/', $arg, $m) === 1) {
        $onlyCompany = (int) $m[1];
    }
}

try {
    Db::connect();
} catch (\Throwable $e) {
    fwrite(STDERR, "Cannot connect to the database: {$e->getMessage()}\n");
    exit(1);
}

$companies = $onlyCompany !== null
    ? [['cmp_id' => $onlyCompany]]
    : Db::all('SELECT cmp_id FROM ' . Domain\Settings::TABLE . ' ORDER BY cmp_id');

if ($companies === []) {
    echo "No companies are configured yet.\n";
    exit(0);
}

$startedAt = microtime(true);
$totals = ['sent' => 0, 'not_sent' => 0, 'skipped' => 0, 'failed' => 0, 'released' => 0, 'expired' => 0, 'purged' => 0];

foreach ($companies as $company) {
    $cmpId = (int) $company['cmp_id'];
    $ctx = Context::forCompany($cmpId);

    try {
        if ($dryRun) {
            $due = (int) Db::scalar(
                'SELECT COUNT(*) FROM ' . ReminderService::TABLE . "
                  WHERE cmp_id = :cmp AND status = 'scheduled' AND scheduled_for <= NOW()",
                ['cmp' => $cmpId],
            );
            echo "cmp {$cmpId}: {$due} reminder(s) due\n";
            continue;
        }

        $reminders = (new ReminderService($ctx))->dispatchDue(200);
        $waitlist = (new WaitlistService($ctx))->sweep();
        $purged = (new SlotHoldService($ctx))->purgeExpired();
        Idempotency::purge($ctx);

        foreach (['sent', 'not_sent', 'skipped', 'failed'] as $key) {
            $totals[$key] += $reminders[$key];
        }
        $totals['released'] += $waitlist['released'];
        $totals['expired'] += $waitlist['expired'];
        $totals['purged'] += $purged;

        // One line per company, and only when something happened: a cron that
        // logs "nothing to do" every five minutes buries the run that mattered.
        if (array_sum($reminders) > 0 || $waitlist['released'] > 0 || $waitlist['expired'] > 0) {
            printf(
                "cmp %d: sent=%d not_sent=%d skipped=%d failed=%d waitlist_released=%d waitlist_expired=%d\n",
                $cmpId,
                $reminders['sent'],
                $reminders['not_sent'],
                $reminders['skipped'],
                $reminders['failed'],
                $waitlist['released'],
                $waitlist['expired'],
            );
        }
    } catch (\Throwable $e) {
        // One company's failure must not stop the rest: the rest are other
        // businesses' reminders.
        fwrite(STDERR, "cmp {$cmpId}: {$e->getMessage()}\n");
    }

    Domain\Settings::forget($cmpId);
}

if (!$dryRun && array_sum($totals) > 0) {
    printf(
        "done in %dms — sent=%d not_sent=%d skipped=%d failed=%d\n",
        (int) ((microtime(true) - $startedAt) * 1000),
        $totals['sent'],
        $totals['not_sent'],
        $totals['skipped'],
        $totals['failed'],
    );
}
