<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth;
use Aicountly\Api\Clients\ManageClient;
use Aicountly\Api\Http;

/**
 * The company switcher.
 *
 * Read-through to Manage and NOT company-scoped: this is what the caller uses
 * to CHOOSE a company, so requiring one first would be a chicken and egg. It
 * is still authenticated, and Manage answers as the signed-in user — so the
 * list is the companies that session may actually open, not a list this product
 * decided.
 *
 * Nothing here is stored. A company renamed in Manage is renamed here on the
 * next page load.
 */
final class ManageController extends Controller
{
    public static function companies(): void
    {
        $auth = Auth::require();

        if ($auth->isService()) {
            // A service key belongs to a product, not to a person, and has no
            // company list of its own to offer.
            Http::forbidden('The company switcher is for signed-in users.');
        }

        $result = (new ManageClient())->withSession($auth->sesKey())->companies([
            'limit' => 200,
        ]);

        if (!$result['ok']) {
            Http::error(503, 'manage_unavailable', 'Aicountly Manage is temporarily unavailable, so your companies cannot be listed.', [
                'retryable' => true,
            ]);
        }

        // Passed through with the shape Manage used, so this product does not
        // become a second opinion on what a company looks like.
        Http::data($result['body']['data'] ?? $result['body'] ?? []);
    }

    public static function companyInfo(): void
    {
        $auth = Auth::require();
        $cmpId = Http::intParam('cmp_id', 0) ?? 0;

        if ($cmpId <= 0) {
            Http::validationFailed('cmp_id is required.');
        }

        $result = (new ManageClient())->withSession($auth->sesKey())->companyInfo($cmpId);

        if (!$result['ok']) {
            Http::error(503, 'manage_unavailable', 'Aicountly Manage is temporarily unavailable.', ['retryable' => true]);
        }

        Http::data($result['body']['data'] ?? $result['body'] ?? []);
    }
}
