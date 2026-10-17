<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playlist\Application\CommandHandler;

use App\Playlist\Application\Command\AddCollaboratorCommand;
use App\Playlist\Application\CommandHandler\AddCollaboratorHandler;
use App\Playlist\Application\Exception\PlaylistWriteConflictException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use App\Playlist\Application\Port\PlaylistCollaboratorResolverInterface;
use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Domain\Repository\PlaylistRepositoryInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** The owner names a collaborator by public id; the aggregate takes the row and the repository writes it. */
final class AddCollaboratorHandlerTest extends TestCase
{
    private PlaylistRepositoryInterface&MockObject $repository;
    private PlaylistCollaboratorResolverInterface&MockObject $users;
    private AddCollaboratorHandler $handler;
    private Playlist $playlist;
    private Uuid $ownerId;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(PlaylistRepositoryInterface::class);
        $this->users = $this->createMock(PlaylistCollaboratorResolverInterface::class);
        $this->handler = new AddCollaboratorHandler($this->repository, $this->users);
        $this->ownerId = Uuid::v4();
        $this->playlist = Playlist::create('Shared', $this->ownerId);
    }

    public function testAKnownUserBecomesACollaboratorAndThePlaylistIsSaved(): void
    {
        $publicId = new PublicId();
        $userId = Uuid::v4();
        $this->users->expects($this->once())->method('userId')->with($publicId)->willReturn($userId);
        $this->repository->expects($this->once())->method('findByUuid')->with($this->playlist->getId())->willReturn($this->playlist);
        $this->repository->expects($this->once())->method('save')->with($this->playlist);

        ($this->handler)(new AddCollaboratorCommand($this->playlist->getId(), $publicId, $this->ownerId));

        $this->assertTrue($this->playlist->isCollaborator($userId));
        $this->assertCount(1, $this->playlist->collaboratorIds());
    }

    public function testAddingACollaboratorAgainChangesNothingAndWritesNothing(): void
    {
        $publicId = new PublicId();
        $userId = Uuid::v4();
        $this->playlist->addCollaborator($userId);
        $before = $this->playlist->getUpdatedAt();
        $this->users->expects($this->once())->method('userId')->with($publicId)->willReturn($userId);
        $this->repository->method('findByUuid')->willReturn($this->playlist);
        $this->repository->expects($this->never())->method('save');

        ($this->handler)(new AddCollaboratorCommand($this->playlist->getId(), $publicId, $this->ownerId));

        $this->assertCount(1, $this->playlist->collaboratorIds());
        $this->assertSame($before, $this->playlist->getUpdatedAt());
    }

    public function testAnUnknownPublicIdIsNotFoundAfterTheOwnerIsAuthorized(): void
    {
        $publicId = new PublicId();
        $this->users->expects($this->once())->method('userId')->with($publicId)->willThrowException(new NotFoundException('User not found.'));
        $this->repository->expects($this->once())->method('findByUuid')->willReturn($this->playlist);
        $this->repository->expects($this->never())->method('save');

        $this->expectException(NotFoundException::class);

        ($this->handler)(new AddCollaboratorCommand($this->playlist->getId(), $publicId, $this->ownerId));
    }

    public function testTheOwnerCannotNameThemself(): void
    {
        $publicId = new PublicId();
        $this->users->expects($this->once())->method('userId')->with($publicId)->willReturn($this->ownerId);
        $this->repository->method('findByUuid')->willReturn($this->playlist);
        $this->repository->expects($this->never())->method('save');

        try {
            ($this->handler)(new AddCollaboratorCommand($this->playlist->getId(), $publicId, $this->ownerId));
            $this->fail('The owner must not become their own collaborator.');
        } catch (InvalidInputException $exception) {
            $this->assertSame('The playlist owner cannot be added as a collaborator.', $exception->getMessage());
            $this->assertSame(['userPublicId' => $publicId->toString()], $exception->details);
        }

        $this->assertSame([], $this->playlist->collaboratorIds());
    }
    public function testRetryKeepsTheWinningCollaborator(): void
    {
        $publicId = new PublicId();
        $requested = Uuid::v4();
        $winner = Uuid::v4();
        $fresh = clone $this->playlist;
        $fresh->addCollaborator($winner);
        $this->users->expects($this->once())->method('userId')->willReturn($requested);
        $this->repository->expects($this->exactly(2))->method('findByUuid')->willReturnOnConsecutiveCalls($this->playlist, $fresh);
        $calls = 0;
        $this->repository->expects($this->exactly(2))->method('save')->willReturnCallback(static function (Playlist $playlist) use (&$calls, $winner, $requested): void {
            if (++$calls === 1) {
                throw new PlaylistWriteConflictException();
            }
            self::assertTrue($playlist->isCollaborator($winner));
            self::assertTrue($playlist->isCollaborator($requested));
        });

        ($this->handler)(new AddCollaboratorCommand($this->playlist->getId(), $publicId, $this->ownerId));
    }

    public function testConcurrentDuplicateBecomesANoWriteSuccess(): void
    {
        $requested = Uuid::v4();
        $fresh = clone $this->playlist;
        $fresh->addCollaborator($requested);
        $this->users->expects($this->once())->method('userId')->willReturn($requested);
        $this->repository->expects($this->exactly(2))->method('findByUuid')->willReturnOnConsecutiveCalls($this->playlist, $fresh);
        $this->repository->expects($this->once())->method('save')->willThrowException(new PlaylistWriteConflictException());

        ($this->handler)(new AddCollaboratorCommand($this->playlist->getId(), new PublicId(), $this->ownerId));
        self::assertCount(1, $fresh->collaboratorIds());
    }

    public function testRetryExhaustionReturnsConflict(): void
    {
        $this->users->expects($this->once())->method('userId')->willReturn(Uuid::v4());
        $this->repository->expects($this->exactly(3))->method('findByUuid')->willReturnCallback(fn (): Playlist => clone $this->playlist);
        $this->repository->expects($this->exactly(3))->method('save')->willThrowException(new PlaylistWriteConflictException());
        $this->expectException(PlaylistWriteConflictException::class);

        ($this->handler)(new AddCollaboratorCommand($this->playlist->getId(), new PublicId(), $this->ownerId));
    }

    public function testCurrentNonOwnerIsRefusedBeforeUserLookup(): void
    {
        $this->repository->expects($this->once())->method('findByUuid')->willReturn($this->playlist);
        $this->repository->expects($this->never())->method('save');
        $this->users->expects($this->never())->method('userId');
        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Only the owner may manage the collaborators of this playlist.');

        ($this->handler)(new AddCollaboratorCommand($this->playlist->getId(), new PublicId(), Uuid::v4()));
    }

    public function testOwnershipIsRecheckedBeforeRetry(): void
    {
        $this->users->expects($this->once())->method('userId')->willReturn(Uuid::v4());
        $fresh = Playlist::create('Transferred', Uuid::v4());
        $this->repository->expects($this->exactly(2))->method('findByUuid')->willReturnOnConsecutiveCalls($this->playlist, $fresh);
        $this->repository->expects($this->once())->method('save')->willThrowException(new PlaylistWriteConflictException());
        $this->expectException(AccessDeniedException::class);

        ($this->handler)(new AddCollaboratorCommand($this->playlist->getId(), new PublicId(), $this->ownerId));
    }

    public function testAnUnrelatedConflictIsNotRetried(): void
    {
        $this->users->expects($this->once())->method('userId')->willReturn(Uuid::v4());
        $this->repository->expects($this->once())->method('findByUuid')->willReturn($this->playlist);
        $this->repository->expects($this->once())->method('save')->willThrowException(new ConflictException('An unrelated conflict.'));
        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('An unrelated conflict.');

        ($this->handler)(new AddCollaboratorCommand($this->playlist->getId(), new PublicId(), $this->ownerId));
    }

    public function testMissingPlaylistIsNotFoundWithoutResolvingTheUser(): void
    {
        $this->repository->expects($this->once())->method('findByUuid')->willReturn(null);
        $this->users->expects($this->never())->method('userId');
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Playlist not found.');

        ($this->handler)(new AddCollaboratorCommand($this->playlist->getId(), new PublicId(), $this->ownerId));
    }

}
