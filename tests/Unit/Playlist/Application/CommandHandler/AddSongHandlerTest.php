<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playlist\Application\CommandHandler;

use App\Playlist\Application\Command\AddSongCommand;
use App\Playlist\Application\CommandHandler\AddSongHandler;
use App\Playlist\Application\Port\PlaylistSongVisibilityInterface;
use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Domain\Repository\PlaylistRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AddSongHandlerTest extends TestCase
{
    private PlaylistRepositoryInterface&MockObject $repository;
    private PlaylistSongVisibilityInterface&MockObject $visibility;
    private AddSongHandler $handler;
    private Playlist $playlist;
    private LibraryReadScope $scope;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(PlaylistRepositoryInterface::class);
        $this->visibility = $this->createMock(PlaylistSongVisibilityInterface::class);
        $this->handler = new AddSongHandler($this->repository, $this->visibility);
        $this->playlist = Playlist::create('Shared', Uuid::v4());
        $this->playlist->addSong(Uuid::v4(), 0);
        $this->scope = LibraryReadScope::restricted([Uuid::v4()]);
        $this->repository->expects($this->once())->method('findByUuid')->with($this->playlist->getId())->willReturn($this->playlist);
    }

    public function testAVisibleSongIsAppended(): void
    {
        $songId = Uuid::v4();
        $this->visibility->expects($this->once())->method('isVisible')->with($songId, $this->scope)->willReturn(true);
        $this->repository->expects($this->once())->method('save')->with($this->playlist);

        $result = ($this->handler)(new AddSongCommand($this->playlist->getId(), $songId, $this->playlist->getUserId(), $this->scope));

        $this->assertSame($this->playlist, $result);
        $this->assertCount(2, $this->playlist->getSongs());
        $this->assertTrue($this->playlist->getSongs()[1]->getSongId()->equals($songId));
        $this->assertSame(1, $this->playlist->getSongs()[1]->getPosition());
    }

    public function testAnInvisibleOrUnknownSongIsNotFoundAndNothingIsWritten(): void
    {
        $songId = Uuid::v4();
        $this->visibility->expects($this->once())->method('isVisible')->with($songId, $this->scope)->willReturn(false);
        $this->repository->expects($this->never())->method('save');

        try {
            ($this->handler)(new AddSongCommand($this->playlist->getId(), $songId, $this->playlist->getUserId(), $this->scope));
            $this->fail('An invisible song must not be added.');
        } catch (NotFoundException $exception) {
            $this->assertSame([], $exception->details);
        }

        $this->assertCount(1, $this->playlist->getSongs());
    }

    public function testASongAlreadyPresentLeavesThePlaylistUnchanged(): void
    {
        $present = $this->playlist->getSongs()[0]->getSongId();
        $this->visibility->expects($this->once())->method('isVisible')->with($present, $this->scope)->willReturn(true);
        $this->repository->expects($this->never())->method('save');

        $result = ($this->handler)(new AddSongCommand($this->playlist->getId(), $present, $this->playlist->getUserId(), $this->scope));

        $this->assertSame($this->playlist, $result);
        $this->assertCount(1, $this->playlist->getSongs());
    }

    public function testCurrentCollaboratorCanAppendWhileCollaborationIsEnabled(): void
    {
        $actor = new Uuid();
        $this->playlist->updateMetadata('Shared', null, false, true);
        $this->playlist->addCollaborator($actor);
        $song = new Uuid();
        $this->visibility->expects(self::once())->method('isVisible')->with($song, $this->scope)->willReturn(true);
        $this->repository->expects(self::once())->method('save');

        $result = ($this->handler)(new AddSongCommand($this->playlist->getId(), $song, $actor, $this->scope));

        self::assertSame($song, $result->getSongs()[1]->getSongId());
    }

    /** @return iterable<string, array{bool, bool, bool}> */
    public static function deniedActors(): iterable
    {
        yield 'removed collaborator' => [true, false, false];
        yield 'collaboration disabled' => [false, true, false];
        yield 'removed collaborator repeats existing song' => [true, false, true];
        yield 'disabled collaborator repeats existing song' => [false, true, true];
    }

    #[DataProvider('deniedActors')]
    public function testCurrentAuthorityIsRequiredBeforeVisibilityOrDuplicateHandling(bool $collaborative, bool $member, bool $duplicate): void
    {
        $actor = new Uuid();
        $this->playlist->updateMetadata('Shared', null, false, $collaborative);
        if ($member) {
            $this->playlist->addCollaborator($actor);
        }
        $song = $duplicate ? $this->playlist->getSongs()[0]->getSongId() : new Uuid();
        $this->visibility->expects(self::never())->method('isVisible');
        $this->repository->expects(self::never())->method('save');
        $this->expectException(AccessDeniedException::class);

        ($this->handler)(new AddSongCommand($this->playlist->getId(), $song, $actor, $this->scope));
    }

}
