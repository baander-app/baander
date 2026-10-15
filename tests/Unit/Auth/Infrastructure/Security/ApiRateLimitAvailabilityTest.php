<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Infrastructure\Security\ApiRateLimitListener;
use App\Shared\Application\Exception\RateLimiterUnavailableException;
use App\Shared\Infrastructure\EventListener\ExceptionSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Translation\Translator;

final class ApiRateLimitAvailabilityTest extends TestCase
{
    /** @return iterable<string, array{string, bool, bool}> */
    public static function deniedRequests(): iterable
    {
        yield 'anonymous' => ['api_example', false, false];
        yield 'authenticated' => ['api_example', true, false];
        yield 'firewall denial' => ['api_example', false, true];
        yield 'similarly named route' => ['auth_logout_other', true, false];
    }

    #[DataProvider('deniedRequests')]
    public function testUnavailableBudgetStopsTheController(string $route, bool $authenticated, bool $accessDenied): void
    {
        [$response, $calls] = $this->request($route, $authenticated, $accessDenied);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame(0, $calls);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function revocationRoutes(): iterable
    {
        foreach (['auth_logout', 'oauth_revoke'] as $route) {
            yield $route . ' anonymous' => [$route, false];
            yield $route . ' authenticated' => [$route, true];
        }
    }

    #[DataProvider('revocationRoutes')]
    public function testUnavailableGenericBudgetAllowsRevocation(string $route, bool $authenticated): void
    {
        [$response, $calls] = $this->request($route, $authenticated, false);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(1, $calls);
    }

    #[DataProvider('revocationRoutes')]
    public function testExhaustedGenericBudgetStillRejectsRevocation(string $route, bool $authenticated): void
    {
        $this->expectException(TooManyRequestsHttpException::class);

        $this->request($route, $authenticated, false, true);
    }

    /** @return array{Response, int} */
    private function request(string $route, bool $authenticated, bool $accessDenied, bool $exhausted = false): array
    {
        $factory = $this->createMock(RateLimiterFactoryInterface::class);
        if ($exhausted) {
            $limiter = $this->createMock(LimiterInterface::class);
            $limiter->expects(self::once())->method('consume')->with(1)
                ->willReturn(new RateLimit(0, new \DateTimeImmutable('+30 seconds'), false, 1));
            $factory->expects(self::once())->method('create')->willReturn($limiter);
        } else {
            $factory->expects(self::once())->method('create')->willThrowException(new RateLimiterUnavailableException());
        }
        $storage = new TokenStorage();
        if ($authenticated) {
            $storage->setToken(new UsernamePasswordToken(new InMemoryUser('user@baander.app', null), 'test', ['ROLE_USER']));
        }
        $listener = new ApiRateLimitListener($factory, $factory, $storage, new NullLogger(), 'test');
        $events = new EventDispatcher();
        if ($accessDenied) {
            $events->addListener(KernelEvents::REQUEST, static function (): never {
                throw new AccessDeniedException();
            }, 8);
        }
        $events->addListener(KernelEvents::REQUEST, $listener->onKernelRequest(...), 7);
        $events->addListener(KernelEvents::EXCEPTION, $listener->onAccessDenied(...), 2);
        $events->addListener(KernelEvents::EXCEPTION, new ExceptionSubscriber(new NullLogger(), new Translator('en')));
        $calls = 0;
        $request = Request::create('/api/example', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $request->attributes->set('_route', $route);
        $request->attributes->set('_controller', static function () use (&$calls): Response {
            ++$calls;

            return new Response(status: 204);
        });
        $response = (new HttpKernel($events, new ControllerResolver(), new RequestStack(), new ArgumentResolver()))->handle($request);

        return [$response, $calls];
    }
}
