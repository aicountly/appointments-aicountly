<?php

declare(strict_types=1);

/**
 * A stand-in for Aicountly Calendar, Contacts and Manage.
 *
 * ## Why a real HTTP server and not a mock
 *
 * The integration tests exercise the actual ApiClient — its timeouts, its
 * header handling, its envelope parsing, its re-entry guard — against something
 * that speaks HTTP. A mocked client would test the tests.
 *
 * ## What it models
 *
 * Calendar's free/busy and conflict-check contract, including the two cases the
 * booking rules turn on:
 *
 *   - a busy block, so a slot can be shown as taken;
 *   - `available: false` on a subscriber, so "we could not read that calendar"
 *     can be told apart from "that calendar is empty".
 *
 * Behaviour is switched by the subscriber uuid so a test can ask for the case it
 * wants without a stateful setup step:
 *
 *   busy-*        one busy block, 10:00–11:00 UTC on every day in the window
 *   unreadable-*  available: false
 *   down-*        HTTP 503, as though Calendar itself were failing
 *   anything else free, plus whatever has been booked through it
 *
 * ## It remembers the events it is given
 *
 * Deliberately. Appointments writes a booking's event here and then relies on
 * free/busy to report that time as taken — it does NOT keep its own copy, which
 * is the whole architecture. A stateless stub would make every such slot look
 * free and would quietly stop the tests from exercising the real path. So
 * created events are stored in a file for the life of the run and returned by
 * free/busy and conflict-check, exactly as Calendar does.
 *
 * Run by tests/run.sh; not deployed.
 */

/** Where created events live for the life of a test run. */
function store(): string
{
    return sys_get_temp_dir() . '/appointments-calendar-stub-' . (getenv('STUB_RUN') ?: 'default') . '.json';
}

