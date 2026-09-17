<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Controllers\AccessController;
use Aicountly\Api\Controllers\AvailabilityController;
use Aicountly\Api\Controllers\BookingPagesController;
use Aicountly\Api\Controllers\BookingsController;
use Aicountly\Api\Controllers\CalendarViewController;
use Aicountly\Api\Controllers\ClientsController;
use Aicountly\Api\Controllers\DashboardsController;
use Aicountly\Api\Controllers\FormsController;
use Aicountly\Api\Controllers\IntegrationsController;
use Aicountly\Api\Controllers\ManageController;
use Aicountly\Api\Controllers\PublicBookingController;
use Aicountly\Api\Controllers\ResourcesController;
use Aicountly\Api\Controllers\ServicesController;
use Aicountly\Api\Controllers\SettingsController;
use Aicountly\Api\Controllers\TeamController;
use Aicountly\Api\Controllers\WaitlistController;

/**
 * Every route this API serves.
 *
 * The shape follows the rest of the fleet: `/api/v1/<resource>`, company
 * context on the query string or in the body, `{data}` / `{data, meta}`
 * envelopes.
 *
 * `/api/public/*` is the one exception and is deliberately grouped apart: those
 * routes resolve no Auth, resolve their company from a page slug, and carry
 * their own rate limits. Keeping them visibly separate in this file is how
 * somebody reading the route table can tell at a glance which endpoints a
 * stranger can reach.
 */
