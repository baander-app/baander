<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\EventListener;

use App\Shared\Application\Http\PublicOrigins;
use App\Shared\Infrastructure\EventListener\SecurityHeadersListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SecurityHeadersListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }

    public function testEachHtmlResponseCarriesItsOwnNonceAndRefusesFraming(): void
    {
        $listener = new SecurityHeadersListener(new PublicOrigins('https://baander.app'));
        $first = $this->respond($listener, Request::create('https://baander.app/library'), new Response('<html></html>'));
        $second = $this->respond($listener, Request::create('https://baander.app/library'), new Response('<html></html>'));

        $policy = $this->directives($first->headers->get('Content-Security-Policy'));
        self::assertMatchesRegularExpression("/^'nonce-[A-Za-z0-9+\\/]{22,}={0,2}'$/", $policy['script-src'][1]);
        self::assertSame(["'self'", $policy['script-src'][1], "'wasm-unsafe-eval'"], $policy['script-src']);
        self::assertSame(["'none'"], $policy['frame-ancestors']);
        self::assertSame(["'none'"], $policy['object-src']);
        self::assertSame(["'none'"], $policy['base-uri']);
        self::assertSame(["'self'", 'blob:'], $policy['worker-src']);
        self::assertContains('wss://baander.app', $policy['connect-src']);
        self::assertNotSame($first->headers->get('Content-Security-Policy'), $second->headers->get('Content-Security-Policy'));
    }

    public function testTheNonceInThePolicyIsTheOneTheRequestCarriesForTemplates(): void
    {
        $listener = new SecurityHeadersListener(new PublicOrigins('https://baander.app'));
        $request = Request::create('https://baander.app/');
        $response = $this->respond($listener, $request, new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']));

        $nonce = $request->attributes->get(SecurityHeadersListener::NONCE_ATTRIBUTE);
        self::assertIsString($nonce);
        self::assertStringContainsString("'nonce-" . $nonce . "'", (string) $response->headers->get('Content-Security-Policy'));
    }

    public function testJsonResponsesCarryNoPolicyButKeepTheBaselineHeaders(): void
    {
        $listener = new SecurityHeadersListener(new PublicOrigins('https://baander.app'));
        $response = $this->respond($listener, Request::create('https://baander.app/api/me'), new JsonResponse([]));

        self::assertFalse($response->headers->has('Content-Security-Policy'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    public function testSignedMediaOnAnotherApplicationOriginAndTheDevelopmentServerAreAllowed(): void
    {
        $listener = new SecurityHeadersListener(
            new PublicOrigins('https://media.baander.app', ['http://localhost:9501']),
            ['http://localhost:5174'],
        );
        $policy = $this->directives($this->respond($listener, Request::create('http://localhost:9501/'), new Response('<html></html>'))->headers->get('Content-Security-Policy'));

        self::assertContains('https://media.baander.app', $policy['connect-src']);
        self::assertContains('https:', $policy['media-src']);
        self::assertContains('ws://localhost:9501', $policy['connect-src']);
        self::assertContains('http://localhost:5174', $policy['script-src']);
        self::assertContains('ws://localhost:5174', $policy['connect-src']);
    }

    public function testTheSocketOriginIsAListedRequestOriginAndTheApplicationOriginForAnyOtherHost(): void
    {
        $listener = new SecurityHeadersListener(new PublicOrigins('https://baander.app', ['https://192.168.1.20:8443']));
        $listed = $this->respond($listener, Request::create('https://192.168.1.20:8443/'), new Response('<html></html>'));
        $forged = $this->respond($listener, Request::create('https://other.baander.app/'), new Response('<html></html>'));

        self::assertContains(
            'wss://192.168.1.20:8443',
            $this->directives($listed->headers->get('Content-Security-Policy'))['connect-src'],
        );
        self::assertSame(
            ["'self'", 'wss://baander.app', 'https://baander.app'],
            $this->directives($forged->headers->get('Content-Security-Policy'))['connect-src'],
        );
    }

    public function testHstsIsSentOnlyForHttpsThroughATrustedProxy(): void
    {
        $listener = new SecurityHeadersListener(new PublicOrigins('https://baander.app'));
        Request::setTrustedProxies(['10.0.0.1'], Request::HEADER_X_FORWARDED_PROTO);

        $proxied = Request::create('http://baander.app/', server: ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https']);
        $untrusted = Request::create('http://baander.app/', server: ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https']);
        $plain = Request::create('http://baander.app/', server: ['REMOTE_ADDR' => '10.0.0.1']);

        self::assertSame('max-age=31536000', $this->respond($listener, $proxied, new Response())->headers->get('Strict-Transport-Security'));
        self::assertFalse($this->respond($listener, $untrusted, new Response())->headers->has('Strict-Transport-Security'));
        self::assertFalse($this->respond($listener, $plain, new Response())->headers->has('Strict-Transport-Security'));
    }

    private function respond(SecurityHeadersListener $listener, Request $request, Response $response): Response
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->onKernelResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));

        return $response;
    }

    /** @return array<string, list<string>> */
    private function directives(?string $policy): array
    {
        self::assertNotNull($policy);
        $directives = [];
        foreach (array_filter(array_map(trim(...), explode(';', $policy))) as $directive) {
            $parts = explode(' ', $directive);
            $directives[array_shift($parts)] = $parts;
        }

        return $directives;
    }
}
