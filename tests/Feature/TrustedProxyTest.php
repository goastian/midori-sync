<?php

namespace Tests\Feature;

use App\Http\Middleware\ValidateSyncToken;
use App\Models\SyncSession;
use App\Models\User;
use App\Services\SyncAuthService;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'trustedproxy.proxies' => ['10.10.0.2', 'fd00:1234::/64'],
            'services.sync.unauth_rate_limit' => 2,
        ]);

        Route::get('/__trusted_proxy/ip', fn (Request $request) => [
            'ip' => $request->ip(),
            'scheme' => $request->getScheme(),
            'port' => $request->getPort(),
            'host' => $request->getHost(),
            'base_url' => $request->getBaseUrl(),
        ]);
        Route::post('/__trusted_proxy/unauth', fn () => response()->noContent())
            ->middleware('throttle:sync-unauth');
        Route::get('/__trusted_proxy/protected', fn (Request $request) => ['user_id' => $request->user()->id])
            ->middleware(ValidateSyncToken::class);

        Log::shouldReceive('channel')->with('security')->andReturnSelf();
        Log::shouldReceive('warning')->andReturnNull();
    }

    #[DataProvider('clientAddresses')]
    public function test_only_configured_proxies_can_forward_a_client_ip(string $peer, string $forwarded, string $expected): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => $peer])
            ->getJson('/__trusted_proxy/ip', ['X-Forwarded-For' => $forwarded])
            ->assertOk()->assertJsonPath('ip', $expected);
    }

    public static function clientAddresses(): array
    {
        return [
            'trusted IPv4' => ['10.10.0.2', '198.51.100.20', '198.51.100.20'],
            'trusted IPv6 CIDR' => ['fd00:1234::2', '2001:db8::20', '2001:db8::20'],
            'untrusted peer' => ['203.0.113.4', '198.51.100.20', '203.0.113.4'],
            'untrusted IPv6 peer' => ['2001:db8::4', '198.51.100.20', '2001:db8::4'],
            'untrusted prefix of chain' => ['10.10.0.2', '192.0.2.99, 198.51.100.20', '198.51.100.20'],
        ];
    }

    public function test_unconfigured_proxies_cannot_enable_forwarding_through_a_host_name(): void
    {
        config(['trustedproxy.proxies' => []]);

        foreach (['sync.example.test', 'attacker.on-forge.com', 'attacker.on-vapor.com'] as $host) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.4'])
                ->getJson('http://'.$host.'/__trusted_proxy/ip', ['X-Forwarded-For' => '198.51.100.20'])
                ->assertOk()->assertJsonPath('ip', '203.0.113.4');
        }
    }

    public function test_only_the_expected_forwarding_headers_are_trusted(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.2'])
            ->getJson('http://sync.example.test/__trusted_proxy/ip', [
                'X-Forwarded-For' => '198.51.100.20',
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Port' => '443',
                'X-Forwarded-Host' => 'attacker.example.test',
                'X-Forwarded-Prefix' => '/attacker',
                'Forwarded' => 'for=192.0.2.99;proto=http;host=attacker.example.test',
                'CF-Connecting-IP' => '192.0.2.98',
                'X-Real-IP' => '192.0.2.97',
            ])->assertOk()->assertExactJson([
                'ip' => '198.51.100.20',
                'scheme' => 'https',
                'port' => 443,
                'host' => 'sync.example.test',
                'base_url' => '',
            ]);
    }

    public function test_untrusted_peers_cannot_forward_scheme_or_port(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.4'])
            ->getJson('/__trusted_proxy/ip', [
                'X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443',
            ])->assertOk()->assertJsonPath('scheme', 'http')->assertJsonPath('port', 80);
    }

    public function test_clients_behind_one_proxy_have_independent_unauthenticated_limits(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.2']);

        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/__trusted_proxy/unauth', [], ['X-Forwarded-For' => '198.51.100.20'])->assertNoContent();
        }
        $this->postJson('/__trusted_proxy/unauth', [], ['X-Forwarded-For' => '198.51.100.20'])->assertStatus(429);
        $this->postJson('/__trusted_proxy/unauth', [], ['X-Forwarded-For' => '198.51.100.21'])->assertNoContent();
    }

    public function test_untrusted_clients_cannot_evade_limits_by_changing_forwarding_headers(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.4']);

        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/__trusted_proxy/unauth', [], ['X-Forwarded-For' => '198.51.100.'.($i + 20)])->assertNoContent();
        }
        $this->postJson('/__trusted_proxy/unauth', [], ['X-Forwarded-For' => '198.51.100.99'])->assertStatus(429);
    }

    public function test_failed_authentication_does_not_block_a_valid_client_behind_the_same_proxy(): void
    {
        $user = new User;
        $user->id = 42;
        $session = (new SyncSession)->setRelation('user', $user);
        $this->mock(SyncAuthService::class, function ($mock) use ($session) {
            $mock->shouldReceive('validateToken')->with('invalid')->twice()->andReturnNull();
            $mock->shouldReceive('validateToken')->with('valid')->once()->andReturn($session);
        });

        $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.2']);
        for ($i = 0; $i < 2; $i++) {
            $this->getJson('/__trusted_proxy/protected', [
                'Authorization' => 'Bearer invalid', 'X-Forwarded-For' => '198.51.100.20',
            ])->assertUnauthorized();
        }
        $this->getJson('/__trusted_proxy/protected', [
            'Authorization' => 'Bearer invalid', 'X-Forwarded-For' => '198.51.100.20',
        ])->assertStatus(429)->assertHeader('Retry-After');
        $this->getJson('/__trusted_proxy/protected', [
            'Authorization' => 'Bearer valid', 'X-Forwarded-For' => '198.51.100.21',
        ])->assertOk()->assertJsonPath('user_id', 42);

        Log::shouldHaveReceived('warning')->with(SecurityLog::EVENT_TOKEN_INVALID,
            \Mockery::on(fn (array $context): bool => $context['ip'] === '198.51.100.20'))->twice();
    }

    public function test_audit_events_ignore_spoofed_ips_from_untrusted_peers(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.4'])
            ->getJson('/__trusted_proxy/protected', ['X-Forwarded-For' => '198.51.100.20'])->assertUnauthorized();

        Log::shouldHaveReceived('warning')->with(SecurityLog::EVENT_TOKEN_INVALID,
            \Mockery::on(fn (array $context): bool => $context['ip'] === '203.0.113.4'))->once();
    }
}
