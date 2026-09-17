<?php

declare(strict_types=1);

/**
 * Integration tests for the Appointments domain.
 *
 * They run against a REAL PostgreSQL database and a stub that stands in for
 * Calendar, Contacts and Manage, so what is being tested is the actual SQL, the
 * actual HTTP client and the actual availability arithmetic — not mocks of them.
 *
 *   server-php/tests/run.sh
 *
 * The cases that matter most are the ones where being wrong costs a customer
 * their afternoon: a slot offered that Calendar says is taken, a booking
 * confirmed when Calendar could not be read, two people given the same 3pm.
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Ai\InsightEngine;
use Aicountly\Api\Dashboards\CapacityDashboard;
use Aicountly\Api\Dashboards\ClientExperienceDashboard;
use Aicountly\Api\Dashboards\IntelligenceDashboard;
use Aicountly\Api\Dashboards\LiveOperationsDashboard;
use Aicountly\Api\Dashboards\OverviewDashboard;
use Aicountly\Api\Dashboards\Period;
use Aicountly\Api\Domain\AvailabilityService;
use Aicountly\Api\Domain\BookingService;
use Aicountly\Api\Domain\ClientProfileService;
use Aicountly\Api\Domain\NoShowRiskService;
use Aicountly\Api\Domain\ReminderService;
use Aicountly\Api\Domain\ScheduleHealthService;
use Aicountly\Api\Domain\Settings;
use Aicountly\Api\Domain\SlotHoldService;
use Aicountly\Api\Domain\WaitlistService;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;
$failures = [];

function check(string $name, callable $fn): void
{
    global $passed, $failed, $failures;

    try {
        $fn();
        echo "  ok    {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  FAIL  {$name}\n        {$e->getMessage()}\n";
        if (getenv('VERBOSE')) {
            echo '        ' . $e->getFile() . ':' . $e->getLine() . "\n";
        }
        $failed++;
        $failures[] = $name;
    } finally {
        // Test-only overrides are cleared HERE, not at the end of each test.
        // A test that sets a feature flag and then fails an assertion would
        // otherwise leave it set, and every later test would be running
        // against a deployment it did not ask for — one failure cascading into
        // six is how an afternoon disappears.
        Features::overrideForTesting(null);
        Ai\ConsoleCredentials::overrideForTesting(null);
    }
}

function assertSame(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(sprintf(
            '%s: expected %s, got %s',
            $what,
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

function assertTrue(bool $condition, string $what): void
{
    if (!$condition) {
        throw new \RuntimeException($what);
    }
}

function assertGreaterThan(int|float $floor, int|float $actual, string $what): void
{
    if ($actual <= $floor) {
        throw new \RuntimeException("{$what}: expected more than {$floor}, got {$actual}");
    }
}

function section(string $name): void
{
    echo "\n{$name}\n";
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

const CMP = 9001;

/** A fixed Monday, so weekday arithmetic is deterministic. */
const FROZEN_NOW = '2026-06-01T04:00:00Z';   // 09:30 Asia/Kolkata, a Monday

function ctx(int $boId = 0): Context
{
    return Context::forCompany(CMP, $boId);
}

function auth(): Auth
{
    return Auth::forTesting('user-test', 'user', 'appointments', ['acs_type' => 1]);
}

/**
 * Tear the company down and build it back up.
 *
 * Every test starts from the same known state. Ordering the deletes by
 * dependency rather than relying on cascades keeps this working when a
 * migration adds a table.
 */
function reset(): void
{
    foreach ([
        'appointment_ai_insights',
        'appointment_reminders',
        'appointment_booking_metadata',
        'appointment_booking_attendees',
        'appointment_waitlist',
        'appointment_slot_holds',
        'appointment_bookings',
        'appointment_service_resources',
        'appointment_service_locations',
        'appointment_service_staff',
        'appointment_team_availability',
        'appointment_resources',
        'appointment_services',
        'appointment_team_members',
        'appointment_locations_config',
        'appointment_booking_pages',
        'appointment_reminder_steps',
        'appointment_reminder_rules',
        'appointment_booking_rules',
        'appointment_client_preferences',
        'appointment_permission_assignments',
        'appointment_permission_profiles',
        'appointment_audit_log',
        'appointment_idempotency_keys',
        'appointment_feature_settings',
    ] as $table) {
        Db::run('DELETE FROM ' . $table . ' WHERE cmp_id = :cmp', ['cmp' => CMP]);
    }

    Settings::forget();
    Permissions::forget();

    Clock::freezeForTesting(FROZEN_NOW);

    // The stub remembers the events it has been given — deliberately, so
    // free/busy behaves like the real Calendar. That means it also has to be
    // cleared, or one test's bookings occupy the next test's diary.
    resetStubCalendar();

    // Creating the settings row fires the trigger that seeds the default
    // booking rule and reminder rule.
    Settings::for(ctx());

    Context::trustForTesting(CMP, auth());
}

