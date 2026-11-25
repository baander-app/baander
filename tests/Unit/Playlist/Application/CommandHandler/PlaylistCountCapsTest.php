<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playlist\Application\CommandHandler;

use App\Playlist\Application\Command\AddCollaboratorCommand;
use App\Playlist\Application\Command\AddSongCommand;
use App\Playlist\Application\Command\CreatePlaylistCommand;
use App\Playlist\Application\CommandHandler\AddCollaboratorHandler;
use App\Playlist\Application\CommandHandler\AddSongHandler;
use App\Playlist\Application\CommandHandler\CreatePlaylistHandler;
use App\Playlist\Application\Exception\PlaylistLimitReachedException;
use App\Playlist\Application\Port\PlaylistCollaboratorResolverInterface;
use App\Playlist\Application\Port\PlaylistSongVisibilityInterface;
use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Domain\Model\PlaylistSong;
use App\Playlist\Domain\Repository\PlaylistRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class PlaylistCountCapsTest extends TestCase
{
    private bool $inTransaction = false;

    public function testCreationCountsTheOwnersPlaylistsUnderTheOwnersLockInsideTheTransaction(): void
    {
        $owner = Uuid::v4();
        $calls = [];
        $repository = $this->createMock(PlaylistRepositoryInterface::class);
        $repository->expects(self::once())->method('lockOwnerPlaylists')->with($owner)->willReturnCallback(
            function () use (&$calls): void {
                self::assertTrue($this->inTransaction, 'The lock must be taken inside the creating transaction.');
                $calls[] = 'lock';
            },
        );
        $repository->expects(self::once())->method('countOwnedBy')->with($owner)->willReturnCallback(
            static function () use (&$calls): int {
                $calls[] = 'count';

                return 999;
            },
        );
        $repository->expects(self::once())->method('save')->willReturnCallback(
            function () use (&$calls): void {
                self::assertTrue($this->inTransaction);
                $calls[] = 'save';
            },
        );

        $this->creator($repository)(new CreatePlaylistCommand('The thousandth', $owner));

        self::assertSame(['lock', 'count', 'save'], $calls);
    }

    public function testTheThousandAndFirstPlaylistIsRefused(): void
    {
        $repository = $this->createMock(PlaylistRepositoryInterface::class);
        $repository->method('countOwnedBy')->willReturn(1000);
        $repository->expects(self::never())->method('save');

        $this->expectExceptionObject(PlaylistLimitReachedException::playlists());

        $this->creator($repository)(new CreatePlaylistCommand('One too many', Uuid::v4()));
    }

    public function testThe10001stSongIsRefusedAndAnExistingSongStaysIdempotent(): void
    {
        $owner = Uuid::v4();
        $songs = array_map(static fn (int $position): PlaylistSong => new PlaylistSong(Uuid::v4(), $position), range(0, 9999));
        $playlist = $this->stored($owner, songs: $songs);
        $repository = $this->createMock(PlaylistRepositoryInterface::class);
        $repository->method('findByUuid')->willReturn($playlist);
        $repository->expects(self::never())->method('save');
        $visibility = $this->createStub(PlaylistSongVisibilityInterface::class);
        $visibility->method('isVisible')->willReturn(true);
        $handler = new AddSongHandler($repository, $visibility);
        $scope = LibraryReadScope::unrestricted();

        self::assertSame($playlist, $handler(new AddSongCommand($playlist->getId(), $songs[17]->getSongId(), $owner, $scope)));
        try {
            $handler(new AddSongCommand($playlist->getId(), Uuid::v4(), $owner, $scope));
            self::fail('The 10,001st song must be refused.');
        } catch (PlaylistLimitReachedException $refusal) {
            self::assertSame(['reason' => 'song_limit', 'limit' => 10000], $refusal->details);
        }
        self::assertCount(10000, $playlist->getSongs());
    }

    public function testThe51stCollaboratorIsRefusedAndAnExistingOneStaysIdempotent(): void
    {
        $owner = Uuid::v4();
        $collaborators = array_map(static fn (): Uuid => Uuid::v4(), range(1, 50));
        $playlist = $this->stored($owner, collaborators: $collaborators);
        $repository = $this->createMock(PlaylistRepositoryInterface::class);
        $repository->method('findByUuid')->willReturn($playlist);
        $repository->expects(self::never())->method('save');
        $existing = new PublicId();
        $newcomer = new PublicId();
        $users = $this->createStub(PlaylistCollaboratorResolverInterface::class);
        $users->method('userId')->willReturnCallback(
            static fn (PublicId $publicId): Uuid => $publicId->equals($existing) ? $collaborators[0] : Uuid::v4(),
        );
        $handler = new AddCollaboratorHandler($repository, $users);

        $handler(new AddCollaboratorCommand($playlist->getId(), $existing, $owner));
        try {
            $handler(new AddCollaboratorCommand($playlist->getId(), $newcomer, $owner));
            self::fail('The 51st collaborator must be refused.');
        } catch (PlaylistLimitReachedException $refusal) {
            self::assertSame(['reason' => 'collaborator_limit', 'limit' => 50], $refusal->details);
        }
        self::assertCount(50, $playlist->collaboratorIds());
    }

    private function creator(PlaylistRepositoryInterface $repository): CreatePlaylistHandler
    {
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(function (callable $operation): mixed {
            $this->inTransaction = true;
            try {
                return $operation();
            } finally {
                $this->inTransaction = false;
            }
        });

        return new CreatePlaylistHandler($repository, new EventDispatcher(), $transaction);
    }

    /**
     * @param list<PlaylistSong> $songs
     * @param list<Uuid>         $collaborators
     */
    private function stored(Uuid $owner, array $songs = [], array $collaborators = []): Playlist
    {
        $now = new \DateTimeImmutable();

        return Playlist::reconstitute(
            Uuid::v4(),
            new PublicId(),
            $owner,
            'Full',
            null,
            false,
            true,
            false,
            [],
            $now,
            $now,
            $songs,
            $collaborators,
            persistenceVersion: 1,
        );
    }
}
