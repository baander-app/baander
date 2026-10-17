<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playlist\Interface\Controller;

use App\Playlist\Application\Port\PlaylistSongVisibilityInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Playlist\Application\Command\AddSongCommand;
use App\Playlist\Application\Command\RemoveSongCommand;
use App\Playlist\Application\Port\PlaylistCollaboratorResolverInterface;
use App\Playlist\Application\Port\PlaylistPortInterface;
use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Interface\Controller\PlaylistController;
use App\Playlist\Interface\Request\AddSongRequest;
use App\Playlist\Interface\Request\ReorderSongsRequest;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PlaylistSongIdentityTest extends TestCase
{
    /** @return iterable<string, array{'add'|'remove'|'reorder'}> */
    public static function mutations(): iterable
    {
        yield 'add' => ['add'];
        yield 'remove' => ['remove'];
        yield 'reorder' => ['reorder'];
    }

    #[DataProvider('mutations')]
    public function testInternalUuidIsRejectedBeforeSongLookup(string $mutation): void
    {
        $playlist = Playlist::create('Mix', new Uuid());
        $lookup = $this->createMock(PlaylistSongVisibilityInterface::class);
        $lookup->expects(self::never())->method('findVisibleSongId');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $controller = $this->controller($playlist, $lookup, $bus);

        self::assertSame(400, $this->mutate($controller, $playlist, $mutation, (new Uuid())->toString())->getStatusCode());
    }

    #[DataProvider('mutations')]
    public function testUnavailableSongPublicIdDoesNotMutatePlaylist(string $mutation): void
    {
        $playlist = Playlist::create('Mix', new Uuid());
        $lookup = $this->createMock(PlaylistSongVisibilityInterface::class);
        $lookup->expects(self::once())->method('findVisibleSongId')
            ->with(self::isInstanceOf(PublicId::class), LibraryReadScope::none())->willReturn(null);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $controller = $this->controller($playlist, $lookup, $bus);

        self::assertSame(404, $this->mutate($controller, $playlist, $mutation, (new PublicId())->toString())->getStatusCode());
    }

    public function testAddResolvesPublicIdAndAnswersWithPublicId(): void
    {
        $playlist = Playlist::create('Mix', new Uuid());
        $songId = new Uuid();
        $songPublicId = new PublicId();
        $lookup = $this->createMock(PlaylistSongVisibilityInterface::class);
        $lookup->expects(self::once())->method('findVisibleSongId')->with($songPublicId, LibraryReadScope::none())->willReturn($songId);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn (AddSongCommand $command): bool => $command->getSongId()->equals($songId)))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $response = $this->mutate($this->controller($playlist, $lookup, $bus), $playlist, 'add', $songPublicId->toString());

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['songId' => $songPublicId->toString(), 'position' => 0], json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testRemoveResolvesPublicIdToInternalCommandIdentity(): void
    {
        $playlist = Playlist::create('Mix', new Uuid());
        $songId = new Uuid();
        $songPublicId = new PublicId();
        $lookup = $this->createMock(PlaylistSongVisibilityInterface::class);
        $lookup->expects(self::once())->method('findVisibleSongId')->with($songPublicId, LibraryReadScope::none())->willReturn($songId);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn (RemoveSongCommand $command): bool => $command->getSongId()->equals($songId)))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        self::assertSame(204, $this->mutate($this->controller($playlist, $lookup, $bus), $playlist, 'remove', $songPublicId->toString())->getStatusCode());
    }

    public function testReorderResolvesPublicIdsBeforeSaving(): void
    {
        $playlist = Playlist::create('Mix', new Uuid());
        $first = new Uuid();
        $second = new Uuid();
        $playlist->addSong($first, 0);
        $playlist->addSong($second, 1);
        $lookup = $this->createMock(PlaylistSongVisibilityInterface::class);
        $lookup->expects(self::once())->method('findVisibleSongId')->willReturn($second);
        $controller = $this->controller($playlist, $lookup, $this->createStub(MessageBusInterface::class), true);

        self::assertSame(200, $this->mutate($controller, $playlist, 'reorder', (new PublicId())->toString())->getStatusCode());
        self::assertTrue($playlist->getSongs()[0]->getSongId()->equals($second));
        self::assertTrue($playlist->getSongs()[1]->getSongId()->equals($first));
    }

    private function controller(Playlist $playlist, PlaylistSongVisibilityInterface $lookup, MessageBusInterface $bus, bool $save = false): PlaylistController
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn(true);
        $playlists = $this->createMock(PlaylistPortInterface::class);
        $playlists->method('findByPublicId')->willReturn($playlist);
        $playlists->expects($save ? self::once() : self::never())->method('save');
        $scopes = $this->createStub(LibraryReadScopeProviderInterface::class);
        $scopes->method('current')->willReturn(LibraryReadScope::none());
        $controller = new PlaylistController($security, $playlists, $this->createStub(SongPortInterface::class), $bus, $scopes, $this->createStub(PlaylistCollaboratorResolverInterface::class), $lookup);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $controller->setTranslator($translator);

        return $controller;
    }

    private function mutate(PlaylistController $controller, Playlist $playlist, string $mutation, string $songId): JsonResponse
    {
        return match ($mutation) {
            'add' => $controller->addSong($playlist->getPublicId()->toString(), new AddSongRequest($songId)),
            'remove' => $controller->removeSong($playlist->getPublicId()->toString(), $songId),
            'reorder' => $controller->reorder($playlist->getPublicId()->toString(), new ReorderSongsRequest([$songId])),
            default => throw new \LogicException('Unknown playlist mutation fixture.'),
        };
    }
}
