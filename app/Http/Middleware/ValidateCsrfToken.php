<?php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken as Middleware;

/**
 * The upload endpoints are shared by two very different clients: the CLI and
 * ShareX, which authenticate with an API token and cannot hold a CSRF token,
 * and the web UI, which authenticates with a session cookie.
 *
 * They were previously exempted from CSRF outright. Because the same handlers
 * also accept the session cookie, that let any third-party page drive a
 * logged-in user's browser into uploading to their account.
 *
 * The exemption now applies only to requests that actually present an API
 * token. Session-authenticated requests keep full CSRF protection.
 */
class ValidateCsrfToken extends Middleware
{
    /**
     * Endpoints token-based clients need to reach.
     *
     * @var array<int, string>
     */
    protected $except = [
        '/s',                // Short URL creation
        '/f',                // File upload
        '/p',                // Paste creation
        '/d',                // Directory creation
        '/d/*/-/upload',     // Directory file upload
        '/d/*/-/folders',    // Directory folder creation
    ];

    protected function inExceptArray($request)
    {
        if (!parent::inExceptArray($request)) {
            return false;
        }

        return $this->hasApiToken($request);
    }

    /**
     * Whether the request carries a usable API token, as opposed to relying on
     * the ambient session cookie.
     */
    private function hasApiToken($request): bool
    {
        $header = $request->header('Authorization');

        if (!$header) {
            return false;
        }

        return User::findByApiToken($header) !== null;
    }
}
