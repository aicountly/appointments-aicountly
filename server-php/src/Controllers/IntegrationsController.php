<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Ai\ConsoleCredentials;
use Aicountly\Api\Clients\BillingClient;
use Aicountly\Api\Clients\CalendarClient;
use Aicountly\Api\Clients\ConnectClient;
use Aicountly\Api\Clients\ContactsClient;
use Aicountly\Api\Clients\CrmClient;
use Aicountly\Api\Clients\MessagingClient;
use Aicountly\Api\Clients\PayClient;
use Aicountly\Api\Clients\ReceptionistClient;
use Aicountly\Api\Domain\Settings;
use Aicountly\Api\Features;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * What Appointments is connected to, and honestly.
 *
 * ## The rule this screen exists to keep
 *
 * NOTHING SAYS "CONNECTED" UNLESS IT ANSWERED. A client that is configured but
 * unreachable reports `error`; one whose flag is off reports `setup_required`
 * with the env key an administrator needs; one whose product is still being
 * built reports `coming_soon`. There is no state in which an unfinished
 * integration looks finished, because a Connected badge on a service that is
 * not there is how somebody plans a launch around a deposit flow that does not
 * exist.
 *
 * ## Calendar is not optional
 *
 * It is listed first and marked `required`. An appointment product with no
 * calendar cannot compute availability or write a booking, so this screen says
 * that outright rather than leaving it as one card among nine.
 */
