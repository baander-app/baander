<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playlist\Interface\Controller;

use App\Catalog\Application\Port\SongPortInterface;
use App\Playlist\Application\Port\PlaylistPortInterface;
use App\Playlist\Interface\Controller\PlaylistController;
use App\Playlist\Interface\Request\CreatePlaylistRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class PlaylistIdentityTest extends TestCase
{
    public function testUnsupportedPrincipalCannotListOrCreatePlaylists(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createStub(UserInterface::class));
        $playlists = $this->createMock(PlaylistPortInterface::class);
        $playlists->expects($this->never())->method('findByUser');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');
        $controller = new PlaylistController($security, $playlists, $this->createStub(SongPortInterface::class), $bus);

        self::assertSame(401, $controller->index(new Request())->getStatusCode());
        self::assertSame(401, $controller->store(new CreatePlaylistRequest('Playlist'))->getStatusCode());
    }
}