function resetStubCalendar(): void
{
    $base = rtrim(Env::get('CALENDAR_API_BASE'), '/');
    if ($base === '') {
        return;
    }

    $ch = curl_init($base . '/api/stub/reset');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_PROXY          => '',
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * A practitioner with a working week.
 *
 * `$calendarSubscriber` decides what the Calendar stub says about them:
 * `free-*` is empty, `busy-*` has 10:00–11:00 UTC taken, `unreadable-*` cannot
 * be read, `down-*` makes Calendar itself fail.
 */
function makeMember(string $calendarSubscriber = 'free-1', int $boId = 0): string
{
    $memberUuid = Uuid::v4();

    Db::insert('appointment_team_members', [
        'member_uuid' => $memberUuid,
        'cmp_id'      => CMP,
        'user_uuid'   => 'user-' . substr($memberUuid, 0, 8),
        'calendar_subscriber_uuid' => $calendarSubscriber,
        'display_label' => 'Dr Test',
        'timezone'    => 'Asia/Kolkata',
    ], 'member_uuid');

    // Monday to Friday, 09:00–17:00 local.
    for ($dow = 1; $dow <= 5; $dow++) {
        Db::insert('appointment_team_availability', [
            'availability_uuid' => Uuid::v4(),
            'cmp_id'        => CMP,
            'member_uuid'   => $memberUuid,
            'bo_id'         => $boId,
            'day_of_week'   => $dow,
            'starts_minute' => 540,
            'ends_minute'   => 1020,
            'kind'          => 'available',
        ], 'availability_uuid');
    }

    return $memberUuid;
}

/** @param array<string, mixed> $overrides */
function makeService(string $memberUuid, array $overrides = []): string
{
    $serviceUuid = Uuid::v4();

    Db::insert('appointment_services', [
        'service_uuid' => $serviceUuid,
        'cmp_id'       => CMP,
        'bo_id'        => 0,
        'name'         => $overrides['name'] ?? ('Consultation ' . substr($serviceUuid, 0, 4)),
        'duration_minutes'   => $overrides['duration_minutes'] ?? 60,
        'buffer_before_mins' => $overrides['buffer_before_mins'] ?? 0,
        'buffer_after_mins'  => $overrides['buffer_after_mins'] ?? 0,
        'min_notice_minutes' => $overrides['min_notice_minutes'] ?? 0,
        'booking_horizon_days' => $overrides['booking_horizon_days'] ?? 60,
        'modes'        => $overrides['modes'] ?? ['IN_PERSON'],
        'deposit_required' => $overrides['deposit_required'] ?? false,
        'deposit_minor'    => $overrides['deposit_minor'] ?? null,
        'form_uuid'    => $overrides['form_uuid'] ?? null,
        'is_active'    => $overrides['is_active'] ?? true,
    ], 'service_uuid');

    Db::insert('appointment_service_staff', [
        'service_uuid' => $serviceUuid,
        'member_uuid'  => $memberUuid,
        'cmp_id'       => CMP,
        'priority'     => 0,
    ], 'service_uuid');

    return $serviceUuid;
}

/**
 * A historical appointment, inserted directly.
 *
 * The Client Experience and Intelligence dashboards measure appointments by the
 * date they FELL on, not the date they were booked — attendance and no-show
 * rates only mean anything that way. So a fixture for those screens has to be
 * in the past, which the booking service correctly refuses to create.
 *
 * @param array<string, mixed> $overrides
 */
function makePastBooking(string $serviceUuid, string $memberUuid, int $daysAgo, array $overrides = []): string
{
    $bookingUuid = Uuid::v4();
    $startsAt = Clock::now()->modify('-' . $daysAgo . ' days');

    Db::insert(BookingService::TABLE, [
        'booking_uuid'  => $bookingUuid,
        'cmp_id'        => CMP,
        'bo_id'         => 0,
        'reference'     => 'AP-H' . substr($bookingUuid, 0, 6),
        'service_uuid'  => $serviceUuid,
        'member_uuid'   => $memberUuid,
        'contact_uuid'  => $overrides['contact_uuid'] ?? ('contact-' . substr($bookingUuid, 0, 8)),
        'client_name'   => $overrides['client_name'] ?? 'Historical Client',
        'client_email'  => $overrides['client_email'] ?? 'history@example.com',
        'client_phone'  => $overrides['client_phone'] ?? '+919000009999',
        'starts_at'     => Clock::sql($startsAt),
        'ends_at'       => Clock::sql($startsAt->modify('+1 hour')),
        'timezone'      => 'Asia/Kolkata',
        'status'        => $overrides['status'] ?? 'COMPLETED',
        'mode'          => 'IN_PERSON',
        'booking_source' => $overrides['booking_source'] ?? 'ONLINE_BOOKING',
        'calendar_sync_state' => 'synced',
        'calendar_event_uuid' => 'evt-history-' . substr($bookingUuid, 0, 8),
        'calendar_subscriber_uuid' => 'free-1',
        'confirmed_at'  => array_key_exists('confirmed_at', $overrides)
            ? $overrides['confirmed_at']
            : Clock::sql($startsAt->modify('-1 day')),
        'completed_at'  => ($overrides['status'] ?? 'COMPLETED') === 'COMPLETED' ? Clock::sql($startsAt) : null,
        'created_at'    => Clock::sql($startsAt->modify('-7 days')),
    ], 'booking_uuid');

    return $bookingUuid;
}

/** The next Wednesday at a local hour, which is inside every fixture's working week. */
function slotAt(int $localHour, int $daysAhead = 2): \DateTimeImmutable
{
    $zone = Clock::zone('Asia/Kolkata');
    $day = Clock::startOfLocalDay(Clock::now()->modify('+' . $daysAhead . ' days'), $zone);

    return Clock::atLocalMinute($day, $localHour * 60, $zone);
}

// ---------------------------------------------------------------------------
// Preflight
// ---------------------------------------------------------------------------

try {
    Db::connect();
} catch (\Throwable $e) {
    fwrite(STDERR, "Cannot connect to the test database: {$e->getMessage()}\n");
    exit(1);
}

$stubBase = Env::get('CALENDAR_API_BASE');
if ($stubBase === '') {
    fwrite(STDERR, "CALENDAR_API_BASE is not set. Run tests through tests/run.sh.\n");
    exit(1);
}

echo "Appointments integration tests\n";
echo str_repeat('=', 60) . "\n";

// ===========================================================================
section('Clock and timezone arithmetic');
// ===========================================================================

check('local minute-of-day survives a round trip', function (): void {
    Clock::freezeForTesting(FROZEN_NOW);
    $zone = Clock::zone('Asia/Kolkata');
    $day = Clock::startOfLocalDay(Clock::now(), $zone);
    $tenThirty = Clock::atLocalMinute($day, 630, $zone);

    assertSame('10:30', $tenThirty->setTimezone($zone)->format('H:i'), 'local time');
    assertSame(630, Clock::localMinuteOfDay($tenThirty, $zone), 'minute of day');
});

check('a slot built in a half-hour-offset zone lands on the right UTC instant', function (): void {
    $zone = Clock::zone('Asia/Kolkata');
    $day = Clock::startOfLocalDay(Clock::parse('2026-06-03T00:00:00Z'), $zone);
    // 09:00 IST is 03:30 UTC. Adding 540 minutes to UTC midnight would give
    // 09:00 UTC, which is the bug this guards.
    assertSame('03:30', Clock::atLocalMinute($day, 540, $zone)->format('H:i'), 'UTC instant for 09:00 IST');
});

check('a DST transition does not shift a local slot', function (): void {
    $zone = Clock::zone('Europe/London');
    // The clocks go forward on 29 March 2026.
    $before = Clock::startOfLocalDay(Clock::parse('2026-03-28T12:00:00Z'), $zone);
    $after = Clock::startOfLocalDay(Clock::parse('2026-03-30T12:00:00Z'), $zone);

    assertSame('09:00', Clock::atLocalMinute($before, 540, $zone)->setTimezone($zone)->format('H:i'), 'before DST');
    assertSame('09:00', Clock::atLocalMinute($after, 540, $zone)->setTimezone($zone)->format('H:i'), 'after DST');
    // And the UTC instants genuinely differ by an hour.
    assertSame('09:00', Clock::atLocalMinute($before, 540, $zone)->format('H:i'), 'before DST in UTC');
    assertSame('08:00', Clock::atLocalMinute($after, 540, $zone)->format('H:i'), 'after DST in UTC');
});

check('an unknown timezone falls back rather than throwing', function (): void {
    assertSame('Asia/Kolkata', Clock::zone('Not/AZone')->getName(), 'fallback zone');
});

// ===========================================================================
section('Availability: rules');
// ===========================================================================

check('working hours bound the slots offered', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member, ['duration_minutes' => 60]);

    $result = (new AvailabilityService(ctx()))->findSlots([
        'service_uuid' => $service,
        'from'         => slotAt(0),
        'to'           => slotAt(0)->modify('+1 day'),
    ]);

    assertTrue($result['calendar_available'], 'calendar available');
    assertGreaterThan(0, count($result['slots']), 'some slots');

    foreach ($result['slots'] as $slot) {
        $hour = (int) substr($slot['local_time'], 0, 2);
        assertTrue($hour >= 9 && $hour < 17, 'slot ' . $slot['local_time'] . ' inside 09:00–17:00');
    }
});

check('a day with no working hours offers nothing', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);

    // Saturday.
    $saturday = Clock::startOfLocalDay(Clock::now()->modify('+5 days'), Clock::zone('Asia/Kolkata'));

    $result = (new AvailabilityService(ctx()))->findSlots([
        'service_uuid' => $service,
        'from'         => $saturday,
        'to'           => $saturday->modify('+1 day'),
    ]);

    assertSame(0, count($result['slots']), 'no slots on a day with no hours');
});

check('minimum notice removes slots that are too soon', function (): void {
    reset();
    $member = makeMember('free-1');
    // Two days' notice, so nothing tomorrow is offerable.
    $service = makeService($member, ['min_notice_minutes' => 2880]);

    $result = (new AvailabilityService(ctx()))->findSlots([
        'service_uuid' => $service,
        'from'         => Clock::now(),
        'to'           => Clock::now()->modify('+2 days'),
    ]);

    assertSame(0, count($result['slots']), 'nothing inside the notice period');
});

check('the booking horizon caps how far ahead slots go', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member, ['booking_horizon_days' => 3]);

    $result = (new AvailabilityService(ctx()))->findSlots([
        'service_uuid' => $service,
        'from'         => Clock::now(),
        'to'           => Clock::now()->modify('+30 days'),
        'limit'        => 500,
    ]);

    $horizon = Clock::now()->modify('+3 days');
    foreach ($result['slots'] as $slot) {
        assertTrue(
            Clock::parse($slot['starts_at']) <= $horizon,
            'slot ' . $slot['starts_at'] . ' inside the 3-day horizon',
        );
    }
});

check('a blocked window removes the slots inside it', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member, ['duration_minutes' => 30]);

    // Lunch, 13:00–14:00 on every working day.
    for ($dow = 1; $dow <= 5; $dow++) {
        Db::insert('appointment_team_availability', [
            'availability_uuid' => Uuid::v4(),
            'cmp_id'        => CMP,
            'member_uuid'   => $member,
            'bo_id'         => 0,
            'day_of_week'   => $dow,
            'starts_minute' => 780,
            'ends_minute'   => 840,
            'kind'          => 'blocked',
        ], 'availability_uuid');
    }

    $result = (new AvailabilityService(ctx()))->findSlots([
        'service_uuid' => $service,
        'from'         => slotAt(0),
        'to'           => slotAt(0)->modify('+1 day'),
        'limit'        => 100,
    ]);

    foreach ($result['slots'] as $slot) {
        assertTrue($slot['local_time'] !== '13:00' && $slot['local_time'] !== '13:30', 'no slot during lunch');
    }
});