final class IntegrationsController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter('appointments.dashboard.view');

        // The env-key hints are for somebody who could act on them. A
        // receptionist seeing "set PAY_SERVICE_KEY" learns nothing and is being
        // shown deployment internals for no reason.
        $showHints = Permissions::allows($ctx, $auth, 'appointments.integrations.manage');

        Http::data([
            'integrations' => [
                self::calendar($showHints),
                self::contacts($showHints),
                self::crm($showHints),
                self::receptionist($showHints),
                self::connect($showHints),
                self::pay($showHints),
                self::billing($showHints),
                self::messaging($showHints),
                self::ai($ctx, $showHints),
                self::websiteWidget($ctx),
            ],
            'statuses' => [
                ['key' => 'connected',      'label' => 'Connected'],
                ['key' => 'available',      'label' => 'Available'],
                ['key' => 'setup_required', 'label' => 'Setup required'],
                ['key' => 'coming_soon',    'label' => 'Coming soon'],
                ['key' => 'unavailable',    'label' => 'Unavailable'],
                ['key' => 'error',          'label' => 'Error'],
            ],
        ]);
    }

    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function calendar(bool $showHints): array
    {
        $client = new CalendarClient();

        if (!$client->configured()) {
            return self::card(
                'calendar',
                'Aicountly Calendar',
                'setup_required',
                'Owns every calendar and event. Appointments reads availability and writes bookings here, live.',
                $showHints ? 'Set CALENDAR_SERVICE_KEY in the server environment, and add the matching key to '
                    . 'CALENDAR_SERVICE_KEYS on the Calendar host.' : null,
                required: true,
                owns: [
                    'Calendars and calendar events',
                    'Event start and end times',
                    'Free/busy and conflicts',
                    'Google, Outlook and Apple synchronisation',
                ],
            );
        }

        // Asked, not assumed. A configured key proves nothing about whether the
        // other end is up.
        $health = $client->health();

        if (!$health['ok']) {
            return self::card(
                'calendar',
                'Aicountly Calendar',
                'error',
                'Owns every calendar and event. Appointments reads availability and writes bookings here, live.',
                $showHints ? 'Calendar did not answer. Check that ' . $client->base() . ' is reachable from this host.' : null,
                required: true,
                detail: 'Availability cannot be shown and bookings cannot be written until Calendar answers.',
            );
        }

        return self::card(
            'calendar',
            'Aicountly Calendar',
            'connected',
            'Owns every calendar and event. Appointments reads availability and writes bookings here, live.',
            null,
            required: true,
            owns: [
                'Calendars and calendar events',
                'Event start and end times',
                'Free/busy and conflicts',
                'Google, Outlook and Apple synchronisation',
            ],
        );
    }

    /** @return array<string, mixed> */
    private static function contacts(bool $showHints): array
    {
        $client = new ContactsClient();

        return self::card(
            'contacts',
            'Aicountly Contacts',
            $client->configured() ? 'connected' : 'setup_required',
            'The authoritative directory of clients. Appointments keeps a contact reference and appointment-specific preferences.',
            $client->configured() ? null : ($showHints ? 'Set APPOINTMENTS_CONTACTS_ENABLED=1 and, if Contacts is on another host, CONTACTS_API_BASE.' : null),
            owns: ['Client names, phone numbers, emails and addresses'],
        );
    }

    /** @return array<string, mixed> */
    private static function crm(bool $showHints): array
    {
        $client = new CrmClient();

        return self::card(
            'crm',
            'Aicountly CRM',
            $client->configured() ? 'connected' : 'setup_required',
            'Relationship context shown next to an appointment. Read live; never copied.',
            $client->configured() ? null : ($showHints ? 'Set APPOINTMENTS_CRM_ENABLED=1 and CRM_SERVICE_KEY.' : null),
            owns: ['Customer 360, relationship history and health'],
        );
    }

    /** @return array<string, mixed> */
    private static function receptionist(bool $showHints): array
    {
        $client = new ReceptionistClient();

        if ($client->configured()) {
            return self::card(
                'receptionist',
                'Aicountly Receptionist',
                'connected',
                'The voice front desk. Receptionist calls Appointments to find slots and book them; Appointments shows its contribution.',
                null,
                owns: ['Calls, recordings, transcripts and voice conversations'],
            );
        }

        return self::card(
            'receptionist',
            'Aicountly Receptionist',
            'coming_soon',
            'The voice front desk. Receptionist will call Appointments to find slots and book them.',
            $showHints ? 'Set APPOINTMENTS_RECEPTIONIST_ENABLED=1 and RECEPTIONIST_SERVICE_KEY once Receptionist is live.' : null,
            detail: 'Appointments already accepts bookings from a Receptionist service key and attributes them correctly. '
                . 'The panel that reports its contribution stays empty until Receptionist answers.',
            owns: ['Calls, recordings, transcripts and voice conversations'],
        );
    }

    /** @return array<string, mixed> */
    private static function connect(bool $showHints): array
    {
        $client = new ConnectClient();

        return self::card(
            'connect',
            'Aicountly Connect',
            $client->configured() ? 'connected' : 'coming_soon',
            'Video and chat rooms for online appointments.',
            $client->configured() ? null : ($showHints ? 'Set APPOINTMENTS_CONNECT_ENABLED=1 and CONNECT_SERVICE_KEY once Connect is live.' : null),
            detail: $client->configured()
                ? null
                : 'In-person, phone and external-video appointments are unaffected. An appointment booked as '
                    . 'Aicountly Connect falls back to phone until Connect is available.',
            owns: ['Meeting rooms, join links and anything that happens inside them'],
        );
    }

    /** @return array<string, mixed> */
    private static function pay(bool $showHints): array
    {
        $client = new PayClient();

        if ($client->configured()) {
            return self::card(
                'pay',
                'Aicountly Pay',
                'connected',
                'Deposits and prepayments. Appointments sets the policy; Pay collects the money.',
                null,
                owns: ['Payments, refunds, gateways, settlement and payment status'],
            );
        }

        return self::card(
            'pay',
            'Aicountly Pay',
            'coming_soon',
            'Deposits and prepayments. Appointments sets the policy; Pay collects the money.',
            $showHints ? 'Set APPOINTMENTS_PAY_ENABLED=1 and PAY_SERVICE_KEY once Pay is live.' : null,
            detail: $client->unavailableMessage()
                . ' Services that require a deposit cannot be booked online until it is, because confirming one '
                . 'would imply a payment nothing collected.',
            owns: ['Payments, refunds, gateways, settlement and payment status'],
        );
    }

    /** @return array<string, mixed> */
    private static function billing(bool $showHints): array
    {
        $client = new BillingClient();

        return self::card(
            'billing',
            'Aicountly Billing',
            $client->configured() ? 'connected' : 'setup_required',
            'Commercial invoices for completed appointments, raised through Billing.',
            $client->configured() ? null : ($showHints ? 'Set APPOINTMENTS_BILLING_ENABLED=1 and BILLING_SERVICE_KEY.' : null),
            owns: ['Invoices, tax, numbering and billing documents'],
        );
    }

    /** @return array<string, mixed> */
    private static function messaging(bool $showHints): array
    {
        $client = new MessagingClient();

        return self::card(
            'messaging',
            'Messaging — WhatsApp, SMS and email',
            $client->configured() ? 'connected' : 'setup_required',
            'Delivers confirmations and reminders. Appointments owns the rules and the timers.',
            $client->configured() ? null : ($showHints ? 'Set APPOINTMENTS_MESSAGING_ENABLED=1 and MESSAGING_SERVICE_KEY.' : null),
            detail: $client->configured() ? null : $client->unavailableMessage()
                . ' Reminders are still computed and scheduled, and are marked "not sent" rather than shown as delivered.',
            owns: ['Provider relationships, templates, sender identity and delivery receipts'],
        );
    }

    /** @return array<string, mixed> */
    private static function ai(\Aicountly\Api\Context $ctx, bool $showHints): array
    {
        $status = ConsoleCredentials::status();
        $companyEnabled = Settings::featureEnabled($ctx, 'AI');

        if ($status['available'] && $companyEnabled) {
            return self::card(
                'ai',
                'Appointment intelligence',
                'connected',
                'Appointments\' own insight engine. Prompts and rules live in this product; the provider key lives in Console.',
                null,
                detail: 'Model: ' . (string) $status['model'] . '. Every figure on every dashboard is this product\'s '
                    . 'own arithmetic — the model only rephrases the wording, and each insight says which it was.',
                owns: ['LLM provider keys and provider governance (Aicountly Console)'],
            );
        }

        if ($status['available'] && !$companyEnabled) {
            return self::card(
                'ai',
                'Appointment intelligence',
                'available',
                'Appointments\' own insight engine.',
                null,
                detail: 'Turned off for this company in Settings. Dashboards show rule-based insights instead.',
            );
        }

        return self::card(
            'ai',
            'Appointment intelligence',
            'setup_required',
            'Appointments\' own insight engine. Prompts and rules live in this product; the provider key lives in Console.',
            $showHints ? (string) $status['admin_hint'] : null,
            detail: 'Dashboards still show insights. They are produced by this product\'s rules and are labelled '
                . '"rule-based" rather than AI.',
            owns: ['LLM provider keys and provider governance (Aicountly Console)'],
        );
    }

    /** @return array<string, mixed> */
    private static function websiteWidget(\Aicountly\Api\Context $ctx): array
    {
        $enabled = Settings::featureEnabled($ctx, 'PUBLIC_BOOKING');

        return self::card(
            'website_widget',
            'Website widget and booking API',
            $enabled ? 'connected' : 'setup_required',
            'Public booking pages and the API other systems book through.',
            null,
            detail: $enabled
                ? 'Published booking pages are live. Public endpoints are rate limited and revalidate every slot before confirming.'
                : (Features::explain('PUBLIC_BOOKING') ?? 'Public booking is turned off for this company.'),
        );
    }

    /**
     * @param list<string> $owns
     * @return array<string, mixed>
     */
    private static function card(
        string $key,
        string $name,
        string $status,
        string $summary,
        ?string $adminHint,
        bool $required = false,
        ?string $detail = null,
        array $owns = [],
    ): array {
        return [
            'key'        => $key,
            'name'       => $name,
            'status'     => $status,
            'summary'    => $summary,
            'detail'     => $detail,
            'admin_hint' => $adminHint,
            'required'   => $required,
            // What the other product owns, so this screen doubles as the data
            // ownership map somebody can actually read.
            'owns'       => $owns,
        ];
    }
}
