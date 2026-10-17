<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playlist\Application\CommandHandler;

use App\Playlist\Application\Command\ReorderSongsCommand;
use App\Playlist\Application\CommandHandler\ReorderSongsHandler;
use App\Playlist\Application\Exception\PlaylistWriteConflictException;
use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Domain\Repository\PlaylistRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ReorderSongsHandlerTest extends TestCase
{
    public function testReordersSubsetAndReturnsThePersistedRevision(): void
    {
        $owner = new Uuid();
        $playlist = Playlist::create('Mix', $owner);
        $first = new Uuid();
        $second = new Uuid();
        $playlist->addSong($first, 0);
        $playlist->addSong($second, 1);
        $playlist->markPersisted(1);
        $before = $playlist->getRevision();
        $repository = $this->createMock(PlaylistRepositoryInterface::class);
        $repository->method('findByUuid')->willReturn($playlist);
        $repository->expects(self::once())->method('save')->willReturnCallback(static function (Playlist $saved): void { $saved->markPersisted(2); });

        $revision = (new ReorderSongsHandler($repository))(new ReorderSongsCommand($playlist->getId(), $owner, [$second], $before));

        self::assertTrue($playlist->getSongs()[0]->getSongId()->equals($second));
        self::assertTrue($playlist->getSongs()[1]->getSongId()->equals($first));
        self::assertNotSame($before, $revision);
        self::assertSame($playlist->getRevision(), $revision);
    }

    public function testStaleClientDoesNotWrite(): void
    {
        $owner = new Uuid();
        $playlist = Playlist::create('Mix', $owner);
        $repository = $this->createMock(PlaylistRepositoryInterface::class);
        $repository->method('findByUuid')->willReturn($playlist);
        $repository->expects(self::never())->method('save');
        $this->expectException(PlaylistWriteConflictException::class);

        (new ReorderSongsHandler($repository))(new ReorderSongsCommand($playlist->getId(), $owner, [], str_repeat('0', 64)));
    }

    public function testLostPermissionIsRejectedBeforeStaleRevision(): void
    {
        $playlist = Playlist::create('Mix', new Uuid());
        $actor = new Uuid();
        $playlist->addCollaborator($actor);
        $repository = $this->createMock(PlaylistRepositoryInterface::class);
        $repository->method('findByUuid')->willReturn($playlist);
        $repository->expects(self::never())->method('save');
        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Only the owner, or a collaborator while the playlist is collaborative, may change its songs.');

        (new ReorderSongsHandler($repository))(new ReorderSongsCommand($playlist->getId(), $actor, [], str_repeat('0', 64)));
    }

    public function testRemovedCollaboratorCannotReorderWithAnOldRevision(): void
    {
        $playlist = Playlist::create('Mix', new Uuid(), isCollaborative: true);
        $actor = new Uuid();
        $playlist->addCollaborator($actor);
        $playlist->markPersisted(1);
        $revision = $playlist->getRevision();
        $playlist->removeCollaborator($actor);
        $playlist->markPersisted(2);
        $repository = $this->createMock(PlaylistRepositoryInterface::class);
        $repository->expects(self::once())->method('findByUuid')->willReturn($playlist);
        $repository->expects(self::never())->method('save');
        $this->expectException(AccessDeniedException::class);

        (new ReorderSongsHandler($repository))(new ReorderSongsCommand($playlist->getId(), $actor, [], $revision));
    }

    public function testCollaboratorCanReorderWhileCollaborative(): void
    {
        $playlist = Playlist::create('Mix', new Uuid(), isCollaborative: true);
        $actor = new Uuid();
        $playlist->addCollaborator($actor);
        $song = new Uuid();
        $playlist->addSong($song, 0);
        $repository = $this->createMock(PlaylistRepositoryInterface::class);
        $repository->method('findByUuid')->willReturn($playlist);
        $repository->expects(self::once())->method('save');

        self::assertSame($playlist->getRevision(), (new ReorderSongsHandler($repository))(new ReorderSongsCommand($playlist->getId(), $actor, [$song], $playlist->getRevision())));
    }

    public function testConflictDuringSaveIsNotRetried(): void
    {
        $owner = new Uuid();
        $playlist = Playlist::create('Mix', $owner);
        $repository = $this->createMock(PlaylistRepositoryInterface::class);
        $repository->expects(self::once())->method('findByUuid')->willReturn($playlist);
        $repository->expects(self::once())->method('save')->willThrowException(new PlaylistWriteConflictException());
        $this->expectException(PlaylistWriteConflictException::class);

        (new ReorderSongsHandler($repository))(new ReorderSongsCommand($playlist->getId(), $owner, [], $playlist->getRevision()));
    }

    public function testMissingPlaylistIsNotFound(): void
    {
        $repository = $this->createMock(PlaylistRepositoryInterface::class);
        $repository->method('findByUuid')->willReturn(null);
        $repository->expects(self::never())->method('save');
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Playlist not found.');

        (new ReorderSongsHandler($repository))(new ReorderSongsCommand(new Uuid(), new Uuid(), [], str_repeat('0', 64)));
    }
}