check('buffers make a slot occupy more than it shows', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member, [
        'duration_minutes'   => 60,
        'buffer_before_mins' => 15,
        'buffer_after_mins'  => 15,
    ]);

    // Book 11:00–12:00. With 15-minute buffers it occupies 10:45–12:15, so
    // neither 10:30 (which would run to 11:30) nor 12:00 is offerable.
    $booking = (new BookingService(ctx(), auth()))->create([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(11)),
        'client_name'  => 'Buffer Test',
    ]);
    assertTrue($booking['ok'], 'booking created: ' . (string) $booking['reason']);

    $result = (new AvailabilityService(ctx()))->findSlots([
        'service_uuid' => $service,
        'from'         => slotAt(0),
        'to'           => slotAt(0)->modify('+1 day'),
        'limit'        => 100,
    ]);

    $times = array_column($result['slots'], 'local_time');
    assertTrue(!in_array('11:00', $times, true), '11:00 is taken');
    assertTrue(!in_array('12:00', $times, true), '12:00 is inside the trailing buffer');
    assertTrue(!in_array('10:30', $times, true), '10:30 would overlap the leading buffer');
    assertTrue(in_array('12:30', $times, true), '12:30 is clear of the buffer');
});

check('only eligible staff are offered', function (): void {
    reset();
    $eligible = makeMember('free-1');
    $notEligible = makeMember('free-2');
    $service = makeService($eligible);

    $result = (new AvailabilityService(ctx()))->findSlots([
        'service_uuid' => $service,
        'from'         => slotAt(0),
        'to'           => slotAt(0)->modify('+1 day'),
    ]);

    foreach ($result['slots'] as $slot) {
        assertSame($eligible, $slot['member_uuid'], 'only the eligible practitioner');
    }
    assertTrue($notEligible !== $eligible, 'two distinct members exist');
});

check('a practitioner who is not accepting bookings is not offered', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);

    Db::update('appointment_team_members', ['accepts_bookings' => false], ['member_uuid' => $member, 'cmp_id' => CMP]);

    $result = (new AvailabilityService(ctx()))->findSlots([
        'service_uuid' => $service,
        'from'         => slotAt(0),
        'to'           => slotAt(0)->modify('+1 day'),
    ]);

    assertSame(0, count($result['slots']), 'no slots for a practitioner who is closed to bookings');
});

// ===========================================================================
section('Availability: live Calendar');
// ===========================================================================

check('a slot Calendar reports busy is not offered', function (): void {
    reset();
    // The stub gives busy-* a busy block at 10:00–11:00 UTC = 15:30–16:30 IST.
    $member = makeMember('busy-1');
    $service = makeService($member, ['duration_minutes' => 60]);

    $result = (new AvailabilityService(ctx()))->findSlots([
        'service_uuid' => $service,
        'from'         => slotAt(0),
        'to'           => slotAt(0)->modify('+1 day'),
        'limit'        => 100,
    ]);

    assertTrue($result['calendar_available'], 'calendar available');

    foreach ($result['slots'] as $slot) {
        assertTrue($slot['local_time'] !== '15:30', '15:30 is busy in Calendar and must not be offered');
    }
    assertGreaterThan(0, count($result['slots']), 'other slots are still offered');
});

check('a calendar that cannot be read yields NO slots, not rule-only slots', function (): void {
    reset();
    $member = makeMember('unreadable-1');
    $service = makeService($member);

    $result = (new AvailabilityService(ctx()))->findSlots([
        'service_uuid' => $service,
        'from'         => slotAt(0),
        'to'           => slotAt(0)->modify('+1 day'),
    ]);

    assertSame(false, $result['calendar_available'], 'calendar reported unavailable');
    assertSame(0, count($result['slots']), 'no slots offered when availability cannot be confirmed');
    assertTrue($result['calendar_message'] !== null, 'a reason is given');
});

check('Calendar being down yields no slots and says so', function (): void {
    reset();
    $member = makeMember('down-1');
    $service = makeService($member);

    $result = (new AvailabilityService(ctx()))->findSlots([
        'service_uuid' => $service,
        'from'         => slotAt(0),
        'to'           => slotAt(0)->modify('+1 day'),
    ]);

    assertSame(false, $result['calendar_available'], 'calendar unavailable');
    assertSame(0, count($result['slots']), 'no slots');
});

check('booking is REFUSED when the conflict check cannot be performed', function (): void {
    reset();
    $member = makeMember('unreadable-1');
    $service = makeService($member);

    $result = (new BookingService(ctx(), auth()))->create([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(10)),
        'client_name'  => 'Unknown Calendar',
    ]);

    assertSame(false, $result['ok'], 'booking refused');
    assertSame('calendar_unavailable', $result['code'], 'refused for the right reason');
});

check('booking is refused when Calendar says the time is taken', function (): void {
    reset();
    $member = makeMember('busy-1');
    $service = makeService($member, ['duration_minutes' => 60]);

    // 15:30 IST = 10:00 UTC, which the stub reports busy.
    $result = (new BookingService(ctx(), auth()))->create([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(15)->modify('+30 minutes')),
        'client_name'  => 'Clash Test',
    ]);

    assertSame(false, $result['ok'], 'booking refused');
    assertSame('slot_taken', $result['code'], 'refused as taken');
});

// ===========================================================================
section('Slot holds and double-booking');
// ===========================================================================

check('a hold blocks the slot for everybody else', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);

    $hold = (new SlotHoldService(ctx()))->place([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(10)),
        'ends_at'      => Clock::iso(slotAt(11)),
        'held_by'      => 'browser-a',
    ]);

    assertTrue($hold['ok'], 'hold placed: ' . (string) $hold['reason']);

    $result = (new AvailabilityService(ctx()))->findSlots([
        'service_uuid' => $service,
        'from'         => slotAt(0),
        'to'           => slotAt(0)->modify('+1 day'),
        'limit'        => 100,
    ]);

    assertTrue(!in_array('10:00', array_column($result['slots'], 'local_time'), true), 'held slot is not offered');
});

check('two browsers cannot hold the same slot', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);

    $holds = new SlotHoldService(ctx());

    $first = $holds->place([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(10)),
        'ends_at'      => Clock::iso(slotAt(11)),
        'held_by'      => 'browser-a',
    ]);
    assertTrue($first['ok'], 'first hold succeeds');

    $second = $holds->place([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(10)),
        'ends_at'      => Clock::iso(slotAt(11)),
        'held_by'      => 'browser-b',
    ]);

    assertSame(false, $second['ok'], 'second hold refused');
    assertSame('slot_taken', $second['code'], 'refused as taken');
});

check('a hold can only be claimed by whoever placed it', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);

    $holds = new SlotHoldService(ctx());
    $hold = $holds->place([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(10)),
        'ends_at'      => Clock::iso(slotAt(11)),
        'held_by'      => 'browser-a',
    ]);

    $holdUuid = (string) $hold['hold']['hold_uuid'];

    assertTrue($holds->claim($holdUuid, 'browser-a') !== null, 'the owner can claim it');
    assertSame(null, $holds->claim($holdUuid, 'browser-b'), 'somebody else cannot');
});

check('an expired hold stops blocking the slot', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);

    $hold = (new SlotHoldService(ctx()))->place([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(10)),
        'ends_at'      => Clock::iso(slotAt(11)),
        'held_by'      => 'browser-a',
    ]);

    // Expire it by moving the clock past the hold window rather than by editing
    // the row, so the query's own `expires_at > now` is what is under test.
    Db::update(SlotHoldService::TABLE, [
        'expires_at' => Clock::sql(Clock::now()->modify('-1 minute')),
    ], ['hold_uuid' => (string) $hold['hold']['hold_uuid'], 'cmp_id' => CMP]);

    $result = (new AvailabilityService(ctx()))->findSlots([
        'service_uuid' => $service,
        'from'         => slotAt(0),
        'to'           => slotAt(0)->modify('+1 day'),
        'limit'        => 100,
    ]);

    assertTrue(in_array('10:00', array_column($result['slots'], 'local_time'), true), 'the slot is free again');
});

check('two bookings cannot take the same slot', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);
    $bookings = new BookingService(ctx(), auth());

    $first = $bookings->create([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(10)),
        'client_name'  => 'First',
    ]);
    assertTrue($first['ok'], 'first booking succeeds');

    // The calendar write went to the stub and the booking is marked synced, so
    // the second attempt is caught by the stub's own conflict check only if the
    // event were there — which it is not, because the stub is stateless. What
    // DOES catch it is the internal block: the first booking's own row.
    Db::update(BookingService::TABLE, ['calendar_sync_state' => 'pending'], [
        'booking_uuid' => (string) $first['booking']['booking_uuid'],
        'cmp_id'       => CMP,
    ]);

    $second = $bookings->create([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(10)),
        'client_name'  => 'Second',
    ]);

    assertSame(false, $second['ok'], 'second booking refused');
});

