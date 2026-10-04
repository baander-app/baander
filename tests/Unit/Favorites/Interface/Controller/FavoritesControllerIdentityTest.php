<?php

declare(strict_types=1);

namespace App\Tests\Unit\Favorites\Interface\Controller;

use App\Favorites\Application\Port\FavoritesPortInterface;
use App\Favorites\Interface\Controller\FavoritesController;
use App\Favorites\Interface\Request\AddFavoriteRequest;
use App\Shared\Domain\Model\PublicId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

final class FavoritesControllerIdentityTest extends TestCase
{
    /** @return iterable<string, array{'index'|'add'|'remove', UserInterface|null}> */
    public static function missingIdentities(): iterable
    {
        foreach (['index', 'add', 'remove'] as $action) {
            yield $action . ' anonymous' => [$action, null];
            yield $action . ' without application identity' => [$action, new InMemoryUser('favorite-user@baander.app', null)];
        }
    }

    /** @param 'index'|'add'|'remove' $action */
    #[DataProvider('missingIdentities')]
    public function testMissingApplicationIdentityIsRejectedBeforeUseCases(string $action, ?UserInterface $user): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');
        $favorites = $this->createMock(FavoritesPortInterface::class);
        $favorites->expects($this->never())->method('findByUser');
        $favorites->expects($this->never())->method('countByUser');
        $controller = new FavoritesController($security, $bus, $favorites);
        $response = match ($action) {
            'index' => $controller->index(Request::create('/api/favorites/')),
            'add' => $controller->add(new AddFavoriteRequest('song', (new PublicId())->toString())),
            'remove' => $controller->remove((new PublicId())->toString()),
        };

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(401, json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['error']['code']);
    }
}
