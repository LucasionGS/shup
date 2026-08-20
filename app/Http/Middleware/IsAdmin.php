<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IsAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Null-safe: this middleware is currently always paired with `auth`, but
        // on its own it would fatal rather than deny.
        $user = $request->user();

        if (!$user) {
            return redirect(route('login'));
        }

        if (!$user->isAdmin()) {
            return redirect(route('dashboard'));
        }

        return $next($request);
    }
}