// ===========================================================================
section('Booking lifecycle');
// ===========================================================================

check('a booking is created, referenced and given a calendar event', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);

    $result = (new BookingService(ctx(), auth()))->create([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(10)),
        'client_name'  => 'Priya Kapoor',
        'client_phone' => '+919876543210',
    ]);

    assertTrue($result['ok'], 'created: ' . (string) $result['reason']);
    $booking = $result['booking'];

    assertSame('CONFIRMED', $booking['status'], 'auto-confirmed');
    assertTrue(str_starts_with((string) $booking['reference'], 'AP-'), 'has a reference');
    assertSame('synced', $booking['calendar_sync_state'], 'calendar event written');
    assertTrue(str_starts_with((string) $booking['calendar_event_uuid'], 'evt-'), 'event uuid stored');
});

check('references do not repeat', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member, ['duration_minutes' => 30]);
    $bookings = new BookingService(ctx(), auth());

    $seen = [];
    foreach ([10, 11, 12] as $hour) {
        $result = $bookings->create([
            'service_uuid' => $service,
            'member_uuid'  => $member,
            'starts_at'    => Clock::iso(slotAt($hour)),
            'client_name'  => 'Client ' . $hour,
        ]);
        assertTrue($result['ok'], 'booking at ' . $hour . ': ' . (string) $result['reason']);
        $seen[] = (string) $result['booking']['reference'];
    }

    assertSame(3, count(array_unique($seen)), 'three distinct references');
});

check('a policy requiring confirmation leaves the booking PENDING', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);

    Db::run(
        'UPDATE appointment_booking_rules SET requires_confirmation = TRUE WHERE cmp_id = :cmp',
        ['cmp' => CMP],
    );

    $result = (new BookingService(ctx(), auth()))->create([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(10)),
        'client_name'  => 'Needs Confirming',
    ]);

    assertTrue($result['ok'], 'created');
    assertSame('PENDING', $result['booking']['status'], 'awaiting confirmation');
});

check('the lifecycle walks forward and refuses to go backwards', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);
    $bookings = new BookingService(ctx(), auth());

    $created = $bookings->create([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(10)),
        'client_name'  => 'Walker',
    ]);
    $uuid = (string) $created['booking']['booking_uuid'];

    foreach (['ARRIVED', 'IN_PROGRESS', 'COMPLETED'] as $target) {
        $step = $bookings->transition($uuid, $target);
        assertTrue($step['ok'], 'moved to ' . $target . ': ' . (string) $step['reason']);
        assertSame($target, $step['booking']['status'], 'status is ' . $target);
    }

    $backwards = $bookings->transition($uuid, 'CONFIRMED');
    assertSame(false, $backwards['ok'], 'a completed appointment cannot be reconfirmed');
    assertSame('illegal_transition', $backwards['code'], 'refused as illegal');
});

check('a cancelled appointment cannot be started', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);
    $bookings = new BookingService(ctx(), auth());

    $created = $bookings->create([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(10)),
        'client_name'  => 'Gone',
    ]);
    $uuid = (string) $created['booking']['booking_uuid'];

    $cancelled = $bookings->cancel($uuid, 'Client called off');
    assertTrue($cancelled['ok'], 'cancelled');

    $started = $bookings->transition($uuid, 'IN_PROGRESS');
    assertSame(false, $started['ok'], 'cannot start a cancelled appointment');
});

check('cancelling frees the slot', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);
    $bookings = new BookingService(ctx(), auth());

    $created = $bookings->create([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(10)),
        'client_name'  => 'Temporary',
    ]);

    $bookings->cancel((string) $created['booking']['booking_uuid'], 'Changed their mind');

    $again = $bookings->create([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(10)),
        'client_name'  => 'Replacement',
    ]);

    assertTrue($again['ok'], 'the slot can be rebooked: ' . (string) $again['reason']);
});

check('cancelling inside the notice period is flagged as late', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);
    $bookings = new BookingService(ctx(), auth());

    // The default policy wants 24 hours; this is in four.
    $soon = Clock::now()->modify('+4 hours');
    $created = $bookings->create([
        'service_uuid'   => $service,
        'member_uuid'    => $member,
        'starts_at'      => Clock::iso($soon),
        'client_name'    => 'Late Canceller',
        'contact_uuid'   => 'contact-late',
        'override_rules' => true,
    ]);
    assertTrue($created['ok'], 'created: ' . (string) $created['reason']);

    $cancelled = $bookings->cancel((string) $created['booking']['booking_uuid'], 'Something came up');

    assertTrue($cancelled['ok'], 'cancelled');
    assertSame(true, $cancelled['late'], 'flagged as a late cancellation');

    $profile = ClientProfileService::for(ctx(), 'contact-late');
    assertSame(1, (int) $profile['late_cancel_count'], 'counted against the client profile');
});

check('rescheduling chains the old booking to the new one', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);
    $bookings = new BookingService(ctx(), auth());

    $created = $bookings->create([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(10)),
        'client_name'  => 'Mover',
    ]);
    $originalUuid = (string) $created['booking']['booking_uuid'];

    $moved = $bookings->reschedule($originalUuid, [
        'starts_at' => Clock::iso(slotAt(14)),
        'reason'    => 'Client asked to move it',
    ]);

    assertTrue($moved['ok'], 'rescheduled: ' . (string) $moved['reason']);

    $newUuid = (string) $moved['booking']['booking_uuid'];
    assertTrue($newUuid !== $originalUuid, 'a new booking was created');
    assertSame($originalUuid, (string) $moved['booking']['rescheduled_from_uuid'], 'chained to the original');
    assertSame(1, (int) $moved['booking']['reschedule_count'], 'reschedule counted');

    $original = $bookings->row($originalUuid);
    assertSame('RESCHEDULED', (string) $original['status'], 'the original is marked rescheduled');
});

check('the reschedule limit is enforced and can be overridden', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member, ['duration_minutes' => 30]);
    $bookings = new BookingService(ctx(), auth());

    Db::run(
        'UPDATE appointment_booking_rules SET max_reschedules = 1, reschedule_notice_hours = 0 WHERE cmp_id = :cmp',
        ['cmp' => CMP],
    );

    $created = $bookings->create([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(10)),
        'client_name'  => 'Serial Mover',
    ]);

    $first = $bookings->reschedule((string) $created['booking']['booking_uuid'], [
        'starts_at' => Clock::iso(slotAt(11)),
    ]);
    assertTrue($first['ok'], 'first move allowed');

    $second = $bookings->reschedule((string) $first['booking']['booking_uuid'], [
        'starts_at' => Clock::iso(slotAt(12)),
    ]);
    assertSame(false, $second['ok'], 'second move refused');
    assertSame('reschedule_limit', $second['code'], 'refused for the limit');

    $overridden = $bookings->reschedule((string) $first['booking']['booking_uuid'], [
        'starts_at'      => Clock::iso(slotAt(12)),
        'override_rules' => true,
    ]);
    assertTrue($overridden['ok'], 'an override gets through: ' . (string) $overridden['reason']);
});

check('booking source is proven by the credential, not the body', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member, ['duration_minutes' => 30]);

    // A browser session claiming to be Receptionist.
    $spoofed = (new BookingService(ctx(), auth()))->create([
        'service_uuid'   => $service,
        'member_uuid'    => $member,
        'starts_at'      => Clock::iso(slotAt(10)),
        'client_name'    => 'Spoof',
        'booking_source' => 'RECEPTIONIST',
    ]);
    assertTrue($spoofed['ok'], 'created');
    assertSame('STAFF_BOOKING', (string) $spoofed['booking']['booking_source'], 'the claim is ignored');

    // A real Receptionist service key.
    $receptionist = Auth::forTesting('user-caller', 'service', 'receptionist');
    Context::trustForTesting(CMP, $receptionist);

    $genuine = (new BookingService(ctx(), $receptionist))->create([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(11)),
        'client_name'  => 'Phone Caller',
    ]);
    assertTrue($genuine['ok'], 'created: ' . (string) $genuine['reason']);
    assertSame('RECEPTIONIST', (string) $genuine['booking']['booking_source'], 'attributed to Receptionist');
});

check('an appointment mode the service does not offer is refused', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member, ['modes' => ['IN_PERSON']]);

    $result = (new BookingService(ctx(), auth()))->create([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(10)),
        'client_name'  => 'Video Please',
        'mode'         => 'EXTERNAL_VIDEO',
    ]);

    assertSame(false, $result['ok'], 'refused');
    assertSame('mode_not_offered', $result['code'], 'refused for the mode');
});

