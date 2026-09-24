<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SessionAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);

        $this->withCredentials()->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_login_rotates_the_session_and_authenticates_api_requests(): void
    {
        $user = User::factory()->create(['password' => 'test-password']);
        $initial = $this->getJson('/sanctum/csrf-cookie')->assertNoContent();
        $initialSessionId = $initial->getCookie(config('session.cookie'))->getValue();
        $this->useSessionCookieFrom($initial);

        $login = $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'test-password',
        ])->assertNoContent();

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($initialSessionId, $login->getCookie(config('session.cookie'))->getValue());
        $this->assertDatabaseMissing('sessions', ['id' => $initialSessionId]);
        $this->useSessionCookieFrom($login);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('email', $user->email)
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('remember_token');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_invalid_credentials_return_the_same_generic_error(): void
    {
        $user = User::factory()->create(['password' => 'test-password']);

        foreach ([$user->email, 'unknown@example.com'] as $email) {
            $this->postJson('/login', ['email' => $email, 'password' => 'wrong-password'])
                ->assertUnprocessable()
                ->assertExactJson([
                    'message' => 'The provided credentials are incorrect.',
                    'errors' => ['email' => ['The provided credentials are incorrect.']],
                ]);

            $this->assertGuest('web');
        }
    }

    public function test_login_validates_credentials(): void
    {
        $this->postJson('/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);

        $this->postJson('/login', ['email' => 'invalid-email', 'password' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);

        $this->assertGuest('web');
    }

    public function test_guests_cannot_access_the_user_endpoint_or_logout(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
        $this->postJson('/logout')->assertUnauthorized();
    }

    public function test_logout_invalidates_the_session_and_rotates_the_csrf_token(): void
    {
        $user = User::factory()->create(['password' => 'test-password']);
        $login = $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'test-password',
        ])->assertNoContent();
        $sessionId = $login->getCookie(config('session.cookie'))->getValue();
        $csrfToken = $this->app['session']->token();
        $this->useSessionCookieFrom($login);

        $logout = $this->postJson('/logout')->assertNoContent();

        $this->assertGuest('web');
        $this->assertNotSame($csrfToken, $this->app['session']->token());
        $this->assertNotSame($sessionId, $logout->getCookie(config('session.cookie'))->getValue());
        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
        $this->useSessionCookieFrom($logout);
        $this->getJson('/api/user')->assertUnauthorized();

        $this->useSessionCookieFrom($login);
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_login_attempts_are_throttled(): void
    {
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->postJson('/login', [
                'email' => 'unknown@example.com',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/login', [
            'email' => 'unknown@example.com',
            'password' => 'wrong-password',
        ])->assertTooManyRequests();
    }

    private function useSessionCookieFrom(TestResponse $response): void
    {
        $cookie = $response->getCookie(config('session.cookie'), decrypt: false);

        // Resolve authentication and session state afresh, using only the returned cookie.
        Auth::forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');

        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }
}
