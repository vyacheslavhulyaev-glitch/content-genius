<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    public static function proxyConfigurations(): array
    {
        return ['local default' => [null, 'http'], 'configured proxy' => ['*', 'https']];
    }

    #[DataProvider('proxyConfigurations')]
    public function test_forwarded_https_is_used_only_when_proxy_trust_is_configured(?string $proxies, string $scheme): void
    {
        config(['trustedproxy.proxies' => $proxies]);
        Route::get('/proxy-verification', fn (Request $request) => response()->json([
            'scheme' => $request->getScheme(), 'host' => $request->getHost(),
        ]));

        $this->getJson('http://localhost/proxy-verification', [
            'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'untrusted.example',
        ])->assertOk()->assertExactJson(['scheme' => $scheme, 'host' => 'localhost']);
    }
}
