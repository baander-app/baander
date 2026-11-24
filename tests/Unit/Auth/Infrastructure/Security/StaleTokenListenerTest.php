<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Infrastructure\Security\StaleTokenListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\HttpKernel\EventListener\ValidateRequestListener;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Firewall;

final class StaleTokenListenerTest extends TestCase
{
    public function testAMainRequestStartsWithoutTheTokenOfAnEarlierRequest(): void
    {
        $storage = self::storageHoldingAToken();

        new StaleTokenListener($storage)->onKernelRequest(self::event(HttpKernelInterface::MAIN_REQUEST));

        self::assertNull($storage->getToken());
    }

    public function testASubRequestKeepsTheTokenOfItsMainRequest(): void
    {
        $storage = self::storageHoldingAToken();
        $token = $storage->getToken();

        new StaleTokenListener($storage)->onKernelRequest(self::event(HttpKernelInterface::SUB_REQUEST));

        self::assertSame($token, $storage->getToken());
    }

    /** @return iterable<string, array{int}> the kernel.request priority of a listener that reads the token or can end the request */
    public static function laterListeners(): iterable
    {
        yield 'request validation' => [ValidateRequestListener::getSubscribedEvents()[KernelEvents::REQUEST][0][1]];
        yield 'the router' => [RouterListener::getSubscribedEvents()[KernelEvents::REQUEST][0][1]];
        yield 'the firewall' => [Firewall::getSubscribedEvents()[KernelEvents::REQUEST][1]];
    }

    #[DataProvider('laterListeners')]
    public function testTheTokenIsClearedBeforeAListenerThatReadsItOrCanEndTheRequest(int $priority): void
    {
        $storage = self::storageHoldingAToken();
        $attribute = new \ReflectionMethod(StaleTokenListener::class, 'onKernelRequest')
            ->getAttributes(AsEventListener::class)[0]
            ->newInstance();
        self::assertSame(KernelEvents::REQUEST, $attribute->event);
        $seen = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            KernelEvents::REQUEST,
            static function () use ($storage, &$seen): void {
                $seen[] = $storage->getToken();
            },
            $priority,
        );
        $dispatcher->addListener(
            KernelEvents::REQUEST,
            new StaleTokenListener($storage)->onKernelRequest(...),
            $attribute->priority,
        );

        $dispatcher->dispatch(self::event(HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);

        self::assertSame([null], $seen);
    }

    private static function storageHoldingAToken(): TokenStorage
    {
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken(
            new SecurityUser('user', 'user@baander.app', ''),
            'api',
            ['ROLE_USER'],
        ));

        return $storage;
    }

    private static function event(int $requestType): RequestEvent
    {
        return new RequestEvent(
            self::createStub(HttpKernelInterface::class),
            Request::create('https://baander.app/api/auth/me'),
            $requestType,
        );
    }
}
