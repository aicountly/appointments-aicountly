<?php

declare(strict_types=1);

/**
 * HTTP-layer tests: the real router, the real controllers, the real envelopes.
 *
 * ## Why this is separate from integration.php
 *
 * That suite exercises the domain services — the availability arithmetic, the
 * booking lifecycle, the matching. This one exercises everything BETWEEN an
 * HTTP request and those services: route matching, verb handling, the company
 * scope check, permission assertions and the `{data}` envelope.
 *
 * Those are different failures. A wrong route, a controller method that does
 * not exist, or a route declared after a catch-all that swallows it will all
 * pass a domain test suite and 404 in production.
 *
 * ## What it asserts about degradation
 *
 * It runs twice in tests/run.sh — once with the Calendar stub up and once
 * without — because "every dashboard still renders when Calendar is down" and
 * "availability refuses rather than guessing" are both promises this product
 * makes, and only one of them can be tested with the stub running.
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

const CMP = 9101;

/** Set by run.sh: 'up' or 'down'. Decides what the Calendar assertions expect. */
$calendarMode = getenv('CALENDAR_MODE') ?: 'up';

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
        $failed++;
        $failures[] = $name;
    }
}

/**
 * Dispatch through the real router and capture what the controller sent.
 *
 * Http::json() throws ResponseSent under CLI rather than exiting, which is the
 * seam that makes this possible without a web server.
 *
 * @param array<string, mixed> $query
 * @return array{status: int, body: array<string, mixed>}
 */
function call(Router $router, string $method, string $path, array $query = []): array
{
    $_SERVER['REQUEST_METHOD'] = $method;
    $_GET = $query + ['cmp_id' => CMP, 'bo_id' => 0];

    try {
        ob_start();
        $matched = $router->dispatch($method, $path);
        ob_end_clean();

        return ['status' => $matched ? 0 : 404, 'body' => []];
    } catch (ResponseSent $e) {
        return ['status' => $e->status, 'body' => $e->payload];
    } catch (\Throwable $e) {
        return [
            'status' => 500,
            'body'   => ['error' => ['message' => $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()]],
        ];
    }
}