check('an Aicountly Connect booking falls back to phone when Connect is off', function (): void {
    reset();
    Features::overrideForTesting([]);   // everything off
    $member = makeMember('free-1');
    $service = makeService($member, ['modes' => ['IN_PERSON', 'AICOUNTLY_CONNECT']]);

    $result = (new BookingService(ctx(), auth()))->create([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(10)),
        'client_name'  => 'Video Client',
        'mode'         => 'AICOUNTLY_CONNECT',
    ]);

    assertTrue($result['ok'], 'the booking still happens: ' . (string) $result['reason']);
    assertSame('PHONE', (string) $result['booking']['mode'], 'degraded to phone rather than refused');
});

check('a booking whose calendar write fails is flagged and still blocks its slot', function (): void {
    reset();
    $member = makeMember('down-1');
    $service = makeService($member);

    // Calendar is down, so the availability check refuses first. Override the
    // rules to get past it, which is what a receptionist booking over the phone
    // during an outage would do.
    $result = (new BookingService(ctx(), auth()))->create([
        'service_uuid'   => $service,
        'member_uuid'    => $member,
        'starts_at'      => Clock::iso(slotAt(10)),
        'client_name'    => 'Outage Booking',
        'override_rules' => true,
    ]);

    // The revalidation is NOT skipped by override_rules — override covers the
    // booking rules, not the conflict check. So this must still be refused.
    assertSame(false, $result['ok'], 'refused even with an override');
    assertSame('calendar_unavailable', $result['code'], 'refused because the calendar could not be read');
});

// ===========================================================================
section('Idempotency');
// ===========================================================================

check('the same key replays the first answer', function (): void {
    reset();
    $context = ctx();

    Idempotency::remember($context, 'booking.create', 'key-abc12345', 201, ['data' => ['booking' => ['reference' => 'AP-1001']]]);

    $replay = Idempotency::replay($context, 'booking.create', 'key-abc12345');

    assertTrue($replay !== null, 'a replay is available');
    assertSame(201, $replay['status'], 'same status');
    assertSame('AP-1001', $replay['body']['data']['booking']['reference'], 'same body');
});

check('a different scope does not collide', function (): void {
    reset();
    $context = ctx();

    Idempotency::remember($context, 'booking.create', 'shared-key-1234', 201, ['data' => ['a' => 1]]);

    assertSame(null, Idempotency::replay($context, 'booking.cancel', 'shared-key-1234'), 'scopes are independent');
});

check('a malformed key is ignored rather than stored', function (): void {
    reset();
    $context = ctx();

    Idempotency::remember($context, 'booking.create', 'short', 201, ['data' => []]);

    assertSame(null, Idempotency::replay($context, 'booking.create', 'short'), 'too short to be a key');
});

// ===========================================================================
section('Tenant isolation');
// ===========================================================================

check('one company cannot read another company\'s bookings', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);

    $created = (new BookingService(ctx(), auth()))->create([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(10)),
        'client_name'  => 'Ours',
    ]);
    $uuid = (string) $created['booking']['booking_uuid'];

    $otherCompany = Context::forCompany(CMP + 1);
    $seen = (new BookingService($otherCompany, auth()))->row($uuid);

    assertSame(null, $seen, 'invisible to another company');
});

check('a service from another company cannot be booked', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);

    $otherCompany = Context::forCompany(CMP + 1);
    Settings::forget(CMP + 1);

    $result = (new BookingService($otherCompany, auth()))->create([
        'service_uuid' => $service,
        'starts_at'    => Clock::iso(slotAt(10)),
        'client_name'  => 'Cross Tenant',
    ]);

    assertSame(false, $result['ok'], 'refused');
    assertSame('unknown_service', $result['code'], 'the service is simply not theirs');

    Db::run('DELETE FROM appointment_feature_settings WHERE cmp_id = :cmp', ['cmp' => CMP + 1]);
});

// ===========================================================================
section('Permissions');
// ===========================================================================

check('a company owner holds everything', function (): void {
    reset();
    $owner = Auth::forTesting('user-owner', 'user', 'appointments', ['acs_type' => 1]);

    assertSame(
        count(Permissions::all()),
        count(Permissions::granted(ctx(), $owner)),
        'the owner holds every permission',
    );
});

check('a member with no profile gets the safe defaults', function (): void {
    reset();
    Permissions::forget();
    $member = Auth::forTesting('user-nobody', 'user', 'appointments', ['acs_type' => 2]);

    $granted = Permissions::granted(ctx(), $member);

    assertSame(Permissions::DEFAULT_MEMBER_GRANTS, $granted, 'the day-one defaults');
    assertSame(false, Permissions::allows(ctx(), $member, 'appointments.settings.manage'), 'but not settings');
    assertSame(false, Permissions::allows(ctx(), $member, 'appointments.booking.create'), 'and not booking');
});

check('an assigned profile decides what somebody holds', function (): void {
    reset();
    Permissions::forget();

    $profileId = (int) Db::insert(Permissions::TABLE_PROFILES, [
        'cmp_id'      => CMP,
        'name'        => 'Receptionist',
        'permissions' => ['appointments.booking.view', 'appointments.booking.create'],
        'is_active'   => true,
    ], 'profile_id');

    Db::insert(Permissions::TABLE_ASSIGNMENTS, [
        'cmp_id'     => CMP,
        'user_uuid'  => 'user-reception',
        'profile_id' => $profileId,
    ], 'assignment_id');

    $receptionist = Auth::forTesting('user-reception', 'user', 'appointments', ['acs_type' => 2]);

    assertSame(true, Permissions::allows(ctx(), $receptionist, 'appointments.booking.create'), 'can book');
    assertSame(false, Permissions::allows(ctx(), $receptionist, 'appointments.settings.manage'), 'cannot change settings');
});

// ===========================================================================
section('Feature flags');
// ===========================================================================

check('a flag is off when its requirement is missing', function (): void {
    Features::overrideForTesting(null);

    // PAY needs PAY_SERVICE_KEY, which the test env does not set.
    assertSame(false, Features::enabled('PAY'), 'Pay is off');
    assertTrue(Features::explain('PAY') !== null, 'and says why');
});

check('a disabled integration reports itself as unconfigured', function (): void {
    Features::overrideForTesting(null);

    $pay = new Clients\PayClient();
    assertSame(false, $pay->configured(), 'Pay client knows it is off');
    assertSame('Aicountly Pay integration is not enabled yet.', $pay->unavailableMessage(), 'and has the words for it');

    $result = $pay->createPaymentRequest(['amount_minor' => 50000, 'currency' => 'INR', 'purpose' => 'deposit', 'reference' => 'x'], 'key-1');
    assertSame(false, $result['ok'], 'no call is attempted');
    assertSame('pay_not_enabled', $result['error'], 'and it says which flag');
});

check('messaging refuses voice, which belongs to Receptionist', function (): void {
    Features::overrideForTesting(['MESSAGING' => true]);

    $result = (new Clients\MessagingClient())->send([
        'channel'   => 'voice',
        'to'        => '+910000000000',
        'template'  => 'x',
        'variables' => [],
        'reference' => 'y',
    ], 'key-1');

    assertSame(false, $result['ok'], 'refused');
    assertSame('voice_belongs_to_receptionist', $result['error'], 'for the right reason');

    Features::overrideForTesting(null);
});

// ===========================================================================
section('Reminders');
// ===========================================================================

check('a booking gets the default reminder schedule', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);

    $created = (new BookingService(ctx(), auth()))->create([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(10, 5)),
        'client_name'  => 'Reminder Test',
        'client_email' => 'test@example.com',
        'client_phone' => '+919876543210',
    ]);
    assertTrue($created['ok'], 'created: ' . (string) $created['reason']);

    $reminders = Db::all(
        'SELECT r.channel, s.offset_minutes FROM ' . ReminderService::TABLE . ' r
           JOIN appointment_reminder_steps s ON s.step_uuid = r.step_uuid
          WHERE r.booking_uuid = :id ORDER BY s.offset_minutes DESC',
        ['id' => (string) $created['booking']['booking_uuid']],
    );

    // The seeded default is confirmation + 24h + 4h-if-unconfirmed.
    assertSame(3, count($reminders), 'three reminders scheduled');
});

