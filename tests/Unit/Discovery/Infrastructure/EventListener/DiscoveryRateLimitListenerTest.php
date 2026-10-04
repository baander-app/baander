<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery\Infrastructure\EventListener;

use App\Discovery\Infrastructure\EventListener\DiscoveryRateLimitListener;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
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
        $event = $this->createRequestEvent(Request::create('/api/auth/login', 'POST'));

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
        $event = $this->createRequestEvent(Request::create('/api/discovery/register', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']));

        $listener->onKernelRequest($event);

        $this->assertSame(9, $event->getRequest()->attributes->get('rate_limit_remaining'));
    }

    public function testRejectsRequestOverLimit(): void
    {
        $rateLimit = new RateLimit(0, new \DateTimeImmutable('+30 seconds'), false, 10);

        $limiterMock = $this->createMock(LimiterInterface::class);
        $limiterMock->method('consume')->willReturn($rateLimit);

        $limiter = $this->createMock(RateLimiterFactoryInterface::class);
        $limiter->method('create')->willReturn($limiterMock);

        $listener = new DiscoveryRateLimitListener($limiter, new NullLogger(), 'prod');
        $event = $this->createRequestEvent(Request::create('/api/discovery/pairing-code', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']));

        $this->expectException(TooManyRequestsHttpException::class);

        $listener->onKernelRequest($event);
    }

    public function testSkipsRateLimitingInDevEnvironment(): void
    {
        $limiter = $this->createMock(RateLimiterFactoryInterface::class);
        $limiter->expects($this->never())->method('create');

        $listener = new DiscoveryRateLimitListener($limiter, new NullLogger(), 'dev');
        $event = $this->createRequestEvent(Request::create('/api/discovery/register', 'POST'));

        $listener->onKernelRequest($event);

        $this->assertNull($event->getRequest()->attributes->get('rate_limit_remaining'));
    }

    private function createRequestEvent(Request $request): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
