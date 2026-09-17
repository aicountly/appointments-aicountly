<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Who is calling.
 *
 * Three ways in, and the third is what makes a booking product different from
 * the rest of the fleet:
 *
 *  1. A human — `Authorization: Bearer <ses_key>`, validated at my.aicountly.com.
 *     The ses_key is kept so this product can call Contacts, CRM and Manage AS
 *     THAT USER, which is what makes their permissions apply over there instead
 *     of Appointments re-implementing them.
 *
 *  2. A trusted product backend — `X-Service-Key`, plus `X-Actor-Uuid` naming
 *     the human it is acting for. Receptionist books this way: a caller on the
 *     phone has no session here, and the person who answered the phone is not
 *     the staff member whose diary is being filled.
 *
 *  3. Nobody — the public booking pages. A client following a booking link has
 *     no AICOUNTLY account and never will. Those routes resolve no Auth at all;
 *     they carry their own rate limits, their own token and their own
 *     revalidation, and they live behind Public* controllers so it is obvious
 *     from the route table which ones they are.
 *
 * `sourceApp` is decided HERE and never read from a header: a service key
 * resolves to its product, a human session is always this product. A booking
 * body that claims `booking_source: RECEPTIONIST` while arriving on a browser
 * session is a caller trying to launder the origin of a booking, and the source
 * it gets is the one proven by its credential.
 */
final class Auth
{
    private function __construct(
        public readonly string $uuid,
        public readonly string $kind,      // 'user' | 'service' | 'public'
        public readonly string $sourceApp,
        private readonly string $sesKey,
        private readonly ?array $session,
    ) {
    }

    /**
     * The caller a CLI test has stood in as.
     *
     * The same seam as ResponseSent, for the same reason: a controller resolves
     * its caller from HTTP headers, which a test has none of. Rather than let
     * tests reach past the controllers into the services — where the permission
     * checks are not — they adopt an identity and call the real endpoint.
     *
     * CLI ONLY. Under a web SAPI this is ignored outright, so it cannot become
     * an authentication bypass however it is called.
     */
    private static ?self $adopted = null;

    public static function adopt(?self $auth): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        self::$adopted = $auth;
    }

    /** Build an identity for a test. CLI only, for the same reason as adopt(). */
    public static function forTesting(string $uuid, string $kind = 'user', string $sourceApp = 'appointments', array $session = []): self
    {
        if (PHP_SAPI !== 'cli') {
            throw new \LogicException('Auth::forTesting is CLI only.');
        }

        return new self($uuid, $kind, $sourceApp, $kind === 'user' ? 'test-ses-key' : '', $session);
    }

    /**
     * The identity a public booking page acts under.
     *
     * Not a credential and not a session: a client following a booking link has
     * no AICOUNTLY account. This exists so the booking domain has ONE notion of
     * "who is doing this" rather than a nullable Auth threaded through every
     * method, and so a public caller is visibly distinct in the audit trail.
     *
     * It holds no ses_key, so it cannot read Contacts or CRM as anybody, and
     * `kind` is 'public', which BookingService uses to decide the booking
     * source and whether auto-confirmation applies. `accessType()` is null, so
     * the owner shortcut in Permissions can never fire for it.
     *
     * The protections that actually matter on those routes — rate limiting,
     * slug resolution, revalidation, idempotency — live in
     * Controllers/PublicBookingController.php.
     */
    public static function forPublic(string $fingerprint): self
    {
        return new self(
            'public:' . substr($fingerprint, 0, 32),
            'public',
            'booking_page',
            '',
            null,
        );
    }

    /** Resolve the caller, or answer 401 and stop. */
    public static function require(): self
    {
        if (PHP_SAPI === 'cli' && self::$adopted !== null) {
            return self::$adopted;
        }

        $resolved = self::resolve();
        if ($resolved === null) {
            Http::unauthorized();
        }

        return $resolved;
    }

    public static function resolve(): ?self
    {
        $serviceKey = Http::header('X-Service-Key');
        if ($serviceKey !== '') {
            $app = ServiceKeys::resolveApp($serviceKey);
            if ($app === null) {
                return null;
            }
            // Proven by the key, not claimed in a header. Recording it is what
            // stops us calling that product back inside its own request.
            CrossServiceCallContext::adoptAuthenticatedOrigin($app);
            $actor = Http::header('X-Actor-Uuid');

            return new self(
                $actor !== '' ? $actor : 'service:' . $app,
                'service',
                $app,
                '',
                null,
            );
        }

        $sesKey = self::bearer();
        if ($sesKey === '') {
            return null;
        }

        $session = Portal::validateSesKey($sesKey);
        if ($session === null) {
            return null;
        }

        return new self(
            (string) ($session['uuid_aictly'] ?? $session['uuid'] ?? ''),
            'user',
            Env::get('APP_PRODUCT_KEY', 'appointments'),
            $sesKey,
            $session,
        );
    }

    public function isService(): bool
    {
        return $this->kind === 'service';
    }

    /**
     * The session key, for calling Contacts / CRM / Manage as this user.
     *
     * Empty for a service caller, which is correct: a service acts with its own
     * key over there, not with a borrowed human session.
     */
    public function sesKey(): string
    {
        return $this->sesKey;
    }

    /** Stable per-session identifier for memo keys. Never the key itself, which must not reach a log or a cache key. */
    public function fingerprint(): string
    {
        return substr(hash('sha256', $this->kind . '|' . $this->uuid . '|' . $this->sesKey), 0, 32);
    }

    /** Portal access type for the company when the portal reported one: 1 = owner. */
    public function accessType(): ?int
    {
        return isset($this->session['acs_type']) ? (int) $this->session['acs_type'] : null;
    }

    public function displayName(): string
    {
        foreach (['name', 'full_name', 'user_name', 'email'] as $field) {
            $value = $this->session[$field] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $this->uuid;
    }

    /**
     * The booking source this caller is entitled to claim.
     *
     * Decided by the credential, never by the request body. A dashboard that
     * attributes bookings to Receptionist is only worth reading if a browser
     * cannot write that label onto its own bookings.
     */
    public function provenBookingSource(): string
    {
        if ($this->kind === 'public') {
            return 'ONLINE_BOOKING';
        }
        if (!$this->isService()) {
            return 'STAFF_BOOKING';
        }

        return match ($this->sourceApp) {
            'receptionist' => 'RECEPTIONIST',
            'crm'          => 'CRM',
            'sales'        => 'SALES',
            'pos'          => 'POS',
            default        => 'API_INTEGRATION',
        };
    }

    private static function bearer(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!is_string($header) || $header === '') {
            if (function_exists('apache_request_headers')) {
                foreach ((array) apache_request_headers() as $name => $value) {
                    if (strcasecmp((string) $name, 'Authorization') === 0) {
                        $header = (string) $value;
                        break;
                    }
                }
            }
        }
        if (!is_string($header) || preg_match('/Bearer\s+(.+)/i', $header, $m) !== 1) {
            return '';
        }

        return trim($m[1]);
    }
}
