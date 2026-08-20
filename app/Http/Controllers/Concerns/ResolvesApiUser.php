<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Resolves the acting user from either the session or an API token.
 *
 * This replaces four near-identical copies of the lookup that disagreed about
 * whether the Authorization header may carry a "Bearer " prefix: /d accepted it
 * while /f, /p and /s compared against the whole raw header and therefore
 * rejected any standards-compliant client.
 */
trait ResolvesApiUser
{
    protected function resolveApiUser(Request $request): ?User
    {
        if ($sessionUser = $request->user()) {
            return $sessionUser;
        }

        $user = User::findByApiToken($request->header('Authorization'));

        if ($user) {
            $this->touchApiTokenUsage($user);
        }

        return $user;
    }

    /**
     * Records when a token was last used, at most once an hour per user so a
     * burst of uploads does not turn into a write per request.
     */
    private function touchApiTokenUsage(User $user): void
    {
        if ($user->api_token_last_used_at && $user->api_token_last_used_at->gt(now()->subHour())) {
            return;
        }

        $user->forceFill(['api_token_last_used_at' => now()])->saveQuietly();
    }
}
