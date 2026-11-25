<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Infrastructure\EventListener;

use App\Catalog\Infrastructure\EventListener\AdminRateLimitListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class AdminRateLimitListenerTest extends TestCase
{
    /**
     * The router matches the decoded path, so a percent-encoded path reaches the
     * cover-extraction route and its limit.
     *
     * @return iterable<string, array{string}>
     */
    public static function coverExtractionPaths(): iterable
    {
        yield 'plain path' => ['/api/albums/covers/extract'];
        yield 'encoded path' => ['/api/albums/covers/%65xtract'];
    }

    #[DataProvider('coverExtractionPaths')]
    public function testCoverExtractionOverTheLimitIsRejected(string $path): void
    {
        $limit = $this->createStub(LimiterInterface::class);
        $limit->method('consume')->willReturn(new RateLimit(0, new \DateTimeImmutable('+30 seconds'), false, 10));
        $factory = $this->createMock(RateLimiterFactoryInterface::class);
        $factory->expects($this->once())->method('create')->with('192.0.2.60')->willReturn($limit);
        $listener = new AdminRateLimitListener($factory, new NullLogger(), 'prod');

        $this->expectException(TooManyRequestsHttpException::class);

        $listener->onKernelRequest($this->event($path, AdminRateLimitListener::COVER_EXTRACTION_ROUTE));
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
        $factory = $this->createStub(RateLimiterFactoryInterface::class);
        $factory->method('create')->willReturnCallback(function (string $key) use (&$keys): LimiterInterface {
            $keys[] = $key;
            $limit = $this->createStub(LimiterInterface::class);
            $limit->method('consume')->willReturn(new RateLimit(9, new \DateTimeImmutable('+1 minute'), true, 10));

            return $limit;
        });
        $listener = new AdminRateLimitListener($factory, new NullLogger(), 'prod');

        $listener->onKernelRequest($this->event('/api/albums/covers/extract', AdminRateLimitListener::COVER_EXTRACTION_ROUTE, $first));
        $listener->onKernelRequest($this->event('/api/albums/covers/extract', AdminRateLimitListener::COVER_EXTRACTION_ROUTE, $second));

        self::assertCount(2, $keys);
        self::assertSame($shared, $keys[0] === $keys[1]);
    }

    public function testOtherRoutesAreNotCharged(): void
    {
        $factory = $this->createMock(RateLimiterFactoryInterface::class);
        $factory->expects($this->never())->method('create');
        $listener = new AdminRateLimitListener($factory, new NullLogger(), 'prod');

        $listener->onKernelRequest($this->event('/api/albums/merge', 'album_merge'));
    }

    private function event(string $path, string $route, string $address = '192.0.2.60'): RequestEvent
    {
        $request = Request::create($path, 'POST', server: ['REMOTE_ADDR' => $address]);
        $request->attributes->set('_route', $route);

        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
