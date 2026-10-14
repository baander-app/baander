<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lyrics\Infrastructure\Api;

use App\Lyrics\Application\DTO\LrclibUnavailable;
use App\Lyrics\Infrastructure\Api\LrclibClient;
use App\Tests\Support\Outbound\LocalTlsServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\HttpClient\CurlHttpClient;
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

    /** @return iterable<string, array{bool}> */
    public static function transports(): iterable
    {
        yield 'native streams' => [false];
        yield 'curl' => [true];
    }

    #[DataProvider('transports')]
    public function testTrustedCertificateAndMatchingHostnameSucceed(bool $curl): void
    {
        $response = $this->client($curl)->request('GET', $this->url('/ok'));
        self::assertSame([], $response->toArray());
        self::assertSame(['https /ok'], $this->server->requests());
    }

    #[DataProvider('transports')]
    public function testUntrustedCertificateIsRejectedBeforeHttp(bool $curl): void
    {
        try {
            $this->client($curl, trust: false)->request('GET', $this->url('/ok'))->getStatusCode();
            self::fail('The untrusted certificate was accepted.');
        } catch (TransportExceptionInterface) {
            self::assertSame([], $this->server->requests());
        }
    }

    #[DataProvider('transports')]
    public function testTrustedCertificateForAnotherHostnameIsRejectedBeforeHttp(bool $curl): void
    {
        try {
            $this->client($curl)->request('GET', str_replace('outbound.baander.app', 'mismatch.baander.app', $this->url('/ok')))->getStatusCode();
            self::fail('The certificate hostname mismatch was accepted.');
        } catch (TransportExceptionInterface) {
            self::assertSame([], $this->server->requests());
        }
    }

    #[DataProvider('transports')]
    public function testLyricsRedirectCannotDowngradeToPrivateHttpDestination(bool $curl): void
    {
        $adapter = new LrclibClient($this->client($curl), new NullLogger(), $this->url(''));
        $result = $adapter->search('a song');
        self::assertSame(['https /api/search?q=a%20song'], $this->server->requests());
        self::assertInstanceOf(LrclibUnavailable::class, $result);
    }

    private function url(string $path): string
    {
        return 'https://outbound.baander.app:' . $this->server->tlsPort . $path;
    }

    private function client(bool $curl, bool $trust = true): HttpClientInterface
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
        return $curl ? new CurlHttpClient($options) : new NativeHttpClient($options);
    }
}