/** @return array<string, list<array<string, mixed>>> subscriber => events */
function loadEvents(): array
{
    $path = store();
    if (!is_file($path)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : [];
}

/** @param array<string, list<array<string, mixed>>> $events */
function saveEvents(array $events): void
{
    file_put_contents(store(), json_encode($events), LOCK_EX);
}

function actor(): string
{
    return trim((string) ($_SERVER['HTTP_X_ACTOR_UUID'] ?? ''));
}

/** Events stored for one subscriber that overlap a window, as busy blocks. */
function storedBusy(string $subscriber, string $start, string $end): array
{
    $out = [];
    foreach (loadEvents()[$subscriber] ?? [] as $event) {
        if (($event['status'] ?? 'confirmed') === 'cancelled') {
            continue;
        }
        if ((string) $event['start_at'] < $end && (string) $event['end_at'] > $start) {
            $out[] = [
                'event_id' => (string) $event['id'],
                'start_at' => (string) $event['start_at'],
                'end_at'   => (string) $event['end_at'],
                'all_day'  => false,
                'status'   => (string) ($event['status'] ?? 'confirmed'),
                'source'   => 'aicountly_native',
            ];
        }
    }

    return $out;
}

$path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
$path = preg_replace('#^api/#', '', $path) ?? $path;
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

header('Content-Type: application/json');

function body(): array
{
    $raw = (string) file_get_contents('php://input');
    $decoded = $raw === '' ? [] : json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

function reply(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(['success' => $status < 400, 'data' => $data, 'message' => 'ok', 'errors' => []]);
    exit;
}

/** One busy block per day in the window, 10:00–11:00 UTC. */
function busyBlocks(string $start, string $end): array
{
    try {
        $from = new DateTimeImmutable($start);
        $to = new DateTimeImmutable($end);
    } catch (Throwable) {
        return [];
    }

    $blocks = [];
    $day = $from->setTime(10, 0);

    while ($day < $to && count($blocks) < 200) {
        if ($day >= $from) {
            $blocks[] = [
                'event_id' => 'busy-' . $day->format('Ymd'),
                'start_at' => $day->format('Y-m-d\TH:i:s\Z'),
                'end_at'   => $day->modify('+1 hour')->format('Y-m-d\TH:i:s\Z'),
                'all_day'  => false,
                'status'   => 'confirmed',
                'source'   => 'google',
            ];
        }
        $day = $day->modify('+1 day');
    }

    return $blocks;
}

// --- Calendar --------------------------------------------------------------

if ($path === 'health') {
    reply(['status' => 'ok', 'app' => 'Calendar stub']);
}

// Not a Calendar route: the test harness calls this between cases so each one
// starts from an empty calendar, the way a fresh company would.
if ($path === 'stub/reset' && $method === 'POST') {
    @unlink(store());
    reply(['reset' => true]);
}

if ($path === 'calendar/free-busy') {
    $start = (string) ($_GET['start'] ?? '');
    $end = (string) ($_GET['end'] ?? '');
    $subscribers = array_filter(explode(',', (string) ($_GET['subscribers'] ?? '')));

    $out = [];
    foreach ($subscribers as $subscriber) {
        $subscriber = trim($subscriber);

        if (str_starts_with($subscriber, 'down-')) {
            http_response_code(503);
            echo json_encode(['success' => false, 'message' => 'Calendar is down', 'data' => null]);
            exit;
        }

        $busy = str_starts_with($subscriber, 'busy-') ? busyBlocks($start, $end) : [];
        $busy = array_merge($busy, storedBusy($subscriber, $start, $end));

        $out[] = [
            'subscriber_uuid' => $subscriber,
            'available'       => !str_starts_with($subscriber, 'unreadable-'),
            'error'           => str_starts_with($subscriber, 'unreadable-') ? 'Could not read' : null,
            'busy'            => str_starts_with($subscriber, 'unreadable-') ? [] : $busy,
        ];
    }

    reply(['range' => ['start' => $start, 'end' => $end], 'subscribers' => $out, 'generated_at' => gmdate('c')]);
}

if ($path === 'calendar/conflict-check') {
    $input = body();
    $subscribers = (array) ($input['subscribers'] ?? []);
    $start = (string) ($input['start_at'] ?? '');
    $end = (string) ($input['end_at'] ?? '');
    $ignore = array_flip(array_map('strval', (array) ($input['ignore_event_ids'] ?? [])));

    $out = [];
    $free = true;
    $checked = true;

    foreach ($subscribers as $subscriber) {
        $subscriber = trim((string) $subscriber);

        if (str_starts_with($subscriber, 'down-')) {
            http_response_code(503);
            echo json_encode(['success' => false, 'message' => 'Calendar is down', 'data' => null]);
            exit;
        }

        if (str_starts_with($subscriber, 'unreadable-')) {
            $checked = false;
            $free = false;
            $out[] = ['subscriber_uuid' => $subscriber, 'free' => false, 'conflicts' => [], 'checked' => false];
            continue;
        }

        $candidates = str_starts_with($subscriber, 'busy-') ? busyBlocks($start, $end) : [];
        $candidates = array_merge($candidates, storedBusy($subscriber, $start, $end));

        $conflicts = [];
        foreach ($candidates as $block) {
            if (isset($ignore[$block['event_id']])) {
                continue;
            }
            if ($block['start_at'] < $end && $block['end_at'] > $start) {
                $conflicts[] = $block;
            }
        }

        if ($conflicts !== []) {
            $free = false;
        }

        $out[] = [
            'subscriber_uuid' => $subscriber,
            'free'            => $conflicts === [],
            'conflicts'       => $conflicts,
            'checked'         => true,
        ];
    }

    reply(['free' => $free, 'checked' => $checked, 'range' => ['start' => $start, 'end' => $end], 'subscribers' => $out]);
}

if ($path === 'calendar/events' && $method === 'POST') {
    $input = body();

    $event = [
        'id'       => 'evt-' . substr(hash('sha256', json_encode($input) . microtime()), 0, 32),
        'title'    => (string) ($input['title'] ?? ''),
        'start_at' => (string) ($input['start_at'] ?? ''),
        'end_at'   => (string) ($input['end_at'] ?? ''),
        'status'   => (string) ($input['status'] ?? 'confirmed'),
        'source'   => 'aicountly_native',
    ];

    // Remembered, so free/busy reports this time as taken on the next call.
    $events = loadEvents();
    $events[actor()][] = $event;
    saveEvents($events);

    reply(['event' => $event], 201);
}

if (preg_match('#^calendar/events/([^/]+)$#', $path, $m) === 1) {
    $eventId = $m[1];
    $events = loadEvents();
    $subscriber = actor();

    if ($method === 'DELETE') {
        $events[$subscriber] = array_values(array_filter(
            $events[$subscriber] ?? [],
            static fn (array $e) => (string) $e['id'] !== $eventId,
        ));
        saveEvents($events);
        reply(['ok' => true]);
    }

    if ($method === 'PATCH') {
        $patch = body();
        $found = null;

        foreach ($events[$subscriber] ?? [] as $index => $existing) {
            if ((string) $existing['id'] !== $eventId) {
                continue;
            }
            foreach (['title', 'start_at', 'end_at', 'status'] as $field) {
                if (array_key_exists($field, $patch)) {
                    $existing[$field] = (string) $patch[$field];
                }
            }
            $events[$subscriber][$index] = $existing;
            $found = $existing;
            break;
        }

        saveEvents($events);

        // A cancelled event stops occupying time, which is what makes the
        // "cancelling frees the slot" behaviour real rather than asserted.
        reply(['event' => $found ?? [
            'id'       => $eventId,
            'title'    => 'Stub event',
            'start_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'end_at'   => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
            'status'   => (string) ($patch['status'] ?? 'confirmed'),
            'source'   => 'aicountly_native',
        ]]);
    }

    foreach ($events[$subscriber] ?? [] as $existing) {
        if ((string) $existing['id'] === $eventId) {
            reply(['event' => $existing]);
        }
    }

    reply([
        'event' => [
            'id'       => $eventId,
            'title'    => 'Stub event',
            'start_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'end_at'   => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
            'status'   => 'confirmed',
            'source'   => 'aicountly_native',
        ],
    ]);
}

if ($path === 'calendar/provider-accounts') {
    reply([
        'accounts' => [
            ['provider' => 'google', 'connection_status' => 'connected', 'display_name' => 'work@example.com', 'last_sync_at' => gmdate('c')],
        ],
    ]);
}

// --- Manage ----------------------------------------------------------------

if ($path === 'companyinfo') {
    $cmpId = (int) ($_GET['comp_id'] ?? 0);

    reply([
        'cmp_id'   => $cmpId,
        'cmp_name' => 'Stub Clinic',
        'branches' => [
            ['bo_id' => 1, 'bo_name' => 'Jalandhar', 'address' => '1 Test Road'],
            ['bo_id' => 2, 'bo_name' => 'Ludhiana', 'address' => '2 Test Road'],
        ],
    ]);
}

if ($path === 'companies') {
    reply([['cmp_id' => 7, 'cmp_name' => 'Stub Clinic']]);
}

if (preg_match('#^companies/(\d+)/share$#', $path) === 1) {
    reply([
        ['uuid_aictly' => 'user-rahul', 'name' => 'Rahul Gupta', 'email' => 'rahul@example.com'],
        ['uuid_aictly' => 'user-neha', 'name' => 'Neha Sharma', 'email' => 'neha@example.com'],
    ]);
}

// --- Contacts --------------------------------------------------------------

if ($path === 'contacts' && $method === 'GET') {
    reply([['contact_uuid' => 'contact-1', 'name' => 'Priya Kapoor', 'mobile' => '+919876543210', 'email' => 'priya@example.com']]);
}

if ($path === 'contacts' && $method === 'POST') {
    reply(['contact_uuid' => 'contact-' . substr(hash('sha256', json_encode(body())), 0, 12)], 201);
}

if (preg_match('#^contacts/([^/]+)$#', $path, $m) === 1) {
    reply(['contact_uuid' => $m[1], 'name' => 'Priya Kapoor', 'mobile' => '+919876543210', 'email' => 'priya@example.com']);
}

http_response_code(404);
echo json_encode(['success' => false, 'message' => 'stub: no route for ' . $method . ' /' . $path, 'data' => null]);
