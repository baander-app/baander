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
        $this->repository->expects($this->once())->method('findWithSongs')->with($this->playlist->getId())->willReturn($this->playlist);
    }

    public function testAVisibleSongIsAppended(): void
    {
        $songId = Uuid::v4();
        $this->visibility->expects($this->once())->method('isVisible')->with($songId, $this->scope)->willReturn(true);
        $this->repository->expects($this->once())->method('save')->with($this->playlist);

        $result = ($this->handler)(new AddSongCommand($this->playlist->getId(), $songId, 1, $this->scope));

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
            ($this->handler)(new AddSongCommand($this->playlist->getId(), $songId, 1, $this->scope));
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

        $result = ($this->handler)(new AddSongCommand($this->playlist->getId(), $present, 1, $this->scope));

        $this->assertSame($this->playlist, $result);
        $this->assertCount(1, $this->playlist->getSongs());
    }
}
