<?php

namespace Tests\Feature;

use App\Http\Middleware\PreventRequestForgery;
use App\Models\User;
use Illuminate\Foundation\Configuration\Middleware as MiddlewareConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Laravel skips CSRF validation whenever it detects a unit-test run, so a
 * normal feature test cannot tell an exempt route from a protected one. These
 * tests call the middleware's decision directly instead.
 */
class CsrfExemptionTest extends TestCase
{
    use RefreshDatabase;

    private function shouldSkipCsrf(Request $request): bool
    {
        $middleware = new PreventRequestForgery(app(), app('encrypter'));

        $method = new \ReflectionMethod($middleware, 'inExceptArray');
        $method->setAccessible(true);

        return $method->invoke($middleware, $request);
    }

    public function test_the_registered_csrf_middleware_is_the_conditional_one(): void
    {
        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
        $property = new \ReflectionProperty($kernel, 'middlewareGroups');
        $property->setAccessible(true);

        $web = $property->getValue($kernel)['web'] ?? [];

        $this->assertContains(
            PreventRequestForgery::class,
            $web,
            'The conditional CSRF middleware must actually be registered. bootstrap/app.php swaps it in with replaceInGroup(), which matches an exact class string and does nothing at all when it misses.'
        );

        $this->assertNotContains(
            \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
            $web,
            'The stock CSRF middleware is still in the web group, so the swap did not happen and every CLI and ShareX upload will fail with a token mismatch.'
        );
    }

    public function test_the_framework_class_we_replace_still_has_that_name(): void
    {
        // replaceInGroup() searches the default web group for one exact class
        // string. The framework has renamed this middleware twice already
        // (VerifyCsrfToken -> ValidateCsrfToken in 11, -> PreventRequestForgery
        // in 13) and both times the search would have silently found nothing.
        // Asserting against the framework's own defaults catches the next
        // rename at upgrade time rather than in production.
        $default = (new MiddlewareConfig)->getMiddlewareGroups()['web'];

        $this->assertContains(
            \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
            $default,
            'The framework renamed its CSRF middleware again. Update the search argument in bootstrap/app.php and the parent of App\Http\Middleware\PreventRequestForgery to match.'
        );
    }

    public function test_upload_route_is_exempt_only_with_a_valid_token(): void
    {
        $user = User::factory()->create();
        $token = $user->issueApiToken();
        $user->save();

        $withToken = Request::create('/f', 'POST');
        $withToken->headers->set('Authorization', $token);
        $this->assertTrue($this->shouldSkipCsrf($withToken));

        $withBearer = Request::create('/f', 'POST');
        $withBearer->headers->set('Authorization', "Bearer $token");
        $this->assertTrue($this->shouldSkipCsrf($withBearer));
    }

    public function test_session_only_request_to_upload_route_is_not_exempt(): void
    {
        // This is the CSRF hole: the endpoint also accepts the session cookie,
        // so a blanket exemption let any site drive a logged-in user's browser.
        $request = Request::create('/f', 'POST');

        $this->assertFalse($this->shouldSkipCsrf($request));
    }

    public function test_invalid_token_does_not_earn_an_exemption(): void
    {
        $request = Request::create('/f', 'POST');
        $request->headers->set('Authorization', 'totally-made-up');

        $this->assertFalse($this->shouldSkipCsrf($request));
    }

    public function test_non_upload_routes_are_never_exempt(): void
    {
        $user = User::factory()->create();
        $token = $user->issueApiToken();
        $user->save();

        $request = Request::create('/user/1', 'PUT');
        $request->headers->set('Authorization', $token);

        $this->assertFalse($this->shouldSkipCsrf($request));
    }
}
