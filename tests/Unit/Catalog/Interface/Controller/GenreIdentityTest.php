<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Controller;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Genre;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Interface\Controller\GenreController;
use App\Catalog\Interface\Request\GenreAlbumRequest;
use App\Catalog\Interface\Request\GenreSongRequest;
use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;

final class GenreIdentityTest extends TestCase
{
    /** @return iterable<string, array{'song'|'album', bool}> */
    public static function mutations(): iterable
    {
        yield 'add song' => ['song', true];
        yield 'remove song' => ['song', false];
        yield 'add album' => ['album', true];
        yield 'remove album' => ['album', false];
    }

    #[DataProvider('mutations')]
    public function testInternalUuidCannotAddressMutationTarget(string $kind, bool $add): void
    {
        $genres = $this->createMock(GenrePortInterface::class);
        $genres->method('findBySlug')->willReturn(Genre::create('Rock', 'rock'));
        $method = $add ? 'add' . ucfirst($kind) . 'ToGenre' : 'remove' . ucfirst($kind) . 'FromGenre';
        $genres->expects(self::never())->method($method);
        $songs = $this->createMock(SongPortInterface::class);
        $songs->expects(self::never())->method('findVisibleByPublicId');
        $albums = $this->createMock(AlbumPortInterface::class);
        $albums->expects(self::never())->method('findVisibleByPublicId');

        self::assertSame(400, $this->mutate($this->controller($genres, $songs, $albums), $kind, $add, (new Uuid())->toString())->getStatusCode());
    }

    #[DataProvider('mutations')]
    public function testPublicTargetResolvesWithinScopeBeforeMutation(string $kind, bool $add): void
    {
        $genre = Genre::create('Rock', 'rock');
        $album = Album::create(new Uuid(), 'Album', 'studio');
        $target = $kind === 'song' ? Song::create($album->getId(), 'Track', '/media/track.flac', 10, 'audio/flac') : $album;
        $genres = $this->createMock(GenrePortInterface::class);
        $genres->method('findBySlug')->willReturn($genre);
        $method = $add ? 'add' . ucfirst($kind) . 'ToGenre' : 'remove' . ucfirst($kind) . 'FromGenre';
        $genres->expects(self::once())->method($method)->with($genre->getId(), $target->getId())->willReturn(true);
        $songs = $this->createStub(SongPortInterface::class);
        $albums = $this->createStub(AlbumPortInterface::class);
        if ($kind === 'song') {
            $lookup = $songs = $this->createMock(SongPortInterface::class);
        } else {
            $lookup = $albums = $this->createMock(AlbumPortInterface::class);
        }
        $lookup->expects(self::once())->method('findVisibleByPublicId')
            ->with($target->getPublicId(), LibraryReadScope::none())->willReturn($target);

        self::assertSame(204, $this->mutate($this->controller($genres, $songs, $albums), $kind, $add, $target->getPublicId()->toString())->getStatusCode());
    }

    #[DataProvider('mutations')]
    public function testInaccessiblePublicTargetCannotBeMutated(string $kind, bool $add): void
    {
        $genres = $this->createMock(GenrePortInterface::class);
        $genres->method('findBySlug')->willReturn(Genre::create('Rock', 'rock'));
        $method = $add ? 'add' . ucfirst($kind) . 'ToGenre' : 'remove' . ucfirst($kind) . 'FromGenre';
        $genres->expects(self::never())->method($method);
        $songs = $this->createStub(SongPortInterface::class);
        $albums = $this->createStub(AlbumPortInterface::class);
        if ($kind === 'song') {
            $lookup = $songs = $this->createMock(SongPortInterface::class);
        } else {
            $lookup = $albums = $this->createMock(AlbumPortInterface::class);
        }
        $lookup->expects(self::once())->method('findVisibleByPublicId')
            ->with(self::isInstanceOf(PublicId::class), LibraryReadScope::none())->willReturn(null);

        self::assertSame(404, $this->mutate($this->controller($genres, $songs, $albums), $kind, $add, (new PublicId())->toString())->getStatusCode());
    }

    private function controller(GenrePortInterface $genres, SongPortInterface $songs, AlbumPortInterface $albums): GenreController
    {
        $scope = $this->createStub(LibraryReadScopeProviderInterface::class);
        $scope->method('current')->willReturn(LibraryReadScope::none());

        return new GenreController($genres, $scope, $this->createStub(MessageBusInterface::class), $songs, $albums);
    }

    private function mutate(GenreController $controller, string $kind, bool $add, string $id): JsonResponse
    {
        return match ([$kind, $add]) {
            ['song', true] => $controller->addSong('rock', new GenreSongRequest($id)),
            ['song', false] => $controller->removeSong('rock', $id),
            ['album', true] => $controller->addAlbum('rock', new GenreAlbumRequest($id)),
            ['album', false] => $controller->removeAlbum('rock', $id),
            default => throw new \LogicException('Unknown genre mutation fixture.'),
        };
    }
}
