<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Push;

use App\Notification\Infrastructure\Push\PushTransport;
use App\Notification\Infrastructure\Push\WebPushFactory;
use App\Shared\Infrastructure\Http\PublicDestinationPolicy;
use App\Tests\Support\Outbound\ExpectedWebPushConstruction;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class PushTransportTest extends TestCase
{
    private const string ENDPOINT = 'https://push.baander.app/capability-secret?key=private';
    /** @var array{publicKey: string, privateKey: string}|null */
    private static ?array $keys = null;

    public function testMinishlinkEncryptsAndSignsTheOriginalUrlBeforePinnedDelivery(): void
    {
        $calls = 0;
        $webPush = $this->webPush(static fn (): array => ['1.1.1.1', '2606:4700:4700::1111'], static function (RequestInterface $request, array $options) use (&$calls): PromiseInterface {
            ++$calls;
            self::assertSame(self::ENDPOINT, (string) $request->getUri());
            self::assertSame('POST', $request->getMethod());
            self::assertSame('push.baander.app', $request->getHeaderLine('Host'));
            self::assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));
            self::assertNotSame('', (string) $request->getBody());
            self::assertStringNotContainsString('confidential-payload', (string) $request->getBody());
            self::assertMatchesRegularExpression('/^vapid t=([^,]+), k=/', $request->getHeaderLine('Authorization'));
            preg_match('/^vapid t=([^,]+),/', $request->getHeaderLine('Authorization'), $match);
            $claims = json_decode(base64_decode(strtr(explode('.', $match[1])[1], '-_', '+/'), true), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('https://push.baander.app', $claims['aud']);
            self::assertSame(['push.baander.app:443:1.1.1.1'], $options['curl'][CURLOPT_RESOLVE]);
            self::assertSame('', $options['proxy']);
            self::assertFalse($options['allow_redirects']);
            self::assertTrue($options['verify']);
            self::assertSame(2, $options['_curl_retries']);
            self::assertSame(CURL_HTTP_VERSION_1_1, $options['curl'][CURLOPT_HTTP_VERSION]);
            self::assertTrue($options['curl'][CURLOPT_FRESH_CONNECT]);
            self::assertTrue($options['curl'][CURLOPT_FORBID_REUSE]);
            self::assertFalse($options['curl'][CURLOPT_FOLLOWLOCATION]);
            self::assertSame('', $options['curl'][CURLOPT_PROXY]);
            self::assertSame('*', $options['curl'][CURLOPT_NOPROXY]);
            self::assertTrue($options['curl'][CURLOPT_SSL_VERIFYPEER]);
            self::assertSame(2, $options['curl'][CURLOPT_SSL_VERIFYHOST]);

            return new FulfilledPromise(new Response(201));
        });

        self::assertTrue($webPush->sendOneNotification($this->subscription(), 'confidential-payload')->isSuccess());
        self::assertSame(1, $calls);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function forbiddenAnswers(): iterable
    {
        yield 'private' => [['10.0.0.1']];
        yield 'mixed' => [['1.1.1.1', '127.0.0.1']];
        yield 'mapped loopback' => [['::ffff:127.0.0.1']];
        yield 'empty' => [[]];
    }

    /** @param list<string> $answers */
    #[DataProvider('forbiddenAnswers')]
    public function testUnsafePersistedDestinationNeverReachesTransport(array $answers): void
    {
        $calls = 0;
        $webPush = $this->webPush(static fn (): array => $answers, static function () use (&$calls): PromiseInterface {
            ++$calls;

            return new FulfilledPromise(new Response(201));
        });

        $report = $webPush->sendOneNotification($this->subscription(), 'confidential-payload');

        self::assertFalse($report->isSuccess());
        self::assertFalse($report->isSubscriptionExpired());
        self::assertNull($report->getResponse());
        self::assertStringNotContainsString('capability-secret', $report->getReason());
        self::assertSame(0, $calls);
    }

    public function testPersistedUnapprovedHostIsRejectedAtSendTime(): void
    {
        $calls = 0;
        $webPush = $this->webPush(static fn (): array => ['1.1.1.1'], static function () use (&$calls): PromiseInterface {
            ++$calls;

            return new FulfilledPromise(new Response(201));
        });

        self::assertFalse($webPush->sendOneNotification($this->subscription('https://other.baander.app/push'), 'payload')->isSuccess());
        self::assertSame(0, $calls);
    }

    public function testEverySendResolvesAgainAndBlocksAChangedAnswer(): void
    {
        $lookups = 0;
        $calls = 0;
        $webPush = $this->webPush(static function () use (&$lookups): array {
            return ++$lookups === 1 ? ['1.1.1.1'] : ['1.1.1.1', '169.254.169.254'];
        }, static function () use (&$calls): PromiseInterface {
            ++$calls;

            return new FulfilledPromise(new Response(201));
        });

        self::assertTrue($webPush->sendOneNotification($this->subscription(), 'payload')->isSuccess());
        self::assertFalse($webPush->sendOneNotification($this->subscription(), 'payload')->isSuccess());
        self::assertSame(2, $lookups);
        self::assertSame(1, $calls);
    }

    /** @return iterable<string, array{int, bool, bool}> */
    public static function responses(): iterable
    {
        yield 'accepted' => [201, true, false];
        yield 'redirect' => [302, false, false];
        yield 'temporary redirect' => [307, false, false];
        yield 'not found' => [404, false, true];
        yield 'gone' => [410, false, true];
        yield 'unavailable' => [503, false, false];
    }

    #[DataProvider('responses')]
    public function testRedirectsFailAndProviderExpiryHandlingIsPreserved(int $status, bool $success, bool $expired): void
    {
        $calls = 0;
        $webPush = $this->webPush(static fn (): array => ['1.1.1.1'], static function () use (&$calls, $status): PromiseInterface {
            ++$calls;

            return new FulfilledPromise(new Response($status, ['Location' => 'https://other.baander.app/target']));
        });

        $report = $webPush->sendOneNotification($this->subscription(), 'payload');

        self::assertSame($success, $report->isSuccess());
        self::assertSame($expired, $report->isSubscriptionExpired());
        self::assertStringNotContainsString('capability-secret', $report->getReason());
        self::assertSame(1, $calls, 'No redirect or retry may send another push.');
    }

    public function testCallerOptionsCannotReplacePinningTlsOrProxyPolicy(): void
    {
        $transport = new PushTransport(new PublicDestinationPolicy(static fn (): array => ['2606:4700:4700::1111']), ['push.baander.app'], static function (RequestInterface $request, array $options): PromiseInterface {
            self::assertSame(['push.baander.app:443:[2606:4700:4700::1111]'], $options['curl'][CURLOPT_RESOLVE]);
            self::assertSame('', $options['proxy']);
            self::assertTrue($options['verify']);
            self::assertFalse($options['allow_redirects']);
            self::assertSame(2, $options['_curl_retries']);
            self::assertArrayNotHasKey(CURLOPT_CONNECT_TO, $options['curl']);

            return new FulfilledPromise(new Response(201));
        });

        $transport(new Request('POST', self::ENDPOINT), [
            'proxy' => 'http://127.0.0.1:1234', 'verify' => false, 'allow_redirects' => true, '_curl_retries' => 0,
            'curl' => [CURLOPT_RESOLVE => ['push.baander.app:443:127.0.0.1'], CURLOPT_CONNECT_TO => ['push.baander.app:443:127.0.0.1:1234']],
        ])->wait();
    }

    public function testDnsConsumesPartOfThePushSendBudget(): void
    {
        $started = hrtime(true) / 1e9;
        $observedDeadline = null;
        $observedOptions = null;
        $policy = new PublicDestinationPolicy(static function (string $host, float $deadline) use (&$observedDeadline): array {
            $observedDeadline = $deadline;
            usleep(20_000);
            return ['1.1.1.1'];
        });
        $transport = new PushTransport($policy, ['push.baander.app'], static function (RequestInterface $request, array $options) use (&$observedOptions): PromiseInterface {
            $observedOptions = $options;
            return new FulfilledPromise(new Response(201));
        });
        self::assertSame(201, $transport(new Request('POST', self::ENDPOINT), [])->wait()->getStatusCode());
        self::assertGreaterThanOrEqual($started + 30, $observedDeadline);
        self::assertLessThan(hrtime(true) / 1e9 + 30, $observedDeadline);
        self::assertLessThan(29.99, $observedOptions['timeout']);
        self::assertGreaterThan(0, $observedOptions['timeout']);
        self::assertLessThanOrEqual(5.0, $observedOptions['connect_timeout']);
    }

    /** @return iterable<string, array{int}> */
    public static function replayConditions(): iterable
    {
        yield 'no response and no errno' => [0];
        yield 'rewind failed' => [65];
    }

    #[DataProvider('replayConditions')]
    public function testInstalledCurlCompletionDoesNotReplayAnUncertainPush(int $errno): void
    {
        $replays = 0;
        $webPush = $this->webPush(static fn (): array => ['1.1.1.1'], function (RequestInterface $request, array $options) use ($errno, &$replays): PromiseInterface {
            $easy = new EasyHandle();
            $easy->handle = curl_init();
            $easy->request = $request;
            $easy->options = $options;
            $easy->errno = $errno;
            $factory = $this->createMock(CurlFactoryInterface::class);
            $factory->expects(self::once())->method('release')->willReturnCallback(static function (EasyHandle $released): void {
                unset($released->handle);
            });

            return CurlFactory::finish(static function () use (&$replays): PromiseInterface {
                ++$replays;

                return new FulfilledPromise(new Response(201));
            }, $easy, $factory);
        });

        $report = $webPush->sendOneNotification($this->subscription(), 'confidential-payload');

        self::assertFalse($report->isSuccess());
        self::assertFalse($report->isSubscriptionExpired());
        self::assertSame(0, $replays);
    }

    /**
     * @param \Closure(string): list<string> $resolver
     * @param \Closure(RequestInterface, array<string, mixed>): PromiseInterface $handler
     */
    private function webPush(\Closure $resolver, \Closure $handler): WebPush
    {
        $transport = new PushTransport(new PublicDestinationPolicy($resolver), ['push.baander.app'], $handler);

        return ExpectedWebPushConstruction::create(new WebPushFactory($transport), ['VAPID' => ['subject' => 'mailto:push@baander.app', ...self::keys()]]);
    }

    private function subscription(string $endpoint = self::ENDPOINT): Subscription
    {
        return Subscription::create([
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => self::keys()['publicKey'], 'auth' => rtrim(strtr(base64_encode(str_repeat('a', 16)), '+/', '-_'), '=')],
            'contentEncoding' => 'aes128gcm',
        ]);
    }

    /** @return array{publicKey: string, privateKey: string} */
    private static function keys(): array
    {
        return self::$keys ??= VAPID::createVapidKeys();
    }
}
