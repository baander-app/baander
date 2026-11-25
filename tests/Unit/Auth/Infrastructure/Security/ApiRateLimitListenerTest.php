<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Infrastructure\Security\ApiRateLimitListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

final class ApiRateLimitListenerTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function addressPairs(): iterable
    {
        yield 'one IPv6 /64' => ['2001:db8:20:1::1', '2001:db8:20:1:ffff:ffff:ffff:ffff', true];
        yield 'neighbouring IPv6 /64 networks' => ['2001:db8:20:1::1', '2001:db8:20:2::1', false];
        yield 'two IPv4 addresses in one /24' => ['198.18.20.7', '198.18.20.8', false];
        yield 'IPv4 and its mapped form' => ['198.18.20.7', '::ffff:198.18.20.7', true];
    }

    #[DataProvider('addressPairs')]
    public function testAnAnonymousIpv6NetworkIsChargedAsOneAddress(string $first, string $second, bool $shared): void
    {
        $keys = [];
        $anonymous = $this->createStub(RateLimiterFactoryInterface::class);
        $anonymous->method('create')->willReturnCallback(function (string $key) use (&$keys): LimiterInterface {
            $keys[] = $key;

            return $this->limit(accepted: true);
        });
        $listener = $this->listener($anonymous, new NullLogger());

        $listener->onKernelRequest($this->event($first));
        $listener->onKernelRequest($this->event($second));

        self::assertCount(2, $keys);
        self::assertSame($shared, $keys[0] === $keys[1]);
    }

    public function testTheLimitWarningLogsTheFullAddress(): void
    {
        $anonymous = $this->createMock(RateLimiterFactoryInterface::class);
        $anonymous->expects($this->once())
            ->method('create')
            ->with('2001:db8:20:1::')
            ->willReturn($this->limit(accepted: false));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('API rate limit exceeded', $this->callback(
                static fn (array $context): bool => $context['ip'] === '2001:db8:20:1::7',
            ));

        $this->expectException(TooManyRequestsHttpException::class);

        $this->listener($anonymous, $logger)->onKernelRequest($this->event('2001:db8:20:1::7'));
    }

    private function listener(RateLimiterFactoryInterface $anonymous, LoggerInterface $logger): ApiRateLimitListener
    {
        return new ApiRateLimitListener(
            anonymousApiLimiter: $anonymous,
            authenticatedApiLimiter: $this->createStub(RateLimiterFactoryInterface::class),
            tokenStorage: new TokenStorage(),
            logger: $logger,
            environment: 'prod',
        );
    }

    private function limit(bool $accepted): LimiterInterface
    {
        $limit = $this->createStub(LimiterInterface::class);
        $limit->method('consume')->willReturn(new RateLimit($accepted ? 9 : 0, new \DateTimeImmutable('+30 seconds'), $accepted, 10));

        return $limit;
    }

    private function event(string $address): RequestEvent
    {
        $request = Request::create('/api/example', 'GET', server: ['REMOTE_ADDR' => $address]);
        $request->attributes->set('_route', 'api_example');

        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
