<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Http\PublicDestinationPolicy;
use App\Shared\Infrastructure\Http\PublicHttpClient;
use App\Shared\Infrastructure\Http\UnsafeDestinationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PublicHttpClientTest extends TestCase
{
    public function testRequestPinsValidatedAddressAndForcesTransportProtection(): void
    {
        $transport = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.baander.app/token', $url);
            self::assertSame('grant_type=client_credentials', $options['body']);
            self::assertSame(['api.baander.app' => '1.1.1.1'], $options['resolve']);
            self::assertSame('', $options['proxy']);
            self::assertSame('*', $options['no_proxy']);
            self::assertTrue($options['verify_peer']);
            self::assertTrue($options['verify_host']);
            self::assertSame(0, $options['max_redirects']);
            self::assertSame('1.1', $options['http_version']);
            self::assertTrue($options['extra']['curl'][CURLOPT_FRESH_CONNECT]);
            self::assertTrue($options['extra']['curl'][CURLOPT_FORBID_REUSE]);
            self::assertLessThanOrEqual(5.0, $options['max_duration']);
            return new MockResponse('{}');
        });
        $response = $this->client($transport)->request('POST', 'https://api.baander.app/token', ['api.baander.app'], [
            'body' => 'grant_type=client_credentials', 'headers' => ['Authorization' => 'Basic secret'], 'timeout' => 5,
        ]);
        self::assertSame('{}', $response->getContent());
    }

    public function testRelativeAndApprovedCrossOriginRedirectsAreRevalidatedAndDropCredentials(): void
    {
        $responses = [
            new MockResponse('', ['http_code' => 307, 'response_headers' => ['location: /moved']]),
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location: https://cdn.baander.app/index']]),
            new MockResponse('{}'),
        ];
        $calls = [];
        $transport = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls, &$responses): MockResponse {
            $calls[] = [$url, $options];
            return array_shift($responses);
        });
        $lookups = [];
        $policy = new PublicDestinationPolicy(static function (string $host) use (&$lookups): array {
            $lookups[] = $host;
            return ['1.1.1.1'];
        });
        $client = new PublicHttpClient($transport, $policy);
        self::assertSame('{}', $client->request('GET', 'https://api.baander.app/start', ['api.baander.app'], [
            'headers' => ['Authorization: Bearer secret', 'Cookie: private=secret', 'X-Baander-Secret: secret', 'Accept: application/json'],
        ], ['api.baander.app', 'cdn.baander.app'])->getContent());
        self::assertSame(['api.baander.app', 'api.baander.app', 'cdn.baander.app'], $lookups);
        self::assertSame('https://api.baander.app/moved', $calls[1][0]);
        self::assertArrayHasKey('authorization', $calls[1][1]['normalized_headers']);
        self::assertArrayNotHasKey('authorization', $calls[2][1]['normalized_headers']);
        self::assertArrayNotHasKey('cookie', $calls[2][1]['normalized_headers']);
        self::assertArrayNotHasKey('x-baander-secret', $calls[2][1]['normalized_headers']);
        self::assertSame(['accept: application/json'], $calls[2][1]['normalized_headers']['accept']);
    }

    #[DataProvider('forbiddenRedirects')]
    public function testRejectedRedirectIsCancelledAndNeverRequested(string $location): void
    {
        $response = $this->createMock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(302);
        $response->method('getHeaders')->willReturn(['location' => [$location]]);
        $response->expects(self::once())->method('cancel');
        $transport = $this->createMock(HttpClientInterface::class);
        $transport->expects(self::once())->method('request')->willReturn($response);
        $this->expectException(UnsafeDestinationException::class);
        $this->client($transport)->request('GET', 'https://api.baander.app/start', ['api.baander.app'], [], ['api.baander.app']);
    }

    /** @return iterable<string, array{string}> */
    public static function forbiddenRedirects(): iterable
    {
        foreach (['http://api.baander.app/private', 'https://private.baander.app/x', 'https://api.baander.app:444/x',
            'https://user:secret@api.baander.app/x', 'https://api.baander.app/x#fragment', 'https://127.0.0.1/x'] as $url) {
            yield $url => [$url];
        }
    }

    public function testRedirectsAreRejectedByDefault(): void
    {
        $transport = new MockHttpClient(new MockResponse('', ['http_code' => 302, 'response_headers' => ['location: /next']]));
        $this->expectException(UnsafeDestinationException::class);
        $this->client($transport)->request('GET', 'https://api.baander.app/start', ['api.baander.app']);
    }

    public function testRedirectLoopStopsAfterThreeHops(): void
    {
        $transport = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 307, 'response_headers' => ['location: /loop']]));
        try {
            $this->client($transport)->request('GET', 'https://api.baander.app/start', ['api.baander.app'], [], ['api.baander.app']);
            self::fail('Redirect loop was accepted.');
        } catch (UnsafeDestinationException) {
            self::assertSame(4, $transport->getRequestsCount());
        }
    }

    public function testChangedDnsStopsSameOriginRedirectBeforeSecondRequest(): void
    {
        $calls = 0;
        $policy = new PublicDestinationPolicy(static function () use (&$calls): array { return ++$calls === 1 ? ['1.1.1.1'] : ['127.0.0.1']; });
        $transport = new MockHttpClient(new MockResponse('', ['http_code' => 307, 'response_headers' => ['location: /next']]));
        try {
            (new PublicHttpClient($transport, $policy))->request('GET', 'https://api.baander.app/start', ['api.baander.app'], [], ['api.baander.app']);
            self::fail('Changed private address was accepted.');
        } catch (UnsafeDestinationException) {
            self::assertSame(1, $transport->getRequestsCount());
        }
    }

    #[DataProvider('protectedOptions')]
    public function testCallerCannotOverrideSecurityOptions(string $name): void
    {
        $transport = new MockHttpClient();
        $this->expectException(\InvalidArgumentException::class);
        $this->client($transport)->request('GET', 'https://api.baander.app/x', ['api.baander.app'], [$name => false]);
    }

    /** @return iterable<string, array{string}> */
    public static function protectedOptions(): iterable
    {
        foreach (['resolve', 'proxy', 'no_proxy', 'verify_peer', 'verify_host', 'extra', 'max_redirects', 'auth_basic', 'auth_bearer', 'base_uri', 'cafile', 'bindto'] as $option) {
            yield $option => [$option];
        }
    }

    public function testTransportFailureIsGenericAndNotReplayed(): void
    {
        $calls = 0;
        $transport = new MockHttpClient(static function () use (&$calls): never { ++$calls; throw new \RuntimeException('https://api.baander.app/?key=secret'); });
        try {
            $this->client($transport)->request('POST', 'https://api.baander.app/token', ['api.baander.app']);
            self::fail('Failed transport was accepted.');
        } catch (\RuntimeException $e) {
            self::assertSame('The provider request failed.', $e->getMessage());
            self::assertNull($e->getPrevious());
            self::assertSame(1, $calls);
        }
    }

    public function testExpiredBudgetAfterDnsDoesNotStartHttp(): void
    {
        $transport = $this->createMock(HttpClientInterface::class);
        $transport->expects(self::never())->method('request');
        $policy = new PublicDestinationPolicy(static function (): array {
            usleep(10000);
            return ['1.1.1.1'];
        });
        $this->expectException(\RuntimeException::class);
        (new PublicHttpClient($transport, $policy))->request('GET', 'https://api.baander.app/x', ['api.baander.app'], ['max_duration' => 0.001]);
    }

    public function testRedirectLookupsShareOneDeadlineAndReduceTransportBudget(): void
    {
        $deadlines = [];
        $budgets = [];
        $policy = new PublicDestinationPolicy(static function (string $host, float $deadline) use (&$deadlines): array {
            $deadlines[] = $deadline;
            usleep(20_000);
            return ['1.1.1.1'];
        });
        $transport = new MockHttpClient(static function (string $method, string $url, array $options) use (&$budgets): MockResponse {
            $budgets[] = $options['max_duration'];
            return count($budgets) === 1
                ? new MockResponse('', ['http_code' => 302, 'response_headers' => ['location: /next']])
                : new MockResponse('complete');
        });
        $response = (new PublicHttpClient($transport, $policy))->request('GET', 'https://api.baander.app/x', ['api.baander.app'], ['max_duration' => 1], ['baander.app']);
        self::assertSame('complete', $response->getContent());
        self::assertCount(2, $deadlines);
        self::assertSame($deadlines[0], $deadlines[1]);
        self::assertLessThan(0.99, $budgets[0]);
        self::assertLessThan($budgets[0] - 0.01, $budgets[1]);
        self::assertGreaterThan(0, $budgets[1]);
    }

    public function testTokenPostCannotOptIntoRedirects(): void
    {
        $transport = $this->createMock(HttpClientInterface::class);
        $transport->expects(self::never())->method('request');
        $this->expectException(\InvalidArgumentException::class);
        $this->client($transport)->request('POST', 'https://api.baander.app/token', ['api.baander.app'], ['body' => 'secret'], ['baander.app']);
    }

    public function testCallerCannotReplaceHostHeader(): void
    {
        $transport = $this->createMock(HttpClientInterface::class);
        $transport->expects(self::never())->method('request');
        $this->expectException(\InvalidArgumentException::class);
        $this->client($transport)->request('GET', 'https://api.baander.app/x', ['api.baander.app'], ['headers' => ['hOsT: private.baander.app']]);
    }

    private function client(HttpClientInterface $transport): PublicHttpClient
    {
        return new PublicHttpClient($transport, new PublicDestinationPolicy(static fn (): array => ['1.1.1.1']));
    }
}
