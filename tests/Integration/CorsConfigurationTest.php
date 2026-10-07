<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use App\Shared\Infrastructure\EventListener\MediaCorsVaryListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Nelmio\CorsBundle\EventListener\CacheableResponseVaryListener;
use Nelmio\CorsBundle\EventListener\CorsListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/** Exercises configured listeners, without claiming endpoint authentication or database coverage. */
final class CorsConfigurationTest extends TestCase
{
    private const ORIGIN = 'https://web.media.baander.app';
    private static CorsConfigurationKernel $kernel;
    private static string $directory;
    /** @var array{server:mixed,serverExists:bool,env:mixed,envExists:bool,process:string|false} */
    private static array $previous;

    public static function setUpBeforeClass(): void
    {
        self::$previous = [
            'server' => $_SERVER['APP_URL'] ?? null, 'serverExists' => array_key_exists('APP_URL', $_SERVER),
            'env' => $_ENV['APP_URL'] ?? null, 'envExists' => array_key_exists('APP_URL', $_ENV),
            'process' => getenv('APP_URL'),
        ];
        $_SERVER['APP_URL'] = $_ENV['APP_URL'] = self::ORIGIN;
        putenv('APP_URL=' . self::ORIGIN);
        self::$directory = sys_get_temp_dir() . '/baander-cors-prod-' . bin2hex(random_bytes(8));
        self::$kernel = new CorsConfigurationKernel(self::$directory);
        self::$kernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$kernel->shutdown();
        if (self::$previous['serverExists']) {
            $_SERVER['APP_URL'] = self::$previous['server'];
        } else {
            unset($_SERVER['APP_URL']);
        }
        if (self::$previous['envExists']) {
            $_ENV['APP_URL'] = self::$previous['env'];
        } else {
            unset($_ENV['APP_URL']);
        }
        putenv(self::$previous['process'] === false ? 'APP_URL' : 'APP_URL=' . self::$previous['process']);
        (new Filesystem())->remove(self::$directory);
    }

