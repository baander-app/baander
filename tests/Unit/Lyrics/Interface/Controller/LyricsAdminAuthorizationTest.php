<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lyrics\Interface\Controller;

use App\Lyrics\Interface\Controller\LyricsAdminController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\Voter\RoleVoter;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\EventListener\IsGrantedAttributeListener;

final class LyricsAdminAuthorizationTest extends TestCase
{
    /** @return iterable<string, array{list<string>, bool}> */
    public static function roles(): iterable
    {
        yield 'user' => [['ROLE_USER'], false];
        yield 'admin' => [['ROLE_USER', 'ROLE_ADMIN'], false];
        yield 'superadmin' => [['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], true];
    }

    /** @param list<string> $roles */
    #[DataProvider('roles')]
    public function testBulkFetchRequiresSuperadminRole(array $roles, bool $allowed): void
    {
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken(
            new InMemoryUser('user', null, $roles),
            'api',
            $roles,
        ));

        $listener = new IsGrantedAttributeListener(
            new AuthorizationChecker($storage, new AccessDecisionManager([new RoleVoter()])),
        );

        $controller = (new \ReflectionClass(LyricsAdminController::class))->newInstanceWithoutConstructor();
        $event = new ControllerArgumentsEvent(
            $this->createStub(HttpKernelInterface::class),
            [$controller, 'bulkFetch'],
            [],
            Request::create('/api/admin/lyrics/bulk-fetch', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
        );

        if (!$allowed) {
            $this->expectException(AccessDeniedException::class);
        }

        $listener->onKernelControllerArguments($event);

        self::assertTrue($allowed);
    }
}
