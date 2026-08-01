<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Infrastructure\Security\RateLimitListener;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

#[AllowMockObjectsWithoutExpectations]
final class RateLimitListenerTest extends TestCase
{
    private RateLimiterFactoryInterface&MockObject $loginIpLimiter;
    private RateLimiterFactoryInterface&MockObject $loginIpEmailLimiter;
    private RateLimiterFactoryInterface&MockObject $registerIpLimiter;
    private RateLimiterFactoryInterface&MockObject $passwordResetIpLimiter;
    private RateLimiterFactoryInterface&MockObject $refreshClientLimiter;
    private LoggerInterface&MockObject $logger;
    private RateLimitListener $listener;

    protected function setUp(): void
    {
        $this->loginIpLimiter = $this->createMock(RateLimiterFactoryInterface::class);
        $this->loginIpEmailLimiter = $this->createMock(RateLimiterFactoryInterface::class);
        $this->registerIpLimiter = $this->createMock(RateLimiterFactoryInterface::class);
        $this->passwordResetIpLimiter = $this->createMock(RateLimiterFactoryInterface::class);
        $this->refreshClientLimiter = $this->createMock(RateLimiterFactoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->listener = new RateLimitListener(
            authLoginIpLimiter: $this->loginIpLimiter,
            authLoginIpEmailLimiter: $this->loginIpEmailLimiter,
            authRegisterIpLimiter: $this->registerIpLimiter,
            authPasswordResetIpLimiter: $this->passwordResetIpLimiter,
            authRefreshClientLimiter: $this->refreshClientLimiter,
            logger: $this->logger,
            jsonEncoder: new JsonEncoder(),
            environment: 'test',
        );
    }

    // --- Helper methods ---

    private function createAcceptedLimit(int $remainingTokens = 4): LimiterInterface
    {
        $rateLimit = $this->createMock(RateLimit::class);
        $rateLimit->method('isAccepted')->willReturn(true);
        $rateLimit->method('getRemainingTokens')->willReturn($remainingTokens);

        $limiter = $this->createMock(LimiterInterface::class);
        $limiter->method('consume')->with(1)->willReturn($rateLimit);

        return $limiter;
    }

    private function createRejectedLimit(int $retryAfterSeconds = 60): LimiterInterface
    {
        $rateLimit = $this->createMock(RateLimit::class);
        $rateLimit->method('isAccepted')->willReturn(false);
        $rateLimit->method('getRetryAfter')->willReturn(
            new \DateTimeImmutable('@' . (time() + $retryAfterSeconds)),
        );

        $limiter = $this->createMock(LimiterInterface::class);
        $limiter->method('consume')->with(1)->willReturn($rateLimit);

        return $limiter;
    }

    private function createRequestEvent(Request $request): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new RequestEvent(
            kernel: $kernel,
            request: $request,
            requestType: HttpKernelInterface::MAIN_REQUEST,
        );
    }

    private function createJsonRequest(string $path, string $method = 'POST', array $body = [], string $ip = '192.168.1.1'): Request
    {
        $request = Request::create($path, $method, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $ip,
        ], json_encode($body) ?: '');

