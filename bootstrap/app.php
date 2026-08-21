<?php

use App\Http\Middleware\IsAdmin;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Conditional CSRF exemption: token clients are exempt, session-cookie
        // requests are not. See the middleware for why.
        // replaceInGroup, not replace: CSRF lives in the web group, and
        // replace() only rewrites the global middleware stack.
        // The search argument must track the framework's current class name --
        // it is an exact string match that no-ops silently on a miss, which
        // would leave the stock CSRF middleware in place and 419 every CLI and
        // ShareX upload. CsrfExemptionTest fails if that ever happens.
        // Not preventRequestForgery(except:), which routes through the static
        // $neverVerify list and exempts those paths unconditionally -- that is
        // the blanket exemption this middleware exists to replace.
        $middleware->replaceInGroup(
            'web',
            \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
            PreventRequestForgery::class
        );

        $middleware->append(SecurityHeaders::class);

        // TLS terminates at the reverse proxy, so the forwarded headers decide
        // the scheme and client IP that rate limiting and URL generation see.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*'),
            headers: Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
                | Illuminate\Http\Request::HEADER_X_FORWARDED_HOST
                | Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
                | Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO
        );

        $middleware->appendToGroup('isAdmin', IsAdmin::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
