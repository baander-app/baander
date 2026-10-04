<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playlist\Interface\Controller;

use App\Catalog\Application\Port\SongPortInterface;
use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Playlist\Application\Port\PlaylistPortInterface;
use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Interface\Controller\PlaylistController;
use App\Playlist\Interface\Request\CreatePlaylistRequest;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

final class PlaylistIdentityTest extends TestCase
{
    public function testUnsupportedPrincipalCannotListOrCreatePlaylists(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createStub(UserInterface::class));
        $playlists = $this->createMock(PlaylistPortInterface::class);
        $playlists->expects($this->never())->method('findReadByUser');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');
        $controller = new PlaylistController(
            $security,
            $playlists,
            $this->createStub(SongPortInterface::class),
            $bus,
            $this->createStub(LibraryReadScopeProviderInterface::class),
        );

        self::assertSame(401, $controller->index(new Request())->getStatusCode());
        self::assertSame(401, $controller->store(new CreatePlaylistRequest('Playlist'))->getStatusCode());
    }

    public function testDeniedPlaylistDoesNotFetchSongMetadata(): void
    {
        $playlist = Playlist::create('Private playlist', new Uuid());
        $security = $this->createMock(Security::class);
        $security->expects(self::once())->method('isGranted')->with('VIEW', $playlist)->willReturn(false);
        $playlists = $this->createMock(PlaylistPortInterface::class);
        $playlists->expects(self::once())->method('findByPublicId')->with($playlist->getPublicId())->willReturn($playlist);
        $songs = $this->createMock(SongPortInterface::class);
        $songs->expects(self::never())->method('findVisibleByUuids');
        $songs->expects(self::never())->method('getVisibleArtistNamesForSongs');
        $songs->expects(self::never())->method('getVisibleAlbumTitlesByIds');
        $scopes = $this->createMock(LibraryReadScopeProviderInterface::class);
        $scopes->expects(self::never())->method('current');
        $controller = new PlaylistController(
            $security,
            $playlists,
            $songs,
            $this->createStub(MessageBusInterface::class),
            $scopes,
        );

        $this->expectException(AccessDeniedException::class);
        $controller->show($playlist->getPublicId()->toString());
    }
}
