<?php

declare(strict_types=1);

/**
 * Retry the Calendar write for bookings whose event never got created.
 *
 *   php server-php/bin/calendar-retry.php
 *   php server-php/bin/calendar-retry.php --cmp=7
 *
 * ## Why this exists
 *
 * A booking is written to this database BEFORE its Calendar event, on purpose:
 * a booking without an event is recoverable and an event without a booking is
 * an orphan on somebody's diary. See Domain/BookingService.php.
 *
 * Those bookings are flagged `calendar_sync_state = 'failed'`, they block their
 * own slot, and they appear on Live Operations and in Needs Attention. A person
 * can retry one from the UI. This is the unattended version for the case where
 * Calendar was down for twenty minutes and nobody was watching.
 *
 * ## Run it every fifteen minutes
 *
 *   *\/15 * * * * /usr/bin/php /home/<user>/public_html/api/bin/calendar-retry.php >> /home/<user>/logs/appointments-calendar.log 2>&1
 *
 * Only future appointments are retried. Writing a calendar entry for something
 * that already happened puts a meeting in somebody's past and helps nobody.
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Domain\BookingService;

$args = array_slice($argv, 1);

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

$rows = Db::all(
    'SELECT booking_uuid, cmp_id, bo_id, reference FROM ' . BookingService::TABLE . "
      WHERE calendar_sync_state IN ('pending', 'failed')
        AND status IN ('DRAFT', 'PENDING', 'CONFIRMED', 'ARRIVED')
        AND starts_at > NOW()
        " . ($onlyCompany !== null ? 'AND cmp_id = :cmp' : '') . '
      ORDER BY starts_at
      LIMIT 200',
    $onlyCompany !== null ? ['cmp' => $onlyCompany] : [],
);

if ($rows === []) {
    exit(0);
}

$written = 0;
$stillFailing = 0;

foreach ($rows as $row) {
    $ctx = Context::forCompany((int) $row['cmp_id'], (int) $row['bo_id']);
    // A service identity, because no human is behind this run. It is recorded
    // that way in the audit trail rather than borrowing somebody's name.
    $auth = Auth::forTesting('service:calendar-retry', 'service', 'appointments');

    try {
        if ((new BookingService($ctx, $auth))->writeCalendarEvent((string) $row['booking_uuid'])) {
            $written++;
            echo "synced {$row['reference']}\n";
        } else {
            $stillFailing++;
        }
    } catch (\Throwable $e) {
        $stillFailing++;
        fwrite(STDERR, "{$row['reference']}: {$e->getMessage()}\n");
    }
}

printf("calendar retry: %d synced, %d still failing\n", $written, $stillFailing);
