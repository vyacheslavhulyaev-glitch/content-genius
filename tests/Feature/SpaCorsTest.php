<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SpaCorsTest extends TestCase
{
    #[DataProvider('authenticationPaths')]
    public function test_authentication_preflights_allow_frontend_credentials(string $path, string $method): void
    {
        $response = $this->options('http://localhost:8000'.$path, [], [
            'Origin' => 'http://localhost:5173',
            'Access-Control-Request-Method' => $method,
            'Access-Control-Request-Headers' => 'content-type,x-xsrf-token',
        ]);

        $response->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');

        $this->assertContains($method, explode(', ', $response->headers->get('Access-Control-Allow-Methods')));
        $headers = explode(', ', strtolower($response->headers->get('Access-Control-Allow-Headers')));
        $this->assertContains('content-type', $headers);
        $this->assertContains('x-xsrf-token', $headers);
    }

    #[DataProvider('authenticationPaths')]
    public function test_actual_authentication_responses_include_cors_headers(string $path, string $method): void
    {
        $response = $this->json($method, 'http://localhost:8000'.$path, [], [
            'Origin' => 'http://localhost:5173',
        ]);

        $response->assertStatus(match ($path) {
            '/sanctum/csrf-cookie' => 204,
            '/login' => 422,
            default => 401,
        })
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_csrf_initialization_sets_cookies_usable_on_local_http(): void
    {
        $response = $this->getJson('http://localhost:8000/sanctum/csrf-cookie', [
            'Origin' => 'http://localhost:5173',
        ])->assertNoContent();

        $session = $response->getCookie(config('session.cookie'), decrypt: false);
        $csrf = $response->getCookie('XSRF-TOKEN', decrypt: false);

        $this->assertNotNull($session);
        $this->assertNotNull($csrf);
        $this->assertTrue($session->isHttpOnly());
        $this->assertFalse($csrf->isHttpOnly());

        foreach ([$session, $csrf] as $cookie) {
            $this->assertFalse($cookie->isSecure());
            $this->assertContains($cookie->getDomain(), [null, '', 'localhost']);
            $this->assertSame('/', $cookie->getPath());
            $this->assertSame('lax', $cookie->getSameSite());
        }
    }

    public function test_an_unapproved_origin_is_not_granted_browser_access(): void
    {
        $response = $this->options('http://localhost:8000/login', [], [
            'Origin' => 'http://untrusted.example',
            'Access-Control-Request-Method' => 'POST',
        ]);

        // The installed CORS package always emits its single allowed origin.
        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }

    public function test_unrelated_routes_do_not_receive_cors_permissions(): void
    {
        $this->getJson('http://localhost:8000/api/health', ['Origin' => 'http://localhost:5173'])
            ->assertOk()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public static function authenticationPaths(): array
    {
        return [
            'CSRF cookie' => ['/sanctum/csrf-cookie', 'GET'],
            'login' => ['/login', 'POST'],
            'logout' => ['/logout', 'POST'],
            'current user' => ['/api/user', 'GET'],
            'create content' => ['/api/contents', 'POST'],
            'list contents' => ['/api/contents', 'GET'],
            'generate content' => ['/api/contents/123/generate', 'POST'],
        ];
    }
}