check('a reminder whose moment has passed is not scheduled', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);

    // Two hours away, so the 24-hour and 4-hour reminders are already in the
    // past and only the immediate confirmation should exist.
    $created = (new BookingService(ctx(), auth()))->create([
        'service_uuid'   => $service,
        'member_uuid'    => $member,
        'starts_at'      => Clock::iso(Clock::now()->modify('+2 hours')),
        'client_name'    => 'Imminent',
        'client_email'   => 'x@example.com',
        'override_rules' => true,
    ]);
    assertTrue($created['ok'], 'created: ' . (string) $created['reason']);

    $count = (int) Db::scalar(
        'SELECT COUNT(*) FROM ' . ReminderService::TABLE . ' WHERE booking_uuid = :id',
        ['id' => (string) $created['booking']['booking_uuid']],
    );

    assertSame(1, $count, 'only the confirmation');
});

check('cancelling an appointment stops its reminders', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);
    $bookings = new BookingService(ctx(), auth());

    $created = $bookings->create([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(10, 5)),
        'client_name'  => 'Cancelled Reminders',
        'client_email' => 'x@example.com',
    ]);

    $bookings->cancel((string) $created['booking']['booking_uuid'], 'Not needed');

    $stillScheduled = (int) Db::scalar(
        'SELECT COUNT(*) FROM ' . ReminderService::TABLE . " WHERE booking_uuid = :id AND status = 'scheduled'",
        ['id' => (string) $created['booking']['booking_uuid']],
    );

    assertSame(0, $stillScheduled, 'nothing is still scheduled');
});

check('with no messaging provider, reminders are marked not_sent and never delivered', function (): void {
    reset();
    Features::overrideForTesting([]);   // messaging off

    $member = makeMember('free-1');
    $service = makeService($member);

    $created = (new BookingService(ctx(), auth()))->create([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(10, 5)),
        'client_name'  => 'No Provider',
        'client_email' => 'x@example.com',
        'client_phone' => '+919876543210',
    ]);
    assertTrue($created['ok'], 'created');

    $tally = (new ReminderService(ctx()))->dispatchDue();

    assertSame(0, $tally['sent'], 'nothing was sent');
    assertGreaterThan(0, $tally['not_sent'], 'and it is recorded as not sent');

    $delivered = (int) Db::scalar(
        'SELECT COUNT(*) FROM ' . ReminderService::TABLE . " WHERE cmp_id = :cmp AND status IN ('sent', 'delivered')",
        ['cmp' => CMP],
    );
    assertSame(0, $delivered, 'nothing claims to be delivered');

    $withReason = Db::first(
        'SELECT status_detail FROM ' . ReminderService::TABLE . " WHERE cmp_id = :cmp AND status = 'not_sent' LIMIT 1",
        ['cmp' => CMP],
    );
    assertTrue(!empty($withReason['status_detail']), 'and a reason is stored');

    Features::overrideForTesting(null);
});

check('reminder performance counts what happened, not what was hoped', function (): void {
    reset();
    Features::overrideForTesting([]);
    $member = makeMember('free-1');
    $service = makeService($member);

    (new BookingService(ctx(), auth()))->create([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(10, 5)),
        'client_name'  => 'Perf',
        'client_email' => 'x@example.com',
    ]);

    (new ReminderService(ctx()))->dispatchDue();

    $performance = (new ReminderService(ctx()))->performance(
        Clock::sql(Clock::now()->modify('-1 day')),
        Clock::sql(Clock::now()->modify('+1 day')),
    );

    assertGreaterThan(0, $performance['total'], 'reminders are counted');
    foreach ($performance['channels'] as $channel) {
        assertSame(0.0, $channel['delivered_rate'], 'no channel claims delivery');
    }

    Features::overrideForTesting(null);
});

// ===========================================================================
section('Waitlist');
// ===========================================================================

check('a matching client is found, with reasons', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);

    (new WaitlistService(ctx()))->add([
        'service_uuid'  => $service,
        'client_name'   => 'Rohit Mehta',
        'client_phone'  => '+919000000001',
        'earliest_date' => Clock::now()->format('Y-m-d'),
        'latest_date'   => Clock::now()->modify('+30 days')->format('Y-m-d'),
        'daypart'       => 'morning',
    ]);

    $matches = (new WaitlistService(ctx()))->matchesForSlot($service, $member, 0, slotAt(10));

    assertSame(1, count($matches), 'one match');
    assertGreaterThan(0, $matches[0]['score'], 'has a score');
    assertGreaterThan(0, count($matches[0]['reasons']), 'and reasons for it');
    assertTrue(
        in_array('service_match', array_column($matches[0]['reasons'], 'key'), true),
        'the service is one of the reasons',
    );
});

check('a daypart mismatch is not a weak match, it is no match', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);

    (new WaitlistService(ctx()))->add([
        'service_uuid'  => $service,
        'client_name'   => 'Evenings Only',
        'client_phone'  => '+919000000002',
        'earliest_date' => Clock::now()->format('Y-m-d'),
        'latest_date'   => Clock::now()->modify('+30 days')->format('Y-m-d'),
        'daypart'       => 'evening',
    ]);

    // 10:00 is morning.
    assertSame(0, count((new WaitlistService(ctx()))->matchesForSlot($service, $member, 0, slotAt(10))), 'no match');
});

check('asking for a specific practitioner excludes other practitioners\' slots', function (): void {
    reset();
    $wanted = makeMember('free-1');
    $other = makeMember('free-2');
    $service = makeService($wanted);
    Db::insert('appointment_service_staff', [
        'service_uuid' => $service, 'member_uuid' => $other, 'cmp_id' => CMP, 'priority' => 0,
    ], 'service_uuid');

    (new WaitlistService(ctx()))->add([
        'service_uuid'  => $service,
        'preferred_member_uuid' => $wanted,
        'client_name'   => 'Particular',
        'client_phone'  => '+919000000003',
        'earliest_date' => Clock::now()->format('Y-m-d'),
        'latest_date'   => Clock::now()->modify('+30 days')->format('Y-m-d'),
    ]);

    assertSame(1, count((new WaitlistService(ctx()))->matchesForSlot($service, $wanted, 0, slotAt(10))), 'matches their own');
    assertSame(0, count((new WaitlistService(ctx()))->matchesForSlot($service, $other, 0, slotAt(10))), 'not somebody else\'s');
});

check('a lapsed offer returns the client to waiting', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);
    $waitlist = new WaitlistService(ctx());

    $entry = $waitlist->add([
        'service_uuid'  => $service,
        'client_name'   => 'Offered',
        'client_phone'  => '+919000000004',
        'earliest_date' => Clock::now()->format('Y-m-d'),
        'latest_date'   => Clock::now()->modify('+30 days')->format('Y-m-d'),
    ]);

    $waitlist->offer((string) $entry['waitlist_uuid'], slotAt(10));
    assertSame('offered', (string) $waitlist->row((string) $entry['waitlist_uuid'])['status'], 'offered');

    Db::update(WaitlistService::TABLE, [
        'offer_expires_at' => Clock::sql(Clock::now()->modify('-1 minute')),
    ], ['waitlist_uuid' => (string) $entry['waitlist_uuid'], 'cmp_id' => CMP]);

    $swept = $waitlist->sweep();

    assertSame(1, $swept['released'], 'one offer released');
    assertSame('waiting', (string) $waitlist->row((string) $entry['waitlist_uuid'])['status'], 'back to waiting');
});

check('the waitlist is empty when the feature is off', function (): void {
    reset();
    Features::overrideForTesting([]);   // WAITLIST off

    $member = makeMember('free-1');
    $service = makeService($member);

    Db::insert(WaitlistService::TABLE, [
        'waitlist_uuid' => Uuid::v4(),
        'cmp_id'        => CMP,
        'service_uuid'  => $service,
        'client_name'   => 'Disabled',
        'earliest_date' => Clock::now()->format('Y-m-d'),
        'latest_date'   => Clock::now()->modify('+30 days')->format('Y-m-d'),
    ], 'waitlist_uuid');

    assertSame(0, count((new WaitlistService(ctx()))->matchesForSlot($service, $member, 0, slotAt(10))), 'no matching while off');

    Features::overrideForTesting(null);
});

// ===========================================================================
section('No-show risk');
// ===========================================================================

