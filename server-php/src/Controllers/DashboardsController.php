<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Ai\InsightEngine;
use Aicountly\Api\Dashboards\CapacityDashboard;
use Aicountly\Api\Dashboards\ClientExperienceDashboard;
use Aicountly\Api\Dashboards\IntelligenceDashboard;
use Aicountly\Api\Dashboards\LiveOperationsDashboard;
use Aicountly\Api\Dashboards\OverviewDashboard;
use Aicountly\Api\Dashboards\Period;
use Aicountly\Api\Http;
use Aicountly\Api\Support\Uuid;

/**
 * The five dashboards.
 *
 * ## The view is in the path
 *
 * `GET /api/v1/dashboards/{view}`. One endpoint per view, so a link to a
 * dashboard is a link to THAT dashboard and the browser's Back button behaves.
 * Only the view somebody is looking at is computed — five dashboards that all
 * load at once is five times the work for one screen of it.
 *
 * ## Each view is one request
 *
 * Whatever the view needs, it aggregates in SQL and returns once. A dashboard
 * that fires fourteen requests paints in fourteen stages and hammers whichever
 * product it is reading from.
 */
final class DashboardsController extends Controller
{
    /** @var array<string, class-string<\Aicountly\Api\Dashboards\Dashboard>> */
    private const VIEWS = [
        'overview'          => OverviewDashboard::class,
        'live'              => LiveOperationsDashboard::class,
        'capacity'          => CapacityDashboard::class,
        'client-experience' => ClientExperienceDashboard::class,
        'intelligence'      => IntelligenceDashboard::class,
    ];

    /** The default period each view opens on, which is not the same question in each case. */
    private const DEFAULT_PERIOD = [
        'overview'          => 'today',
        'live'              => 'today',
        'capacity'          => 'last_4_weeks',
        'client-experience' => '30d',
        'intelligence'      => 'last_7_weeks',
    ];

    public static function show(string $view): void
    {
        [$auth, $ctx] = self::enter('appointments.dashboard.view');

        $view = strtolower(trim($view));
        $class = self::VIEWS[$view] ?? null;

        if ($class === null) {
            Http::notFound('There is no "' . $view . '" dashboard. Try: ' . implode(', ', array_keys(self::VIEWS)) . '.');
        }

        $period = Period::fromRequest($ctx, self::DEFAULT_PERIOD[$view] ?? '30d');

        Http::data((new $class($ctx, $auth, $period))->build());
    }

    /** The view list, for a navigation that does not hardcode it. */
    public static function index(): void
    {
        self::enter('appointments.dashboard.view');

        Http::data([
            'views' => [
                ['id' => 'overview',          'label' => 'Overview',          'title' => 'Appointment Command Center',            'subtitle' => 'Real-time insights. Smoother days. Happier clients.'],
                ['id' => 'live',              'label' => 'Live Operations',   'title' => 'Live Operations Dashboard',             'subtitle' => 'Manage real-time operations and keep your day on track.'],
                ['id' => 'capacity',          'label' => 'Capacity',          'title' => 'Availability & Capacity Intelligence',  'subtitle' => 'Understand where you have capacity and optimise your schedule.'],
                ['id' => 'client-experience', 'label' => 'Client Experience', 'title' => 'Client Experience & Engagement',        'subtitle' => 'Track how clients book, confirm, attend and come back.'],
                ['id' => 'intelligence',      'label' => 'Intelligence',      'title' => 'Business Intelligence & Growth',        'subtitle' => 'Track performance, trends and opportunities for growth.'],
            ],
        ]);
    }

    /**
     * Dismiss an insight.
     *
     * A suggestion somebody has decided against should go away, and stay away
     * until the underlying figures change enough for the rule to fire on a new
     * period. An insight that reappears on every refresh is an insight people
     * learn to ignore.
     */
    public static function dismissInsight(string $insightUuid): void
    {
        [$auth, $ctx] = self::enter('appointments.dashboard.view');

        if (!Uuid::isValid($insightUuid)) {
            Http::notFound('That insight does not exist.');
        }

        $dismissed = (new InsightEngine($ctx))->dismiss($insightUuid, $auth->uuid);

        if (!$dismissed) {
            Http::notFound('That insight does not exist or has already been dismissed.');
        }

        Http::data(['dismissed' => true]);
    }
}
