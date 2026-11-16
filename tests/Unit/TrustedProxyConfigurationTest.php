<?php

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TrustedProxyConfigurationTest extends TestCase
{
    public function test_empty_configuration_trusts_no_proxies(): void
    {
        $this->assertSame([], $this->loadConfiguration('')['proxies']);
    }

    public function test_configuration_accepts_explicit_ipv4_ipv6_and_cidrs(): void
    {
        $this->assertSame(['10.10.0.2', '127.0.0.1/32', 'fd00:1234::/64', '::1'],
            $this->loadConfiguration(' 10.10.0.2,127.0.0.1/32, fd00:1234::/64,::1, ')['proxies']);
    }

    #[DataProvider('invalidProxies')]
    public function test_configuration_rejects_implicit_trust_and_invalid_networks(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->loadConfiguration($value);
    }

    public static function invalidProxies(): array
    {
        return array_map(fn (string $value): array => [$value], [
            '*', '**', 'REMOTE_ADDR', 'private_ranges', '0.0.0.0/0', '::/0',
            '10.10.0.2/33', '::1/129', 'proxy.example.test', '10.10.0.2/32/1',
        ]);
    }

    private function loadConfiguration(string $value): array
    {
        $name = 'SYNC_TRUSTED_PROXIES';
        $originalEnv = $_ENV[$name] ?? null;
        $originalServer = $_SERVER[$name] ?? null;
        $originalProcess = getenv($name);
        $_ENV[$name] = $_SERVER[$name] = $value;
        putenv($name.'='.$value);

        try {
            return require __DIR__.'/../../config/trustedproxy.php';
        } finally {
            unset($_ENV[$name], $_SERVER[$name]);
            if ($originalEnv !== null) {
                $_ENV[$name] = $originalEnv;
            }
            if ($originalServer !== null) {
                $_SERVER[$name] = $originalServer;
            }
            putenv($originalProcess === false ? $name : $name.'='.$originalProcess);
        }
    }
}
