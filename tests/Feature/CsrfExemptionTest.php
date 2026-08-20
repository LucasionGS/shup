<?php

namespace Tests\Feature;

use App\Http\Middleware\ValidateCsrfToken;
use App\Models\User;
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
        $middleware = new ValidateCsrfToken(app(), app('encrypter'));

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
            ValidateCsrfToken::class,
            $web,
            'The conditional CSRF middleware must actually be registered; the framework class it replaces is named ValidateCsrfToken in Laravel 11.'
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
