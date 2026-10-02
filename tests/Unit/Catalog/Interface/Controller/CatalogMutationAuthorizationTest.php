<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Controller;

use App\Catalog\Interface\Controller\AlbumController;
use App\Catalog\Interface\Controller\MovieController;
use App\Catalog\Interface\Controller\SongController;
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

final class CatalogMutationAuthorizationTest extends TestCase
{
    /** @return iterable<string, array{class-string, string, string, bool}> */
    public static function mutations(): iterable
    {
        foreach ([AlbumController::class, MovieController::class, SongController::class] as $class) {
            $actions = ['update' => 'PATCH', 'destroy' => 'DELETE'];
            if ($class === AlbumController::class) {
                $actions['merge'] = 'POST';
            }
            foreach ($actions as $action => $method) {
                foreach ([false, true] as $admin) {
                    yield $class . ':' . $action . ':' . (int) $admin => [$class, $action, $method, $admin];
                }
            }
        }
    }

    /** @param class-string $class */
    #[DataProvider('mutations')]
    public function testMutationsRequireAdministrator(string $class, string $action, string $method, bool $admin): void
    {
        $roles = $admin ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER'];
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken(new InMemoryUser('user', null, $roles), 'api', $roles));
        $listener = new IsGrantedAttributeListener(new AuthorizationChecker($storage, new AccessDecisionManager([new RoleVoter()])));
        $controller = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $event = new ControllerArgumentsEvent(
            $this->createStub(HttpKernelInterface::class),
            [$controller, $action],
            [],
            Request::create('/api/resource', $method),
            HttpKernelInterface::MAIN_REQUEST,
        );
        if (!$admin) {
            $this->expectException(AccessDeniedException::class);
        }
        $listener->onKernelControllerArguments($event);
        self::assertTrue($admin);
    }
}
