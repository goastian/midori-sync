<?php

namespace Tests\Unit;

use App\Services\Library\PublicPageFetcher;
use App\Services\Library\UrlNormalizer;
use PHPUnit\Framework\TestCase;

class PublicPageFetcherTest extends TestCase
{
    public function test_public_url_validation_rejects_local_and_ambiguous_destinations(): void
    {
        foreach ([
            'http://127.0.0.1/',
            'http://[::1]/',
            'http://169.254.169.254/latest/meta-data',
            'http://100.64.0.1/',
            'http://198.18.0.1/',
            'http://224.0.0.1/',
            'http://[2001:db8::1]/',
            'https://localhost./',
            'https://service.localhost/',
            'https://user:pass@example.com/',
            'https://example.com:8080/',
            'ftp://example.com/file',
            'http://2130706433/',
        ] as $url) {
            $this->assertFalse(UrlNormalizer::isPublicHttpUrl($url), $url);
        }
        $this->assertTrue(UrlNormalizer::isPublicHttpUrl('https://93.184.216.34/page'));
        $this->assertTrue(UrlNormalizer::isPublicHttpUrl('https://example.com/page'));
    }

    public function test_redirects_are_resolved_one_hop_at_a_time(): void
    {
        $fetcher = new class extends PublicPageFetcher
        {
            public array $seen = [];

            protected function request(string $url, string $address, int $maxBytes, int $timeout, string $userAgent): array
            {
                $this->seen[] = [$url, $address];

                return count($this->seen) === 1
                    ? ['status' => 302, 'body' => '', 'location' => '/next', 'content_type' => '']
                    : ['status' => 200, 'body' => 'ok', 'location' => null, 'content_type' => 'text/html'];
            }
        };

        $page = $fetcher->fetch('https://93.184.216.34/start', 1024, 10, 'Test');

        $this->assertSame('https://93.184.216.34/next', $page['url']);
        $this->assertSame('ok', $page['body']);
        $this->assertSame([
            ['https://93.184.216.34/start', '93.184.216.34'],
            ['https://93.184.216.34/next', '93.184.216.34'],
        ], $fetcher->seen);
    }

    public function test_redirect_to_private_host_is_rejected_before_connecting(): void
    {
        $fetcher = new class extends PublicPageFetcher
        {
            public int $requests = 0;

            protected function request(string $url, string $address, int $maxBytes, int $timeout, string $userAgent): array
            {
                $this->requests++;

                return ['status' => 302, 'body' => '', 'location' => 'http://127.0.0.1/private', 'content_type' => ''];
            }
        };

        try {
            $fetcher->fetch('https://93.184.216.34/start', 1024, 10, 'Test');
            $this->fail('The redirect must be blocked');
        } catch (\InvalidArgumentException $error) {
            $this->assertSame('URL is not public', $error->getMessage());
        }
        $this->assertSame(1, $fetcher->requests);
    }

    public function test_chunked_response_is_stopped_at_the_byte_limit(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('curl_init')) {
            $this->markTestSkipped('The socket test requires pcntl and curl');
        }
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $this->assertNotFalse($listener, $errorMessage);
        $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            $client = stream_socket_accept($listener, 5);
            if ($client !== false) {
                stream_set_timeout($client, 5);
                while (($line = fgets($client)) !== false && trim($line) !== '') {
                }
                fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n");
                @fwrite($client, "1000\r\n".str_repeat('A', 4096)."\r\n0\r\n\r\n");
                fclose($client);
            }
            fclose($listener);
            exit(0);
        }
        $fetcher = new class extends PublicPageFetcher
        {
            protected function isAllowedUrl(string $url): bool
            {
                return true;
            }

            protected function addresses(string $url): array
            {
                return ['127.0.0.1'];
            }
        };
        $failure = null;
        try {
            $fetcher->fetch("http://127.0.0.1:{$port}/", 1024, 5, 'Test');
        } catch (\RuntimeException $error) {
            $failure = $error;
        } finally {
            fclose($listener);
            pcntl_waitpid($pid, $status);
        }

        $this->assertInstanceOf(\RuntimeException::class, $failure);
        $this->assertSame('Response too large', $failure->getMessage());
    }
}