final class Routes
{
    public static function register(Router $router): void
    {
        // ------------------------------------------------------------------
        // The company switcher. Live reads from Manage, NOT company-scoped —
        // this is what the caller uses to choose a company in the first place.
        // ------------------------------------------------------------------
        $router->get('v1/manage/companies', [ManageController::class, 'companies']);
        $router->get('v1/manage/companyinfo', [ManageController::class, 'companyInfo']);

        // ------------------------------------------------------------------
        // Session, settings and policies.
        // ------------------------------------------------------------------
        $router->get('v1/session', [SettingsController::class, 'session']);
        $router->get('v1/permissions', [SettingsController::class, 'permissions']);
        $router->get('v1/settings', [SettingsController::class, 'show']);
        $router->put('v1/settings', [SettingsController::class, 'update']);
        $router->post('v1/settings/rules', [SettingsController::class, 'saveRule']);
        $router->get('v1/settings/reminder-rules', [SettingsController::class, 'reminderRulesIndex']);
        $router->post('v1/settings/reminder-rules', [SettingsController::class, 'saveReminderRule']);

        // ------------------------------------------------------------------
        // The five dashboards. The view is in the path so a link to one is a
        // link to that one, and Back behaves.
        // ------------------------------------------------------------------
        $router->get('v1/dashboards', [DashboardsController::class, 'index']);
        $router->get('v1/dashboards/{view}', [DashboardsController::class, 'show']);
        $router->delete('v1/insights/{insight}', [DashboardsController::class, 'dismissInsight']);

        // ------------------------------------------------------------------
        // Availability. Every slot here has been checked against live Calendar
        // free/busy — see Domain/AvailabilityService.php.
        // ------------------------------------------------------------------
        $router->get('v1/availability/slots', [AvailabilityController::class, 'slots']);
        $router->get('v1/availability/interpret', [AvailabilityController::class, 'interpret']);
        $router->post('v1/availability/holds', [AvailabilityController::class, 'hold']);
        $router->delete('v1/availability/holds/{hold}', [AvailabilityController::class, 'releaseHold']);

        // ------------------------------------------------------------------
        // Appointments.
        // ------------------------------------------------------------------
        $router->get('v1/bookings', [BookingsController::class, 'index']);
        $router->post('v1/bookings', [BookingsController::class, 'create']);
        $router->get('v1/bookings/{booking}', [BookingsController::class, 'show']);
        $router->post('v1/bookings/{booking}/reschedule', [BookingsController::class, 'reschedule']);
        $router->post('v1/bookings/{booking}/cancel', [BookingsController::class, 'cancel']);
        $router->post('v1/bookings/{booking}/retry-calendar', [BookingsController::class, 'retryCalendar']);

        // The intake form for one appointment. Declared BEFORE the catch-all
        // transition route below, which would otherwise match
        // POST /v1/bookings/{id}/form and try to move it to a "form" status.
        $router->get('v1/bookings/{booking}/form', [FormsController::class, 'answers']);
        $router->post('v1/bookings/{booking}/form', [FormsController::class, 'submit']);

        // confirm | arrive | start | complete | no-show. LAST, because it
        // matches any single trailing segment.
        $router->post('v1/bookings/{booking}/{action}', [BookingsController::class, 'transition']);

        // ------------------------------------------------------------------
        // The calendar UI. Drawn here, owned by Aicountly Calendar.
        // ------------------------------------------------------------------
        $router->get('v1/calendar', [CalendarViewController::class, 'view']);

        // ------------------------------------------------------------------
        // Catalogue: services, team, locations and resources.
        // ------------------------------------------------------------------
        $router->get('v1/services', [ServicesController::class, 'index']);
        $router->post('v1/services', [ServicesController::class, 'create']);
        $router->get('v1/services/{service}', [ServicesController::class, 'show']);
        $router->put('v1/services/{service}', [ServicesController::class, 'update']);
        $router->delete('v1/services/{service}', [ServicesController::class, 'deactivate']);

        $router->get('v1/team', [TeamController::class, 'index']);
        $router->post('v1/team', [TeamController::class, 'create']);
        $router->get('v1/team/candidates', [TeamController::class, 'candidates']);
        $router->get('v1/team/{member}', [TeamController::class, 'show']);
        $router->put('v1/team/{member}', [TeamController::class, 'update']);

        $router->get('v1/locations', [ResourcesController::class, 'locations']);
        $router->post('v1/locations', [ResourcesController::class, 'saveLocation']);
        $router->get('v1/resources', [ResourcesController::class, 'index']);
        $router->post('v1/resources', [ResourcesController::class, 'create']);
        $router->put('v1/resources/{resource}', [ResourcesController::class, 'update']);

        // ------------------------------------------------------------------
        // Clients. Identity is Contacts'; the appointment profile is ours.
        // ------------------------------------------------------------------
        $router->get('v1/clients', [ClientsController::class, 'index']);
        $router->get('v1/clients/search', [ClientsController::class, 'search']);
        $router->post('v1/clients', [ClientsController::class, 'create']);
        $router->get('v1/clients/{contact}', [ClientsController::class, 'show']);
        $router->put('v1/clients/{contact}/preferences', [ClientsController::class, 'updatePreferences']);

        // ------------------------------------------------------------------
        // Waitlist.
        // ------------------------------------------------------------------
        $router->get('v1/waitlist', [WaitlistController::class, 'index']);
        $router->post('v1/waitlist', [WaitlistController::class, 'create']);
        $router->get('v1/waitlist/matches', [WaitlistController::class, 'matches']);
        $router->post('v1/waitlist/{entry}/offer', [WaitlistController::class, 'offer']);
        $router->delete('v1/waitlist/{entry}', [WaitlistController::class, 'withdraw']);

        // ------------------------------------------------------------------
        // Forms.
        // ------------------------------------------------------------------
        $router->get('v1/forms', [FormsController::class, 'index']);
        $router->post('v1/forms', [FormsController::class, 'save']);
        $router->get('v1/forms/{form}', [FormsController::class, 'show']);

        // ------------------------------------------------------------------
        // Public booking pages — the configuration side.
        // ------------------------------------------------------------------
        $router->get('v1/booking-pages', [BookingPagesController::class, 'index']);
        $router->post('v1/booking-pages', [BookingPagesController::class, 'save']);
        $router->delete('v1/booking-pages/{page}', [BookingPagesController::class, 'delete']);

        // ------------------------------------------------------------------
        // Integrations. Nothing here says "connected" unless it answered.
        // ------------------------------------------------------------------
        $router->get('v1/integrations', [IntegrationsController::class, 'index']);

        // ------------------------------------------------------------------
        // Access. Every route needs `appointments.access.manage`, and the
        // escalation and self-lockout rules live in the controller, not the UI.
        // ------------------------------------------------------------------
        $router->get('v1/access/catalogue', [AccessController::class, 'catalogue']);
        $router->get('v1/access/profiles', [AccessController::class, 'profiles']);
        $router->post('v1/access/profiles', [AccessController::class, 'saveProfile']);
        $router->delete('v1/access/profiles/{profile}', [AccessController::class, 'deleteProfile']);
        $router->get('v1/access/members', [AccessController::class, 'members']);
        $router->post('v1/access/members', [AccessController::class, 'assign']);
        $router->delete('v1/access/members/{assignment}', [AccessController::class, 'unassign']);
        $router->get('v1/access/people', [AccessController::class, 'people']);

        // ==================================================================
        // PUBLIC — no session, no company parameter, rate limited.
        //
        // Everything below is reachable by a stranger with a link. The
        // company comes from the page slug and never from a request
        // parameter; the protections are in
        // Controllers/PublicBookingController.php.
        // ==================================================================
        $router->get('public/pages/{slug}', [PublicBookingController::class, 'page']);
        $router->get('public/pages/{slug}/slots', [PublicBookingController::class, 'slots']);
        $router->post('public/pages/{slug}/holds', [PublicBookingController::class, 'hold']);
        $router->post('public/pages/{slug}/bookings', [PublicBookingController::class, 'book']);
        $router->get('public/bookings/{booking}', [PublicBookingController::class, 'show']);
        $router->post('public/bookings/{booking}/cancel', [PublicBookingController::class, 'cancel']);
    }
}
