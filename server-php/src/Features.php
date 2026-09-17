<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Which integrations this deployment actually has.
 *
 * THE POINT OF THIS CLASS is to make it impossible to ship a placeholder that
 * looks connected. Pay, Receptionist and Connect are being built elsewhere in
 * the fleet and are not live yet. Appointments has a real client for each, a
 * real contract, and a flag that is OFF until the other side answers — so the
 * screen says "Aicountly Pay integration is not enabled yet" rather than
 * drawing a deposits panel full of numbers nobody collected.
 *
 * A flag is on only when it has been turned on AND the thing it gates is
 * configured. `APPOINTMENTS_PAY_ENABLED=1` with no `PAY_SERVICE_KEY` is an
 * administrator who meant to finish and did not, and reading it as "on" would
 * put the failure in front of a client mid-booking instead of in front of the
 * administrator in Integrations.
 *
 * Calendar is deliberately absent from the flag list. It is not optional: an
 * appointment product with no calendar has nothing to book against, and a flag
 * that could turn it off would only ever be used to hide a misconfiguration.
 */
final class Features
{
    /**
     * Flag name => the env keys that must be present for it to count as configured.
     *
     * An empty requirement list means the flag alone decides — the feature is
     * ours and needs nothing from another product.
     *
     * @var array<string, list<string>>
     */
    private const REQUIREMENTS = [
        'AI'             => ['CONSOLE_API_URL', 'CONSOLE_SERVICE_KEY'],
        'WAITLIST'       => [],
        'PUBLIC_BOOKING' => [],
        'PAY'            => ['PAY_SERVICE_KEY'],
        'RECEPTIONIST'   => ['RECEPTIONIST_SERVICE_KEY'],
        'CONNECT'        => ['CONNECT_SERVICE_KEY'],
        'CRM'            => ['CRM_SERVICE_KEY'],
        'CONTACTS'       => [],
        'BILLING'        => ['BILLING_SERVICE_KEY'],
        'MESSAGING'      => ['MESSAGING_SERVICE_KEY'],
    ];

    /**
     * Flags that are on unless a deployment turns them off.
     *
     * These gate features Appointments owns outright, so the only reason to
     * disable one is that a particular business does not want it.
     *
     * @var list<string>
     */
    private const ON_BY_DEFAULT = ['WAITLIST', 'PUBLIC_BOOKING', 'CONTACTS'];

    /** @var array<string, bool>|null */
    private static ?array $memo = null;

    public static function enabled(string $flag): bool
    {
        return self::all()[strtoupper($flag)] ?? false;
    }

    /**
     * Every flag and its state, for the Integrations screen and /api/health.
     *
     * @return array<string, bool>
     */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $out = [];
        foreach (self::REQUIREMENTS as $flag => $required) {
            $out[$flag] = self::resolve($flag, $required);
        }

        return self::$memo = $out;
    }

    /**
     * Why a flag is off, in words an administrator can act on.
     *
     * Names the env key that is missing, because that is the one thing the
     * person reading the Integrations screen needs and cannot guess. It never
     * names a value.
     */
    public static function explain(string $flag): ?string
    {
        $flag = strtoupper($flag);
        if (self::enabled($flag)) {
            return null;
        }

        $required = self::REQUIREMENTS[$flag] ?? null;
        if ($required === null) {
            return 'Unknown feature.';
        }

        $switch = 'APPOINTMENTS_' . $flag . '_ENABLED';
        if (!self::switchedOn($flag)) {
            return 'Turned off for this deployment. Set ' . $switch . '=1 in the server environment to enable it.';
        }

        $missing = array_values(array_filter($required, static fn (string $key) => Env::get($key) === ''));
        if ($missing !== []) {
            return $switch . ' is set, but ' . implode(' and ', $missing)
                . ' ' . (count($missing) === 1 ? 'is' : 'are') . ' missing from the server environment.';
        }

        return 'Not available.';
    }

    /** Test seam. CLI only, and it resets rather than accumulating. */
    public static function overrideForTesting(?array $flags): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        if ($flags === null) {
            self::$memo = null;

            return;
        }
        $out = [];
        foreach (array_keys(self::REQUIREMENTS) as $flag) {
            $out[$flag] = (bool) ($flags[$flag] ?? false);
        }
        self::$memo = $out;
    }

    /** @param list<string> $required */
    private static function resolve(string $flag, array $required): bool
    {
        if (!self::switchedOn($flag)) {
            return false;
        }

        foreach ($required as $key) {
            if (Env::get($key) === '') {
                return false;
            }
        }

        return true;
    }

    private static function switchedOn(string $flag): bool
    {
        $raw = strtolower(trim(Env::get('APPOINTMENTS_' . $flag . '_ENABLED')));

        if ($raw === '') {
            return in_array($flag, self::ON_BY_DEFAULT, true);
        }

        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }
}
