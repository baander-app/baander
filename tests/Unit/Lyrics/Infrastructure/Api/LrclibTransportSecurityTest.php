<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lyrics\Infrastructure\Api;

use App\Lyrics\Application\DTO\LrclibUnavailable;
use App\Lyrics\Infrastructure\Api\LrclibClient;
use App\Lyrics\Infrastructure\Api\LrclibPauseInterface;
use App\Tests\Support\Outbound\LocalTlsServer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LrclibTransportSecurityTest extends TestCase
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

    public function testTrustedCertificateAndMatchingHostnameSucceed(): void
    {
        $response = $this->client()->request('GET', $this->url('/ok'));
        self::assertSame([], $response->toArray());
        self::assertSame(['https /ok'], $this->server->requests());
    }

    public function testUntrustedCertificateIsRejectedBeforeHttp(): void
    {
        try {
            $this->client(trust: false)->request('GET', $this->url('/ok'))->getStatusCode();
            self::fail('The untrusted certificate was accepted.');
        } catch (TransportExceptionInterface) {
            self::assertSame([], $this->server->requests());
        }
    }

    public function testTrustedCertificateForAnotherHostnameIsRejectedBeforeHttp(): void
    {
        try {
            $this->client()->request('GET', str_replace('outbound.baander.app', 'mismatch.baander.app', $this->url('/ok')))->getStatusCode();
            self::fail('The certificate hostname mismatch was accepted.');
        } catch (TransportExceptionInterface) {
            self::assertSame([], $this->server->requests());
        }
    }

    public function testLyricsRedirectCannotDowngradeToPrivateHttpDestination(): void
    {
        // Turns come at once and no pause is in effect, so the request goes straight to the transport.
        $adapter = new LrclibClient(
            $this->client(),
            new NullLogger(),
            new RateLimiterFactory(['id' => 'lrclib', 'policy' => 'no_limit'], new InMemoryStorage()),
            self::createStub(LrclibPauseInterface::class),
            new NativeClock(),
            $this->url(''),
        );
        $result = $adapter->search('a song');
        self::assertSame(['https /api/search?q=a%20song'], $this->server->requests());
        self::assertInstanceOf(LrclibUnavailable::class, $result);
    }

    private function url(string $path): string
    {
        return 'https://outbound.baander.app:' . $this->server->tlsPort . $path;
    }

    /** cURL is the one transport the images offer: with allow_url_fopen off, Symfony's stream client opens no URL. */
    private function client(bool $trust = true): HttpClientInterface
    {
        $options = [
            'resolve' => array_fill_keys(['outbound.baander.app', 'mismatch.baander.app', 'private.baander.app'], '127.0.0.1'),
            'no_proxy' => '*',
            'timeout' => 2,
            'max_duration' => 2,
        ];
        if ($trust) {
            $options['cafile'] = $this->server->certificate;
        }

        return new CurlHttpClient($options);
    }
}