check('an unconfirmed first-time booking fires the expected indicators', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);

    Db::run('UPDATE appointment_booking_rules SET requires_confirmation = TRUE WHERE cmp_id = :cmp', ['cmp' => CMP]);

    (new BookingService(ctx(), auth()))->create([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(10)),
        'client_name'  => 'New Client',
        'client_phone' => '+919000000005',
        'contact_uuid' => 'contact-new',
    ]);

    $assessed = (new NoShowRiskService(ctx()))->assessWindow(
        Clock::sql(Clock::now()),
        Clock::sql(Clock::now()->modify('+7 days')),
    );

    assertSame(1, count($assessed), 'one booking assessed');
    $keys = array_column($assessed[0]['indicators'], 'key');

    assertTrue(in_array('not_confirmed', $keys, true), 'not confirmed');
    assertTrue(in_array('never_booked_before', $keys, true), 'first time');
    assertGreaterThan(0, $assessed[0]['score'], 'has a score');
    // Every point is attributable to a named indicator.
    $expected = array_sum(array_column($assessed[0]['indicators'], 'weight'));
    assertSame($expected, $assessed[0]['score'], 'the score is the sum of its named parts');
});

check('a client with no way to be contacted is flagged', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);

    (new BookingService(ctx(), auth()))->create([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(10)),
        'client_name'  => 'Unreachable',
    ]);

    $assessed = (new NoShowRiskService(ctx()))->assessWindow(
        Clock::sql(Clock::now()),
        Clock::sql(Clock::now()->modify('+7 days')),
    );

    assertTrue(
        in_array('no_contact_channel', array_column($assessed[0]['indicators'], 'key'), true),
        'no contact channel',
    );
});

check('factor frequency stays quiet on a handful of rows', function (): void {
    reset();

    $factors = (new NoShowRiskService(ctx()))->factorFrequency(
        Clock::sql(Clock::now()->modify('-30 days')),
        Clock::sql(Clock::now()),
    );

    assertSame(0, $factors['no_shows'], 'no no-shows');
    assertSame([], $factors['factors'], 'and no factors claimed');
});

// ===========================================================================
section('Schedule health');
// ===========================================================================

check('an empty day scores 100, not zero', function (): void {
    reset();

    $health = (new ScheduleHealthService(ctx()))->today();

    assertSame(100, $health['score'], 'a day with nothing in it is not an unhealthy day');
    assertSame('clear', $health['band'], 'and is reported as clear');
    assertSame(0, $health['appointments'], 'no appointments');
});

check('the score is the sum of named penalties', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member, ['duration_minutes' => 30]);

    Db::run('UPDATE appointment_booking_rules SET requires_confirmation = TRUE WHERE cmp_id = :cmp', ['cmp' => CMP]);

    // Two unconfirmed appointments later today.
    $bookings = new BookingService(ctx(), auth());
    foreach ([14, 15] as $hour) {
        $result = $bookings->create([
            'service_uuid'   => $service,
            'member_uuid'    => $member,
            'starts_at'      => Clock::iso(slotAt($hour, 0)),
            'client_name'    => 'Unconfirmed ' . $hour,
            'override_rules' => true,
        ]);
        assertTrue($result['ok'], 'created: ' . (string) $result['reason']);
    }

    $health = (new ScheduleHealthService(ctx()))->today();

    assertSame(2, $health['appointments'], 'two appointments today');
    assertGreaterThan(0, count($health['components']), 'components are reported');

    $penalties = array_sum(array_column($health['components'], 'penalty'));
    assertSame(100 - $penalties, $health['score'], 'the score is 100 minus the named penalties');

    foreach ($health['components'] as $component) {
        assertTrue($component['label'] !== '', 'every component has a label');
        assertGreaterThan(0, $component['count'], 'and a count');
    }
});

// ===========================================================================
section('Dashboards');
// ===========================================================================

check('all five dashboards build and differ', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member, ['duration_minutes' => 30]);
    $bookings = new BookingService(ctx(), auth());

    foreach ([10, 11, 14] as $hour) {
        $bookings->create([
            'service_uuid' => $service,
            'member_uuid'  => $member,
            'starts_at'    => Clock::iso(slotAt($hour)),
            'client_name'  => 'Dash ' . $hour,
            'contact_uuid' => 'contact-' . $hour,
            'client_email' => 'd' . $hour . '@example.com',
        ]);
    }

    $views = [
        'overview'          => OverviewDashboard::class,
        'live'              => LiveOperationsDashboard::class,
        'capacity'          => CapacityDashboard::class,
        'client_experience' => ClientExperienceDashboard::class,
        'intelligence'      => IntelligenceDashboard::class,
    ];

    $panelSets = [];

    foreach ($views as $id => $class) {
        $period = Period::fromRequest(ctx(), $id === 'overview' || $id === 'live' ? 'today' : '30d');
        $built = (new $class(ctx(), auth(), $period))->build();

        assertSame($id, $built['view'], $id . ' identifies itself');
        assertGreaterThan(0, count($built['metrics']), $id . ' has metrics');
        assertGreaterThan(0, count($built['panels']), $id . ' has panels');
        assertTrue(isset($built['freshness']['sources']), $id . ' names its sources');

        foreach ($built['metrics'] as $metric) {
            assertTrue(array_key_exists('change_pct', $metric), $id . ' metric has a change field');
            assertTrue(array_key_exists('direction', $metric), $id . ' metric says which way is good');
        }

        $panelSets[$id] = array_keys($built['panels']);
    }

    // The five are genuinely different screens, not one screen rearranged.
    assertSame(5, count(array_unique(array_map('serialize', $panelSets))), 'each dashboard has its own panels');
});

check('a metric with no previous period reports no change rather than 0%', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);

    (new BookingService(ctx(), auth()))->create([
        'service_uuid' => $service,
        'member_uuid'  => $member,
        'starts_at'    => Clock::iso(slotAt(10)),
        'client_name'  => 'Only One',
        'contact_uuid' => 'contact-solo',
    ]);

    $built = (new ClientExperienceDashboard(ctx(), auth(), Period::fromRequest(ctx(), '30d')))->build();

    foreach ($built['metrics'] as $metric) {
        if ($metric['previous'] === null || (float) $metric['previous'] === 0.0) {
            assertSame(null, $metric['change_pct'], $metric['id'] . ' has nothing to compare against');
        }
    }
});

check('the capacity dashboard marks a closed heatmap cell as closed, not quiet', function (): void {
    reset();
    makeMember('free-1');

    $built = (new CapacityDashboard(ctx(), auth(), Period::fromRequest(ctx(), 'last_4_weeks')))->build();
    $heatmap = $built['panels']['heatmap'];

    $foundClosed = false;
    foreach ($heatmap['bands'] as $band) {
        foreach ($band['cells'] as $cell) {
            // Sunday and Saturday have no working hours in the fixture.
            if (in_array($cell['day_of_week'], [0, 6], true)) {
                assertSame(true, $cell['closed'], 'a weekend cell is closed');
                assertSame(null, $cell['intensity'], 'and has no intensity');
                $foundClosed = true;
            }
        }
    }

    assertTrue($foundClosed, 'the heatmap distinguishes closed from quiet');
});

check('the intelligence dashboard reports Pay as not enabled rather than zero', function (): void {
    reset();
    Features::overrideForTesting([]);

    $built = (new IntelligenceDashboard(ctx(), auth(), Period::fromRequest(ctx(), '30d')))->build();
    $payments = $built['panels']['payments'];

    assertSame(false, $payments['configured'], 'Pay is not configured');
    assertSame('Aicountly Pay integration is not enabled yet.', $payments['unavailable_reason'], 'and says so');
    assertSame(null, $payments['collected_minor'], 'and reports nothing collected, not zero collected');

    Features::overrideForTesting(null);
});

check('the client experience funnel marks unmeasured stages rather than inventing them', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);

    // In the past, because this dashboard measures appointments by the date
    // they fell on — see the note on ClientExperienceDashboard.
    makePastBooking($service, $member, 5, ['contact_uuid' => 'contact-funnel']);

    $built = (new ClientExperienceDashboard(ctx(), auth(), Period::fromRequest(ctx(), '30d')))->build();
    $stages = $built['panels']['funnel']['stages'];

    $byKey = [];
    foreach ($stages as $stage) {
        $byKey[$stage['key']] = $stage;
    }

    assertSame(false, $byKey['booking_started']['measured'], 'the top of the funnel is not measured here');
    assertSame(null, $byKey['booking_started']['count'], 'and is not back-filled');
    assertTrue($byKey['booking_started']['unmeasured_reason'] !== null, 'and says why');
    assertSame(true, $byKey['booking_completed']['measured'], 'what we own is measured');
    assertSame(1, $byKey['booking_completed']['count'], 'and counted');
});

// ===========================================================================
section('Insights');
// ===========================================================================

