<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Http\PublicDestinationPolicy;
use App\Shared\Infrastructure\Http\PublicHttpClient;
use App\Shared\Infrastructure\Http\UnsafeDestinationException;
use App\Tests\Support\Outbound\LocalTlsServer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class PublicHttpTransportTest extends TestCase
{
    private LocalTlsServer $server;

    protected function setUp(): void
    {
        $this->server = new LocalTlsServer();
    }

    protected function tearDown(): void
    {
        $this->server->close();
    }

    public function testApprovedRequestUsesTlsHostnameAndVerifiedCertificate(): void
    {
        self::assertSame('[]', $this->client()->request('GET', 'https://outbound.baander.app/ok', ['outbound.baander.app'])->getContent());
        self::assertSame(['https /ok'], $this->server->requests());
    }

    public function testUntrustedCertificateFailsBeforeSendingHttp(): void
    {
        try {
            $this->client(false)->request('GET', 'https://outbound.baander.app/ok', ['outbound.baander.app']);
            self::fail('Untrusted certificate was accepted.');
        } catch (\RuntimeException $e) {
            self::assertSame('The provider request failed.', $e->getMessage());
            self::assertSame([], $this->server->requests());
        }
    }

    public function testHostnameMismatchFailsBeforeSendingHttp(): void
    {
        try {
            $this->client()->request('GET', 'https://mismatch.baander.app/ok', ['mismatch.baander.app']);
            self::fail('Wrong certificate hostname was accepted.');
        } catch (\RuntimeException $e) {
            self::assertSame('The provider request failed.', $e->getMessage());
            self::assertSame([], $this->server->requests());
        }
    }

    public function testRealRedirectNeverReachesPrivateHttpPeer(): void
    {
        try {
            $this->client()->request('GET', 'https://outbound.baander.app/stream-redirect', ['outbound.baander.app'], [], ['baander.app']);
            self::fail('Private downgrade was followed.');
        } catch (UnsafeDestinationException) {
            self::assertSame(['https /stream-redirect'], $this->server->requests());
        }
    }

    private function client(bool $trust = true): PublicHttpClient
    {
        // Route the approved test name to an isolated peer only after inspecting
        // the production pin. The public policy has no private-address exception.
        $transport = new class($this->server, $trust) implements HttpClientInterface {
            private CurlHttpClient $inner;

            public function __construct(private LocalTlsServer $peer, private bool $trust)
            {
                $this->inner = new CurlHttpClient();
            }

            /** @param array<string, mixed> $options */
            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                $host = (string) parse_url($url, PHP_URL_HOST);
                TestCase::assertSame([$host => '1.1.1.1'], $options['resolve']);
                TestCase::assertTrue($options['verify_peer']);
                TestCase::assertTrue($options['verify_host']);
                $options['extra']['curl'][CURLOPT_CONNECT_TO] = [$host . ':443:127.0.0.1:' . $this->peer->tlsPort];
                if ($this->trust) {
                    $options['cafile'] = $this->peer->certificate;
                }
                return $this->inner->request($method, $url, $options);
            }

            public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
            {
                return $this->inner->stream($responses, $timeout);
            }

            /** @param array<string, mixed> $options */
            public function withOptions(array $options): static
            {
                throw new \LogicException('Test routing has no caller defaults.');
            }
        };
        return new PublicHttpClient($transport, new PublicDestinationPolicy(static fn (): array => ['1.1.1.1']));
    }
}