function assertStatus(int $expected, array $response, string $what): void
{
    if ($response['status'] !== $expected) {
        $detail = $response['body']['error']['message'] ?? json_encode($response['body']);
        throw new \RuntimeException("{$what}: expected {$expected}, got {$response['status']} — {$detail}");
    }
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

$tables = [
    'appointment_bookings', 'appointment_service_staff', 'appointment_team_availability',
    'appointment_services', 'appointment_team_members', 'appointment_reminder_steps',
    'appointment_reminder_rules', 'appointment_booking_rules', 'appointment_feature_settings',
];

try {
    Db::connect();
} catch (\Throwable $e) {
    fwrite(STDERR, "Cannot connect to the test database: {$e->getMessage()}\n");
    exit(1);
}

$auth = Auth::forTesting('user-http', 'user', 'appointments', ['acs_type' => 1]);
Auth::adopt($auth);
$ctx = Context::forCompany(CMP);
Context::trustForTesting(CMP, $auth);
Clock::freezeForTesting('2026-06-01T04:00:00Z');

foreach ($tables as $table) {
    Db::run('DELETE FROM ' . $table . ' WHERE cmp_id = :cmp', ['cmp' => CMP]);
}

Domain\Settings::forget();
Domain\Settings::for($ctx);

$member = Uuid::v4();
Db::insert('appointment_team_members', [
    'member_uuid' => $member,
    'cmp_id'      => CMP,
    'user_uuid'   => 'u-http',
    'calendar_subscriber_uuid' => 'free-http',
    'display_label' => 'Dr Http',
], 'member_uuid');

for ($day = 1; $day <= 5; $day++) {
    Db::insert('appointment_team_availability', [
        'availability_uuid' => Uuid::v4(),
        'cmp_id'        => CMP,
        'member_uuid'   => $member,
        'bo_id'         => 0,
        'day_of_week'   => $day,
        'starts_minute' => 540,
        'ends_minute'   => 1020,
        'kind'          => 'available',
    ], 'availability_uuid');
}

$service = Uuid::v4();
Db::insert('appointment_services', [
    'service_uuid'     => $service,
    'cmp_id'           => CMP,
    'name'             => 'HTTP Consultation',
    'duration_minutes' => 60,
    'modes'            => ['IN_PERSON'],
], 'service_uuid');

Db::insert('appointment_service_staff', [
    'service_uuid' => $service,
    'member_uuid'  => $member,
    'cmp_id'       => CMP,
    'priority'     => 0,
], 'service_uuid');

$router = new Router();
Routes::register($router);

echo "HTTP layer tests (Calendar {$calendarMode})\n";
echo str_repeat('=', 60) . "\n";

// ---------------------------------------------------------------------------
// Every read endpoint answers
// ---------------------------------------------------------------------------

foreach ([
    'v1/session', 'v1/permissions', 'v1/settings', 'v1/services', 'v1/team',
    'v1/locations', 'v1/resources', 'v1/clients', 'v1/waitlist', 'v1/forms',
    'v1/booking-pages', 'v1/integrations', 'v1/bookings', 'v1/dashboards',
    'v1/settings/reminder-rules', 'v1/calendar', 'v1/access/catalogue',
    'v1/access/profiles',
] as $path) {
    check("GET /{$path}", function () use ($router, $path) {
        $response = call($router, 'GET', $path);
        assertStatus(200, $response, $path);
        if (!isset($response['body']['data'])) {
            throw new \RuntimeException('no {data} envelope');
        }
    });
}

// ---------------------------------------------------------------------------
// The five dashboards, which must render whatever Calendar is doing
// ---------------------------------------------------------------------------

foreach (['overview', 'live', 'capacity', 'client-experience', 'intelligence'] as $view) {
    check("GET /v1/dashboards/{$view}", function () use ($router, $view) {
        $response = call($router, 'GET', "v1/dashboards/{$view}");
        assertStatus(200, $response, $view);

        $data = $response['body']['data'];
        foreach (['view', 'period', 'currency', 'metrics', 'panels', 'insights', 'freshness'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new \RuntimeException("missing {$key}");
            }
        }
        if ($data['metrics'] === []) {
            throw new \RuntimeException('no metrics');
        }
        if (($data['freshness']['sources'] ?? []) === []) {
            throw new \RuntimeException('no sources named — every panel must say who owns its data');
        }
    });
}

// ---------------------------------------------------------------------------
// Availability, which must NOT render when Calendar is down
// ---------------------------------------------------------------------------

check('GET /v1/availability/slots', function () use ($router, $service, $calendarMode) {
    $response = call($router, 'GET', 'v1/availability/slots', [
        'service_uuid' => $service,
        'from' => Clock::iso(Clock::now()),
        'to'   => Clock::iso(Clock::now()->modify('+3 days')),
    ]);

    if ($calendarMode === 'down') {
        // The promise: a slot this product cannot confirm is a slot it will not
        // offer. 503 rather than 200-with-an-empty-list, so a client that only
        // checks the status code cannot conclude "no availability" from "we
        // could not look".
        assertStatus(503, $response, 'slots with Calendar down');
        if (($response['body']['error']['code'] ?? '') !== 'calendar_unavailable') {
            throw new \RuntimeException('wrong error code: ' . json_encode($response['body']['error'] ?? []));
        }
        if (($response['body']['error']['details']['retryable'] ?? false) !== true) {
            throw new \RuntimeException('should be marked retryable');
        }

        return;
    }

    assertStatus(200, $response, 'slots');
    if ($response['body']['data']['calendar_available'] !== true) {
        throw new \RuntimeException('calendar reported unavailable with the stub up');
    }
    if ($response['body']['data']['slots'] === []) {
        throw new \RuntimeException('no slots offered');
    }
});

check('GET /v1/availability/interpret falls back to keywords with no model', function () use ($router) {
    $response = call($router, 'GET', 'v1/availability/interpret', ['q' => 'http consultation tomorrow morning']);
    assertStatus(200, $response, 'interpret');

    if ($response['body']['data']['origin'] !== 'keywords') {
        throw new \RuntimeException('expected the keyword fallback with no AI configured');
    }
    if ($response['body']['data']['interpreted']['daypart'] !== 'morning') {
        throw new \RuntimeException('did not read "morning"');
    }
});

// ---------------------------------------------------------------------------
// Routing itself
// ---------------------------------------------------------------------------

check('an unknown dashboard is a 404, not a 500', function () use ($router) {
    assertStatus(404, call($router, 'GET', 'v1/dashboards/not-a-view'), 'unknown view');
});

check('a wrong verb answers 405, not 404', function () use ($router) {
    assertStatus(405, call($router, 'DELETE', 'v1/services'), 'wrong verb');
});

check('the form route is not swallowed by the transition catch-all', function () use ($router) {
    // POST /v1/bookings/{id}/form and POST /v1/bookings/{id}/{action} are both
    // four segments. Declaration order decides, and getting it wrong sends a
    // form submission to the status machine.
    $response = call($router, 'POST', 'v1/bookings/' . Uuid::v4() . '/form');

    // 404 because the booking does not exist — the point is that it reached
    // FormsController and not "there is no 'form' status".
    if ($response['status'] === 404) {
        $message = (string) ($response['body']['error']['message'] ?? '');
        if (str_contains($message, 'action')) {
            throw new \RuntimeException('the catch-all transition route swallowed it');
        }
    }
});

check('a missing company is refused before any query', function () use ($router) {
    $_GET = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';

    try {
        ob_start();
        $router->dispatch('GET', 'v1/services');
        ob_end_clean();
        throw new \RuntimeException('no response');
    } catch (ResponseSent $e) {
        if ($e->status !== 400 || ($e->payload['error']['code'] ?? '') !== 'context_required') {
            throw new \RuntimeException('expected 400 context_required, got ' . $e->status);
        }
    }
});

// ---------------------------------------------------------------------------
// Public routes, which must resolve their company from a slug and never a param
// ---------------------------------------------------------------------------

check('an unpublished booking page 404s publicly', function () use ($router) {
    $response = call($router, 'GET', 'public/pages/does-not-exist');
    assertStatus(404, $response, 'unknown slug');
});

check('a public slug cannot be a company id', function () use ($router) {
    // Three characters minimum and only [a-z0-9-], so "9101" is not a slug and
    // certainly does not resolve to company 9101.
    $response = call($router, 'GET', 'public/pages/' . CMP);
    assertStatus(404, $response, 'numeric slug');
});

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

Clock::freezeForTesting(null);

foreach ($tables as $table) {
    Db::run('DELETE FROM ' . $table . ' WHERE cmp_id = :cmp', ['cmp' => CMP]);
}

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
