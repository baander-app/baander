<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playlist\Application\CommandHandler;

use App\Playlist\Application\Command\RemoveCollaboratorCommand;
use App\Playlist\Application\CommandHandler\RemoveCollaboratorHandler;
use App\Playlist\Application\Port\PlaylistCollaboratorResolverInterface;
use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Domain\Repository\PlaylistRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** Removing a collaborator drops their row; removing a user who has none changes nothing. */
final class RemoveCollaboratorHandlerTest extends TestCase
{
    private PlaylistRepositoryInterface&MockObject $repository;
    private PlaylistCollaboratorResolverInterface&MockObject $users;
    private RemoveCollaboratorHandler $handler;
    private Playlist $playlist;
    private Uuid $collaboratorId;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(PlaylistRepositoryInterface::class);
        $this->users = $this->createMock(PlaylistCollaboratorResolverInterface::class);
        $this->handler = new RemoveCollaboratorHandler($this->repository, $this->users);
        $this->playlist = Playlist::create('Shared', Uuid::v4());
        $this->collaboratorId = Uuid::v4();
        $this->playlist->addCollaborator($this->collaboratorId);
    }

    public function testACollaboratorIsRemovedAndThePlaylistIsSaved(): void
    {
        $publicId = new PublicId();
        $this->users->expects($this->once())->method('userId')->with($publicId)->willReturn($this->collaboratorId);
        $this->repository->expects($this->once())->method('findByUuid')->with($this->playlist->getId())->willReturn($this->playlist);
        $this->repository->expects($this->once())->method('save')->with($this->playlist);

        ($this->handler)(new RemoveCollaboratorCommand($this->playlist->getId(), $publicId));

        $this->assertFalse($this->playlist->isCollaborator($this->collaboratorId));
        $this->assertSame([], $this->playlist->collaboratorIds());
    }

    public function testAUserWhoIsNotACollaboratorLeavesThePlaylistUnchanged(): void
    {
        $publicId = new PublicId();
        $before = $this->playlist->getUpdatedAt();
        $this->users->expects($this->once())->method('userId')->with($publicId)->willReturn(Uuid::v4());
        $this->repository->method('findByUuid')->willReturn($this->playlist);
        $this->repository->expects($this->never())->method('save');

        ($this->handler)(new RemoveCollaboratorCommand($this->playlist->getId(), $publicId));

        $this->assertTrue($this->playlist->isCollaborator($this->collaboratorId));
        $this->assertSame($before, $this->playlist->getUpdatedAt());
    }

    public function testAnUnknownPublicIdIsNotFoundBeforeThePlaylistIsLoaded(): void
    {
        $publicId = new PublicId();
        $this->users->expects($this->once())->method('userId')->with($publicId)->willThrowException(new NotFoundException('User not found.'));
        $this->repository->expects($this->never())->method('findByUuid');
        $this->repository->expects($this->never())->method('save');

        $this->expectException(NotFoundException::class);

        ($this->handler)(new RemoveCollaboratorCommand($this->playlist->getId(), $publicId));
    }
}
