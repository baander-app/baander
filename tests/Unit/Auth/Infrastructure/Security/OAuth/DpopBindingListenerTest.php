<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\OAuth;

use App\Auth\Application\Port\DpopJtiCacheInterface;
use App\Auth\Infrastructure\Security\OAuth\DpopBindingListener;
use App\Auth\Infrastructure\Security\OAuth\DpopProofValidator;
use App\Auth\Infrastructure\Security\SecurityUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class DpopBindingListenerTest extends TestCase
{
    #[DataProvider('protectedPaths')]
    public function testAuthenticatedUserCannotBypassProofValidation(string $path): void
    {
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken(new SecurityUser('user', 'user@example.test', ''), 'api', ['ROLE_USER']));
        $listener = new DpopBindingListener(
            $storage,
            new DpopProofValidator($this->createStub(DpopJtiCacheInterface::class)),
            new NullLogger(),
        );
        $request = Request::create($path);
        $request->headers->set('Authorization', 'Bearer invalid-token');

        $this->expectException(AccessDeniedException::class);
        $listener->onKernelRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));
    }

    /** @return iterable<array{string}> */
    public static function protectedPaths(): iterable
    {
        yield ['/api/catalog/songs'];
        yield ['/api/transcode/sessions/example'];
        yield ['/api/transcode/jobs/cleanup'];
        yield ['/api/transcode/example/quality-ladder'];
    }
}