        return $request;
    }

    // --- Login rate limiting tests ---

    public function testLoginUnderLimitPassesThrough(): void
    {
        $request = $this->createJsonRequest('/api/auth/login', body: ['email' => 'user@example.com', 'password' => 'pass']);
        $event = $this->createRequestEvent($request);

        $this->loginIpLimiter->method('create')->willReturn($this->createAcceptedLimit(4));
        $this->loginIpEmailLimiter->method('create')->willReturn($this->createAcceptedLimit(2));

        // Should not throw
        $this->listener->onKernelRequest($event);

        // Verify rate limit info is attached to request
        $this->assertNotNull($request->attributes->get('rate_limit_remaining'));
    }

    public function testLoginOverLimitThrowsTooManyRequests(): void
    {
        $request = $this->createJsonRequest('/api/auth/login', body: ['email' => 'user@example.com', 'password' => 'pass']);
        $event = $this->createRequestEvent($request);

        $this->loginIpLimiter->method('create')->willReturn($this->createRejectedLimit(120));
        $this->loginIpEmailLimiter->method('create')->willReturn($this->createAcceptedLimit(2));

        $this->logger->expects($this->once())->method('warning');

        $this->expectException(TooManyRequestsHttpException::class);
        $this->expectExceptionMessage('Too many requests');

        $this->listener->onKernelRequest($event);
    }

    public function testLoginPerEmailOverLimitThrowsTooManyRequests(): void
    {
        // IP limit passes but IP+email limit rejects
        $request = $this->createJsonRequest('/api/auth/login', body: ['email' => 'target@example.com', 'password' => 'pass']);
        $event = $this->createRequestEvent($request);

        $this->loginIpLimiter->method('create')->willReturn($this->createAcceptedLimit(4));
        $this->loginIpEmailLimiter->method('create')->willReturn($this->createRejectedLimit(300));

        $this->expectException(TooManyRequestsHttpException::class);

        $this->listener->onKernelRequest($event);
    }

    public function testLoginWithoutEmailSkipsCompoundCheck(): void
    {
        // Login body has no email field -- the per-IP check runs, compound check is skipped
        $request = $this->createJsonRequest('/api/auth/login', body: ['password' => 'pass']);
        $event = $this->createRequestEvent($request);

        $this->loginIpLimiter->method('create')->willReturn($this->createAcceptedLimit(4));
        // Compound limiter should NOT be called because email key is empty and skip_empty_key is set
        $this->loginIpEmailLimiter->expects($this->never())->method('create');

        $this->listener->onKernelRequest($event);
    }

    // --- Registration rate limiting tests ---

    public function testRegisterUnderLimitPassesThrough(): void
    {
        $request = $this->createJsonRequest('/api/auth/register', body: ['email' => 'new@example.com', 'name' => 'Test', 'password' => 'pass']);
        $event = $this->createRequestEvent($request);

        $this->registerIpLimiter->method('create')->willReturn($this->createAcceptedLimit(2));

        $this->listener->onKernelRequest($event);
    }

    public function testRegisterOverLimitThrowsTooManyRequests(): void
    {
        $request = $this->createJsonRequest('/api/auth/register', body: ['email' => 'new@example.com', 'name' => 'Test', 'password' => 'pass']);
        $event = $this->createRequestEvent($request);

        $this->registerIpLimiter->method('create')->willReturn($this->createRejectedLimit(600));

        $this->expectException(TooManyRequestsHttpException::class);

        $this->listener->onKernelRequest($event);
    }

    // --- Password reset rate limiting tests ---

    public function testPasswordResetUnderLimitPassesThrough(): void
    {
        $request = $this->createJsonRequest('/api/auth/password/reset-request', body: ['email' => 'user@example.com']);
        $event = $this->createRequestEvent($request);

        $this->passwordResetIpLimiter->method('create')->willReturn($this->createAcceptedLimit(4));

        $this->listener->onKernelRequest($event);
    }

    public function testPasswordResetOverLimitThrowsTooManyRequests(): void
    {
        $request = $this->createJsonRequest('/api/auth/password/reset-request', body: ['email' => 'user@example.com']);
        $event = $this->createRequestEvent($request);

        $this->passwordResetIpLimiter->method('create')->willReturn($this->createRejectedLimit(300));

        $this->expectException(TooManyRequestsHttpException::class);

        $this->listener->onKernelRequest($event);
    }

    // --- Refresh token rate limiting tests ---

    public function testRefreshUnderLimitPassesThrough(): void
    {
        $request = $this->createJsonRequest('/api/auth/refresh', body: ['refreshToken' => 'token-abc']);
        $event = $this->createRequestEvent($request);

        $this->refreshClientLimiter->method('create')->willReturn($this->createAcceptedLimit(29));

        $this->listener->onKernelRequest($event);
    }

    public function testRefreshOverLimitThrowsTooManyRequests(): void
    {
        $request = $this->createJsonRequest('/api/auth/refresh', body: ['refreshToken' => 'token-abc']);
        $event = $this->createRequestEvent($request);

        $this->refreshClientLimiter->method('create')->willReturn($this->createRejectedLimit(45));

        $this->expectException(TooManyRequestsHttpException::class);

        $this->listener->onKernelRequest($event);
    }

    public function testRefreshUsesTokenAsKey(): void
    {
        $request = $this->createJsonRequest('/api/auth/refresh', body: ['refreshToken' => 'unique-token-xyz']);
        $event = $this->createRequestEvent($request);

        $limiter = $this->createMock(LimiterInterface::class);
        $rateLimit = $this->createMock(RateLimit::class);
        $rateLimit->method('isAccepted')->willReturn(true);
        $rateLimit->method('getRemainingTokens')->willReturn(29);
        $limiter->method('consume')->with(1)->willReturn($rateLimit);

        $this->refreshClientLimiter->expects($this->once())
            ->method('create')
            ->with('unique-token-xyz')
            ->willReturn($limiter);

        $this->listener->onKernelRequest($event);
    }

    // --- Non-rate-limited endpoints ---

    public function testNonAuthEndpointIsNotRateLimited(): void
    {
        $request = $this->createJsonRequest('/api/catalog/songs');
        $event = $this->createRequestEvent($request);

        // No limiters should be called
        $this->loginIpLimiter->expects($this->never())->method('create');
        $this->registerIpLimiter->expects($this->never())->method('create');
        $this->passwordResetIpLimiter->expects($this->never())->method('create');
        $this->refreshClientLimiter->expects($this->never())->method('create');

        $this->listener->onKernelRequest($event);
    }

    public function testAuthMeEndpointIsNotRateLimited(): void
    {
        $request = Request::create('/api/auth/me', 'GET');
        $event = $this->createRequestEvent($request);

        // No limiters should be called for GET /api/auth/me
        $this->loginIpLimiter->expects($this->never())->method('create');
        $this->registerIpLimiter->expects($this->never())->method('create');

        $this->listener->onKernelRequest($event);
    }

    // --- Different IPs have independent limits ---

    public function testDifferentIpsHaveIndependentLoginLimits(): void
    {
        $requestIp1 = $this->createJsonRequest('/api/auth/login', body: ['email' => 'user@example.com', 'password' => 'pass'], ip: '10.0.0.1');
        $eventIp1 = $this->createRequestEvent($requestIp1);

        $requestIp2 = $this->createJsonRequest('/api/auth/login', body: ['email' => 'user@example.com', 'password' => 'pass'], ip: '10.0.0.2');
        $eventIp2 = $this->createRequestEvent($requestIp2);

        // IP1 is rate limited
        $this->loginIpLimiter->method('create')
            ->willReturnCallback(function (string $key) use ($requestIp1): LimiterInterface {
                if ($key === '10.0.0.1') {
                    return $this->createRejectedLimit(120);
                }

                return $this->createAcceptedLimit(4);
            });

        $this->loginIpEmailLimiter->method('create')->willReturn($this->createAcceptedLimit(2));

        // IP1 should be rejected
        $this->expectException(TooManyRequestsHttpException::class);
        $this->listener->onKernelRequest($eventIp1);
    }

    public function testDifferentIpsHaveIndependentLimitsSecondIpAllowed(): void
    {
        $requestIp2 = $this->createJsonRequest('/api/auth/login', body: ['email' => 'user@example.com', 'password' => 'pass'], ip: '10.0.0.2');
        $eventIp2 = $this->createRequestEvent($requestIp2);

        $this->loginIpLimiter->method('create')->willReturn($this->createAcceptedLimit(4));
        $this->loginIpEmailLimiter->method('create')->willReturn($this->createAcceptedLimit(2));

        // IP2 should pass through
        $this->listener->onKernelRequest($eventIp2);
    }

    // --- Method filtering ---

    public function testGetRequestToLoginIsNotRateLimited(): void
    {
        $request = Request::create('/api/auth/login', 'GET');
        $event = $this->createRequestEvent($request);

        // GET should not be rate limited
        $this->loginIpLimiter->expects($this->never())->method('create');

        $this->listener->onKernelRequest($event);
    }

    // --- Sub-request handling ---

    public function testSubRequestsAreNotRateLimited(): void
    {
        $request = $this->createJsonRequest('/api/auth/login', body: ['email' => 'user@example.com', 'password' => 'pass']);
        $kernel = $this->createMock(HttpKernelInterface::class);

        // Sub-request
        $event = new RequestEvent(
            kernel: $kernel,
            request: $request,
            requestType: HttpKernelInterface::SUB_REQUEST,
        );

        // No limiters should be called
        $this->loginIpLimiter->expects($this->never())->method('create');

        $this->listener->onKernelRequest($event);
    }

    // --- Logger integration ---

    public function testRateLimitExceededLogsWarning(): void
    {
        $request = $this->createJsonRequest('/api/auth/register', body: ['email' => 'new@example.com', 'name' => 'Test', 'password' => 'pass']);
        $event = $this->createRequestEvent($request);

        $this->registerIpLimiter->method('create')->willReturn($this->createRejectedLimit(300));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Rate limit exceeded', $this->callback(function (array $context): bool {
                return isset($context['path'])
                    && isset($context['ip'])
                    && isset($context['retry_after']);
            }));

        try {
            $this->listener->onKernelRequest($event);
        } catch (TooManyRequestsHttpException) {
            // Expected
        }
    }

    // --- TooManyRequestsHttpException has correct Retry-After ---

    public function testRetryAfterHeaderFromRateLimit(): void
    {
        $request = $this->createJsonRequest('/api/auth/login', body: ['email' => 'user@example.com', 'password' => 'pass']);
        $event = $this->createRequestEvent($request);

        $this->loginIpLimiter->method('create')->willReturn($this->createRejectedLimit(237));

        try {
            $this->listener->onKernelRequest($event);
            $this->fail('Expected TooManyRequestsHttpException');
        } catch (TooManyRequestsHttpException $e) {
            $this->assertSame(237, $e->getHeaders()['Retry-After']);
            $this->assertSame(429, $e->getStatusCode());
        }
    }
}