    #[DataProvider('mediaRequests')]
    public function testProtectedMediaPreflightAllowsReadMethodsAndRangeHeaders(string $path, string $method): void
    {
        $response = $this->preflight($path, $method, 'Authorization, DPoP, Range, If-Range');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::ORIGIN, $response->headers->get('Access-Control-Allow-Origin'));
        self::assertContains(strtolower($method), $this->headerList($response, 'Access-Control-Allow-Methods'));
        foreach (['authorization', 'dpop', 'range', 'if-range'] as $header) {
            self::assertContains($header, $this->headerList($response, 'Access-Control-Allow-Headers'));
        }
        self::assertFalse($response->headers->has('Access-Control-Allow-Credentials'));
    }

    /** @return iterable<string,array{string,string}> */
    public static function mediaRequests(): iterable
    {
        foreach (['/api/images/image', '/api/images/image/file', '/api/stream/track'] as $path) {
            foreach (['GET', 'HEAD'] as $method) {
                yield $method . ' ' . $path => [$path, $method];
            }
        }
    }

    #[DataProvider('mediaStatuses')]
    public function testMediaResponsesExposeRangeValidatorsAndNonce(int $status): void
    {
        $response = $this->actual('/api/stream/track', self::ORIGIN, new Response('', $status, [
            'Content-Range' => 'bytes 0-3/8', 'Accept-Ranges' => 'bytes',
            'ETag' => '"media-test"', 'DPoP-Nonce' => 'fixture-nonce',
        ]));
        self::assertSame(self::ORIGIN, $response->headers->get('Access-Control-Allow-Origin'));
        foreach (['content-range', 'accept-ranges', 'etag', 'dpop-nonce'] as $header) {
            self::assertContains($header, $this->headerList($response, 'Access-Control-Expose-Headers'));
        }
        self::assertFalse($response->headers->has('Access-Control-Allow-Credentials'));
    }

    /** @return iterable<string,array{int}> */
    public static function mediaStatuses(): iterable
    {
        yield 'partial content' => [206];
        yield 'authentication failure' => [401];
    }

    public function testCacheableMediaResponseVariesByOrigin(): void
    {
        $response = new Response('', 200);
        $response->setPublic()->setMaxAge(60);
        $response->setVary('Accept-Encoding');
        $this->actual('/api/images/image/file', self::ORIGIN, $response);
        self::assertTrue($response->isCacheable());
        self::assertContains('Origin', $response->getVary());
        self::assertContains('Accept-Encoding', $response->getVary());
    }

    #[DataProvider('partialContentRequests')]
    public function testPublicPartialMediaVariesByOriginEvenWithoutOriginHeader(string $path, ?string $origin): void
    {
        $response = new Response('', 206, ['Content-Range' => 'bytes 0-3/8']);
        $response->setPublic()->setMaxAge(60);
        $response->setVary('Accept-Encoding');
        $this->actual($path, $origin, $response);
        self::assertContains('Origin', $response->getVary());
        self::assertContains('Accept-Encoding', $response->getVary());
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        if ($origin === null) {
            self::assertFalse($response->headers->has('Access-Control-Allow-Origin'));
        }
    }

    /** @return iterable<string,array{string,?string}> */
    public static function partialContentRequests(): iterable
    {
        foreach (['/api/images/image/file', '/api/stream/track'] as $path) {
            yield 'CORS ' . $path => [$path, self::ORIGIN];
            yield 'without Origin ' . $path => [$path, null];
        }
    }

    #[DataProvider('disallowedOrigins')]
    public function testMediaRejectsOtherOrigins(string $origin): void
    {
        self::assertFalse($this->preflight('/api/stream/track', 'GET', 'Authorization, DPoP', $origin)
            ->headers->has('Access-Control-Allow-Origin'));
        self::assertFalse($this->actual('/api/images/image/file', $origin, new Response())
            ->headers->has('Access-Control-Allow-Origin'));
    }

    /** @return iterable<string,array{string}> */
    public static function disallowedOrigins(): iterable
    {
        yield 'suffix host' => ['https://web.media.baander.app.foreign.baander.app'];
        yield 'regex dot lookalike' => ['https://webXmedia.baander.app'];
        yield 'different port' => ['https://web.media.baander.app:8444'];
        yield 'different scheme' => ['http://web.media.baander.app'];
        yield 'opaque origin' => ['null'];
        yield 'foreign host' => ['https://foreign.baander.app'];
    }

    public function testNonApiPathHasNoCors(): void
    {
        self::assertFalse($this->preflight('/unmatched', 'GET', 'Authorization')->headers->has('Access-Control-Allow-Origin'));
        self::assertFalse($this->actual('/unmatched', self::ORIGIN, new Response())->headers->has('Access-Control-Allow-Origin'));
    }

    public function testPublicJwksStillAllowsForeignOrigins(): void
    {
        $origin = 'https://foreign.baander.app';
        self::assertSame($origin, $this->actual('/.well-known/jwks.json', $origin, new Response())
            ->headers->get('Access-Control-Allow-Origin'));
    }

    /** First-party clients revoke tokens from the app origin; other origins get no CORS grant. */
    public function testTokenRevocationAllowsOnlyTheAppOrigin(): void
    {
        $preflight = $this->preflight('/api/oauth/revoke', 'POST', 'Content-Type, Authorization, DPoP');
        self::assertSame(200, $preflight->getStatusCode());
        self::assertSame(self::ORIGIN, $preflight->headers->get('Access-Control-Allow-Origin'));
        self::assertSame(self::ORIGIN, $this->actual('/api/oauth/revoke', self::ORIGIN, new Response())
            ->headers->get('Access-Control-Allow-Origin'));

        $foreign = 'https://foreign.baander.app';
        self::assertFalse($this->preflight('/api/oauth/revoke', 'POST', 'Content-Type', $foreign)
            ->headers->has('Access-Control-Allow-Origin'));
        self::assertFalse($this->actual('/api/oauth/revoke', $foreign, new Response())
            ->headers->has('Access-Control-Allow-Origin'));
    }

    public function testRemovedIntrospectionPathHasNoWildcardOrigin(): void
    {
        self::assertFalse($this->actual('/api/oauth/introspect', 'https://foreign.baander.app', new Response())
            ->headers->has('Access-Control-Allow-Origin'));
    }

    public function testStreamSigningPostRemainsAllowed(): void
    {
        self::assertSame(200, $this->preflight('/api/stream/sign', 'POST', 'Content-Type, Authorization, DPoP')->getStatusCode());
    }

    public function testSessionPreflightAllowsBaanderHeadersAndRejectsOldDeviceHeader(): void
    {
        $headers = 'Authorization, DPoP, X-Baander-Device-Id, X-Baander-Client-Fingerprint, X-Baander-Correlation-ID';
        $response = $this->preflight('/api/session', 'PUT', $headers);

        self::assertSame(200, $response->getStatusCode());
        foreach (['x-baander-device-id', 'x-baander-client-fingerprint', 'x-baander-correlation-id'] as $header) {
            self::assertContains($header, $this->headerList($response, 'Access-Control-Allow-Headers'));
        }
        self::assertNotContains('x-ratelimit-limit', $this->headerList(
            $this->actual('/api/session', self::ORIGIN, new Response()),
            'Access-Control-Expose-Headers',
        ));
        self::assertSame(400, $this->preflight('/api/session', 'PUT', 'Authorization, DPoP, X-Device-Id')->getStatusCode());
    }

    public function testMediaRejectsWriteMethodsAndUnknownHeaders(): void
    {
        foreach (['POST', 'DELETE'] as $method) {
            self::assertSame(405, $this->preflight('/api/stream/track', $method, 'Authorization, DPoP')->getStatusCode());
        }
        self::assertSame(400, $this->preflight('/api/images/image/file', 'GET', 'Authorization, DPoP, X-Unknown')->getStatusCode());
    }

    public function testMediaVaryListenerIsRegisteredInProductionDispatcher(): void
    {
        $container = self::$kernel->getContainer();
        $listener = $container->get('cors.production.media_vary');
        self::assertInstanceOf(MediaCorsVaryListener::class, $listener);
        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        self::assertContains([$listener, '__invoke'], $dispatcher->getListeners(KernelEvents::RESPONSE));
    }

    public function testRawPathStreamRouteIsRemoved(): void
    {
        $router = self::$kernel->getContainer()->get('cors.production.router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);
        $routes = $router->getRouteCollection();
        self::assertNull($routes->get('stream_media'));
        self::assertNotNull($routes->get('stream_track'));
    }

    private function preflight(string $path, string $method, string $headers, string $origin = self::ORIGIN): Response
    {
        $request = Request::create('https://api.baander.app' . $path, 'OPTIONS');
        $request->headers->set('Origin', $origin);
        $request->headers->set('Access-Control-Request-Method', $method);
        $request->headers->set('Access-Control-Request-Headers', $headers);
        $event = new RequestEvent(self::$kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $this->listener()->onKernelRequest($event);
        return $event->getResponse() ?? new Response();
    }

    private function actual(string $path, ?string $origin, Response $response): Response
    {
        $request = Request::create('https://api.baander.app' . $path);
        if ($origin !== null) {
            $request->headers->set('Origin', $origin);
        }
        $this->listener()->onKernelRequest(new RequestEvent(self::$kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $event = new ResponseEvent(self::$kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $this->listener()->onKernelResponse($event);
        $vary = self::$kernel->getContainer()->get('cors.production.vary');
        self::assertInstanceOf(CacheableResponseVaryListener::class, $vary);
        $vary->onResponse($event);
        $mediaVary = self::$kernel->getContainer()->get('cors.production.media_vary');
        self::assertInstanceOf(MediaCorsVaryListener::class, $mediaVary);
        $mediaVary($event);
        return $event->getResponse();
    }

    private function listener(): CorsListener
    {
        $listener = self::$kernel->getContainer()->get('cors.production.listener');
        self::assertInstanceOf(CorsListener::class, $listener);
        return $listener;
    }

    /** @return list<string> */
    private function headerList(Response $response, string $header): array
    {
        return array_map(strtolower(...), array_map(trim(...), explode(',', $response->headers->get($header) ?? '')));
    }
}

final class CorsConfigurationKernel extends Kernel
{
    public function __construct(private readonly string $temporaryDirectory)
    {
        parent::__construct('prod', false);
    }

    public function getProjectDir(): string { return dirname(__DIR__, 2); }
    public function getCacheDir(): string { return $this->temporaryDirectory . '/cache'; }
    public function getLogDir(): string { return $this->temporaryDirectory . '/log'; }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('cors.production.router', 'router')->setPublic(true);
        $container->setAlias('cors.production.listener', 'nelmio_cors.cors_listener')->setPublic(true);
        $container->setAlias('cors.production.media_vary', MediaCorsVaryListener::class)->setPublic(true);
        $container->setAlias('cors.production.vary', 'nelmio_cors.cacheable_response_vary_listener')->setPublic(true);
    }
}
