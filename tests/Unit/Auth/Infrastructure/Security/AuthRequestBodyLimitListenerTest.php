<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Infrastructure\Security\AuthRequestBodyLimitListener;
use App\Auth\Infrastructure\Security\RateLimitListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class AuthRequestBodyLimitListenerTest extends TestCase
{
    public function testABodyOf64KiBIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();

        $this->request(RateLimitListener::LOGIN_ROUTE, str_repeat('a', 65536)) |> $this->dispatch(...);
    }

    public function testOneByteMoreIsRefusedWith413(): void
    {
        try {
            $this->request(RateLimitListener::LOGIN_ROUTE, str_repeat('a', 65537)) |> $this->dispatch(...);
            self::fail('A body over 64 KiB must be refused.');
        } catch (HttpException $exception) {
            self::assertSame(413, $exception->getStatusCode());
            self::assertSame('The request body is larger than 65536 bytes.', $exception->getMessage());
        }
    }

    /** Swoole sets the body it received; a Content-Length header that understates it changes nothing. */
    public function testTheReceivedBytesCountNotTheDeclaredLength(): void
    {
        $request = $this->request(RateLimitListener::TOKEN_ROUTE, str_repeat('a', 2_000_000));
        $request->headers->set('Content-Length', '16');

        $this->expectException(HttpException::class);

        $this->dispatch($request);
    }

    public function testRoutesWithoutTheCapAndSubRequestsPass(): void
    {
        $this->expectNotToPerformAssertions();
        $body = str_repeat('a', 65537);

        $this->dispatch($this->request(RateLimitListener::PAR_ROUTE, $body));
        $this->dispatch($this->request('albums_list', $body));
        $this->dispatch($this->request(RateLimitListener::LOGIN_ROUTE, $body), HttpKernelInterface::SUB_REQUEST);
    }

    private function request(string $route, string $body): Request
    {
        $request = Request::create('https://baander.app/api/auth/login', 'POST', content: $body);
        $request->attributes->set('_route', $route);

        return $request;
    }

    private function dispatch(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): void
    {
        new AuthRequestBodyLimitListener()->onKernelRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type));
    }
}