check('attendance and no-show rates exclude cancellations from the denominator', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);

    // Three that happened, one missed, and two called off. Attendance is 3 of
    // 4 — the cancelled ones never had an attendance to measure, and counting
    // them would make a well-run practice look negligent.
    for ($i = 1; $i <= 3; $i++) {
        makePastBooking($service, $member, $i, ['status' => 'COMPLETED', 'contact_uuid' => 'c-done-' . $i]);
    }
    makePastBooking($service, $member, 4, ['status' => 'NO_SHOW', 'contact_uuid' => 'c-missed']);
    makePastBooking($service, $member, 5, ['status' => 'CANCELLED', 'contact_uuid' => 'c-off-1']);
    makePastBooking($service, $member, 6, ['status' => 'CANCELLED', 'contact_uuid' => 'c-off-2']);

    $built = (new ClientExperienceDashboard(ctx(), auth(), Period::fromRequest(ctx(), '30d')))->build();

    $metrics = [];
    foreach ($built['metrics'] as $metric) {
        $metrics[$metric['id']] = $metric['value'];
    }

    assertSame(75.0, $metrics['attendance_rate'], 'attendance is 3 of 4, not 3 of 6');
    assertSame(25.0, $metrics['no_show_rate'], 'no-show is 1 of 4');
});

check('a client who came back counts towards the rebook rate', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);

    // A long-standing client whose first visit predates the window, plus two
    // visits inside it. "Returning" means they had booked with this company
    // BEFORE the period — somebody whose first ever visit happens to fall
    // inside it is a new client even if they came twice.
    makePastBooking($service, $member, 45, ['status' => 'COMPLETED', 'contact_uuid' => 'c-loyal']);
    makePastBooking($service, $member, 20, ['status' => 'COMPLETED', 'contact_uuid' => 'c-loyal']);
    makePastBooking($service, $member, 5, ['status' => 'COMPLETED', 'contact_uuid' => 'c-loyal']);
    makePastBooking($service, $member, 6, ['status' => 'COMPLETED', 'contact_uuid' => 'c-once']);

    $built = (new ClientExperienceDashboard(ctx(), auth(), Period::fromRequest(ctx(), '30d')))->build();

    $metrics = [];
    foreach ($built['metrics'] as $metric) {
        $metrics[$metric['id']] = $metric['value'];
    }

    // Of the three completed appointments INSIDE the window, one (the 20-day-old
    // one) has a later appointment for the same client.
    assertSame(33.3, $metrics['rebook_rate'], 'one of three completed appointments led to another');
    assertSame(1, $metrics['returning_clients'], 'the long-standing client counts as returning');
    assertSame(1, $metrics['new_clients'], 'and the first-timer counts as new');
});

check('booking sources are reported from what created them', function (): void {
    reset();
    $member = makeMember('free-1');
    $service = makeService($member);

    makePastBooking($service, $member, 3, ['booking_source' => 'ONLINE_BOOKING', 'contact_uuid' => 'c-src-1']);
    makePastBooking($service, $member, 4, ['booking_source' => 'ONLINE_BOOKING', 'contact_uuid' => 'c-src-2']);
    makePastBooking($service, $member, 5, ['booking_source' => 'RECEPTIONIST', 'contact_uuid' => 'c-src-3']);

    $built = (new IntelligenceDashboard(ctx(), auth(), Period::fromRequest(ctx(), '30d')))->build();
    $sources = [];
    foreach ($built['panels']['sources'] as $source) {
        $sources[$source['source']] = $source;
    }

    assertSame(2, $sources['ONLINE_BOOKING']['total'], 'two online');
    assertSame(1, $sources['RECEPTIONIST']['total'], 'one from Receptionist');
    assertSame(66.7, $sources['ONLINE_BOOKING']['share'], 'shares add up over the real total');
    assertSame('Online booking', $sources['ONLINE_BOOKING']['label'], 'and are labelled for a reader');
});

check('insights are rule-based when no model is configured', function (): void {
    reset();

    $insights = (new InsightEngine(ctx()))->forSurface('overview', [
        'today_total'      => 20,
        'unconfirmed'      => 6,
        'available_slots'  => 4,
        'waitlist_waiting' => 3,
        'peak_hour'        => ['label' => '15:00 – 17:00', 'bookings' => 8, 'hour' => 15],
    ], 'test-period');

    assertGreaterThan(0, count($insights), 'insights were produced');

    foreach ($insights as $insight) {
        assertSame('rules', $insight['origin'], 'produced by rules, and labelled so');
        assertTrue($insight['title'] !== '', 'has a title');
        assertTrue($insight['rule_key'] !== '', 'names the rule that fired');
        assertGreaterThan(0, count($insight['rule_detail']), 'and shows the thresholds it used');
    }
});

check('a rule below its threshold does not fire', function (): void {
    reset();

    // Two unconfirmed out of twenty is below both the count and the share
    // threshold in the rule.
    $insights = (new InsightEngine(ctx()))->forSurface('overview', [
        'today_total' => 20,
        'unconfirmed' => 2,
    ], 'quiet-period');

    foreach ($insights as $insight) {
        assertTrue($insight['rule_key'] !== 'unconfirmed_backlog', 'the backlog rule stayed quiet');
    }
});

check('insights are cached per period and re-served', function (): void {
    reset();
    $engine = new InsightEngine(ctx());

    $signals = ['today_total' => 20, 'unconfirmed' => 8];

    $first = $engine->forSurface('overview', $signals, 'cache-test');
    $second = $engine->forSurface('overview', $signals, 'cache-test');

    assertGreaterThan(0, count($first), 'produced');
    assertSame(count($first), count($second), 'the same count comes back');
    assertSame(
        $first[0]['insight_uuid'],
        $second[0]['insight_uuid'],
        'and it is the same stored insight, not a second copy',
    );
});

check('an insight can be dismissed and stays dismissed', function (): void {
    reset();
    $engine = new InsightEngine(ctx());

    $insights = $engine->forSurface('overview', ['today_total' => 20, 'unconfirmed' => 8], 'dismiss-test');
    assertGreaterThan(0, count($insights), 'produced');

    assertSame(true, $engine->dismiss($insights[0]['insight_uuid'], 'user-test'), 'dismissed');
    assertSame(false, $engine->dismiss($insights[0]['insight_uuid'], 'user-test'), 'and not twice');
});

// ===========================================================================
section('Cross-app clients');
// ===========================================================================

check('the re-entry guard suppresses a call back to the caller', function (): void {
    reset();

    CrossServiceCallContext::adoptAuthenticatedOrigin('calendar');

    $result = (new Clients\CalendarClient())->forSubscriber('free-1')->freeBusy(['free-1'], Clock::iso(Clock::now()), Clock::iso(Clock::now()->modify('+1 day')));

    assertSame(false, $result['ok'], 'the call was suppressed');
    assertSame('calendar_reentrant_call_refused', $result['error'], 'and says why');

    // Reset for the remaining tests.
    (function (): void {
        $reflection = new \ReflectionClass(CrossServiceCallContext::class);
        $inbound = $reflection->getProperty('inbound');
        $inbound->setValue(null, null);
        $resolved = $reflection->getProperty('resolved');
        $resolved->setValue(null, false);
    })();
});

check('the Calendar client reaches the stub and parses free/busy', function (): void {
    reset();

    $result = (new Clients\CalendarClient())
        ->forSubscriber('busy-1')
        ->freeBusy(['busy-1'], Clock::iso(Clock::now()), Clock::iso(Clock::now()->modify('+2 days')));

    assertTrue($result['ok'], 'call succeeded');
    $subscribers = $result['body']['data']['subscribers'] ?? [];
    assertSame(1, count($subscribers), 'one subscriber');
    assertGreaterThan(0, count($subscribers[0]['busy']), 'with busy blocks');
});

check('a Calendar 503 is reported as a failure, not as empty availability', function (): void {
    reset();

    $result = (new Clients\CalendarClient())
        ->forSubscriber('down-1')
        ->freeBusy(['down-1'], Clock::iso(Clock::now()), Clock::iso(Clock::now()->modify('+1 day')));

    assertSame(false, $result['ok'], 'reported as not ok');
    assertTrue($result['status'] >= 500, 'with the upstream status');
});

// ===========================================================================
// Summary
// ===========================================================================

Clock::freezeForTesting(null);

echo "\n" . str_repeat('=', 60) . "\n";
printf("%d passed, %d failed\n", $passed, $failed);

if ($failed > 0) {
    echo "\nFailed:\n";
    foreach ($failures as $name) {
        echo "  - {$name}\n";
    }
    exit(1);
}

exit(0);
