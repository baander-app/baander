<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery\Infrastructure\EventListener;

use App\Discovery\Infrastructure\EventListener\DiscoveryRateLimitListener;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\LimiterInterface;

#[AllowMockObjectsWithoutExpectations]
final class DiscoveryRateLimitListenerTest extends TestCase
{
    public function testIgnoresNonDiscoveryRoutes(): void
    {
        $limiter = $this->createMock(RateLimiterFactoryInterface::class);
        $limiter->expects($this->never())->method('create');

        $listener = new DiscoveryRateLimitListener($limiter, new NullLogger(), 'prod');
        $event = $this->createRequestEvent($this->routed(Request::create('/api/auth/login', 'POST'), 'auth_login_login'));

        $listener->onKernelRequest($event);

    }

    public function testAllowsRequestWithinLimit(): void
    {
        $rateLimit = new RateLimit(9, new \DateTimeImmutable('+1 minute'), true, 10);

        $limiterMock = $this->createMock(LimiterInterface::class);
        $limiterMock->method('consume')->willReturn($rateLimit);

        $limiter = $this->createMock(RateLimiterFactoryInterface::class);
        $limiter->expects($this->once())
            ->method('create')
            ->with('127.0.0.1')
            ->willReturn($limiterMock);

        $listener = new DiscoveryRateLimitListener($limiter, new NullLogger(), 'prod');
        $request = Request::create('/api/discovery/register', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $event = $this->createRequestEvent($this->routed($request, DiscoveryRateLimitListener::REGISTER_ROUTE));

        $listener->onKernelRequest($event);

        $this->assertSame(9, $event->getRequest()->attributes->get('rate_limit_remaining'));
    }

    /**
     * The router matches the decoded path, so a percent-encoded path reaches the same
     * route and its limit.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function limitedRoutes(): iterable
    {
        yield 'server registration' => [DiscoveryRateLimitListener::REGISTER_ROUTE, '/api/discovery/%72egister'];
        yield 'pairing code' => [DiscoveryRateLimitListener::PAIRING_CODE_ROUTE, '/api/discovery/pairing-cod%65'];
        yield 'pairing completion' => [DiscoveryRateLimitListener::COMPLETE_PAIRING_ROUTE, '/%61pi/discovery/complete-pairing'];
        yield 'QR pairing payload' => [DiscoveryRateLimitListener::QR_PAYLOAD_ROUTE, '/api/discovery/qr-p%61yload/0192f3a1-7b2c-7d4e-8f90-a1b2c3d4e5f6'];
    }

    #[DataProvider('limitedRoutes')]
    public function testRejectsRequestOverLimitWhateverThePathEncoding(string $route, string $path): void
    {
        $rateLimit = new RateLimit(0, new \DateTimeImmutable('+30 seconds'), false, 10);

        $limiterMock = $this->createMock(LimiterInterface::class);
        $limiterMock->method('consume')->willReturn($rateLimit);

        $limiter = $this->createMock(RateLimiterFactoryInterface::class);
        $limiter->expects($this->once())
            ->method('create')
            ->with('127.0.0.1')
            ->willReturn($limiterMock);

        $listener = new DiscoveryRateLimitListener($limiter, new NullLogger(), 'prod');
        $request = Request::create($path, 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $event = $this->createRequestEvent($this->routed($request, $route));

        $this->expectException(TooManyRequestsHttpException::class);

        $listener->onKernelRequest($event);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function addressPairs(): iterable
    {
        yield 'one IPv6 /64' => ['2001:db8:20:1::1', '2001:db8:20:1:ffff:ffff:ffff:ffff', true];
        yield 'neighbouring IPv6 /64 networks' => ['2001:db8:20:1::1', '2001:db8:20:2::1', false];
        yield 'two IPv4 addresses in one /24' => ['198.18.20.7', '198.18.20.8', false];
        yield 'IPv4 and its mapped form' => ['198.18.20.7', '::ffff:198.18.20.7', true];
    }

    #[DataProvider('addressPairs')]
    public function testAnIpv6NetworkIsChargedAsOneAddress(string $first, string $second, bool $shared): void
    {
        $keys = [];
        $limiter = $this->createStub(RateLimiterFactoryInterface::class);
        $limiter->method('create')->willReturnCallback(function (string $key) use (&$keys): LimiterInterface {
            $keys[] = $key;
            $limit = $this->createStub(LimiterInterface::class);
            $limit->method('consume')->willReturn(new RateLimit(9, new \DateTimeImmutable('+1 minute'), true, 10));

            return $limit;
        });
        $listener = new DiscoveryRateLimitListener($limiter, new NullLogger(), 'prod');

        foreach ([$first, $second] as $address) {
            $request = Request::create('/api/discovery/register', 'POST', server: ['REMOTE_ADDR' => $address]);
            $listener->onKernelRequest($this->createRequestEvent($this->routed($request, DiscoveryRateLimitListener::REGISTER_ROUTE)));
        }

        self::assertCount(2, $keys);
        self::assertSame($shared, $keys[0] === $keys[1]);
    }

    public function testSkipsRateLimitingInDevEnvironment(): void
    {
        $limiter = $this->createMock(RateLimiterFactoryInterface::class);
        $limiter->expects($this->never())->method('create');

        $listener = new DiscoveryRateLimitListener($limiter, new NullLogger(), 'dev');
        $request = Request::create('/api/discovery/register', 'POST');
        $event = $this->createRequestEvent($this->routed($request, DiscoveryRateLimitListener::REGISTER_ROUTE));

        $listener->onKernelRequest($event);

        $this->assertNull($event->getRequest()->attributes->get('rate_limit_remaining'));
    }

    private function routed(Request $request, string $route): Request
    {
        $request->attributes->set('_route', $route);

        return $request;
    }

    private function createRequestEvent(Request $request): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
