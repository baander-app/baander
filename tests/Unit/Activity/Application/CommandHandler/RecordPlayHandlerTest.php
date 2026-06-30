<?php

declare(strict_types=1);

namespace App\Tests\Unit\Activity\Application\CommandHandler;

use App\Activity\Application\Command\RecordPlayCommand;
use App\Activity\Application\CommandHandler\RecordPlayHandler;
use App\Activity\Domain\Model\MediaActivity;
use App\Activity\Domain\Repository\MediaActivityRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RecordPlayHandlerTest extends TestCase
{
    private MediaActivityRepositoryInterface&MockObject $repository;
    private RecordPlayHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(MediaActivityRepositoryInterface::class);
        $this->handler = new RecordPlayHandler($this->repository);
    }

    public function testCreatesNewActivityWhenNoExistingSongActivity(): void
    {
        $userId = Uuid::v4();
        $songId = Uuid::v4();

        $this->repository->expects($this->once())
            ->method('findForSong')
            ->with($userId, $songId)
            ->willReturn(null);
        $this->repository->expects($this->once())
            ->method('save')
            ->with($this->callback(static fn (MediaActivity $a): bool => $a->getActivityType() === 'play' && $a->getPlayCount() === 1));

        $activity = ($this->handler)(new RecordPlayCommand(
            userId: $userId,
            songId: $songId,
            platform: 'mobile',
            player: 'app',
        ));

        $this->assertSame('play', $activity->getActivityType());
        $this->assertTrue($activity->getUserId()->equals($userId));
        $this->assertTrue($activity->getSongId()->equals($songId));
        $this->assertSame(1, $activity->getPlayCount());
        $this->assertSame('mobile', $activity->getLastPlatform());
        $this->assertSame('app', $activity->getLastPlayer());
        $this->assertNotNull($activity->getLastPlayedAt());
    }

    public function testIncrementsPlayCountOnExistingSongActivity(): void
    {
        $userId = Uuid::v4();
        $songId = Uuid::v4();
        $existing = MediaActivity::create(userId: $userId, activityType: 'play', songId: $songId);
        $existing->recordPlay('web', 'browser');

        $this->assertSame(1, $existing->getPlayCount());

        $this->repository->expects($this->once())
            ->method('findForSong')
            ->with($userId, $songId)
            ->willReturn($existing);
        $this->repository->expects($this->once())->method('save')->with($existing);

        $result = ($this->handler)(new RecordPlayCommand(
            userId: $userId,
            songId: $songId,
            platform: 'desktop',
            player: 'vlc',
        ));

        $this->assertSame($existing, $result);
        $this->assertSame(2, $result->getPlayCount());
        $this->assertSame('desktop', $result->getLastPlatform());
        $this->assertSame('vlc', $result->getLastPlayer());
    }

    public function testRoutesToFindForMovieWhenOnlyMovieIdProvided(): void
    {
        $userId = Uuid::v4();
        $movieId = Uuid::v4();

        $this->repository->expects($this->once())
            ->method('findForMovie')
            ->with($userId, $movieId)
            ->willReturn(null);
        $this->repository->expects($this->never())->method('findForSong');
        $this->repository->expects($this->once())->method('save');

        $activity = ($this->handler)(new RecordPlayCommand(
            userId: $userId,
            movieId: $movieId,
            platform: 'tv',
            player: 'kodi',
        ));

        $this->assertSame('play', $activity->getActivityType());
        $this->assertNull($activity->getSongId());
        $this->assertTrue($activity->getMovieId()->equals($movieId));
        $this->assertSame(1, $activity->getPlayCount());
        $this->assertSame('tv', $activity->getLastPlatform());
    }

    public function testRoutesToFindForSongWhenBothSongAndMovieProvided(): void
    {
        $userId = Uuid::v4();
        $songId = Uuid::v4();
        $movieId = Uuid::v4();

        $this->repository->expects($this->once())
            ->method('findForSong')
            ->with($userId, $songId)
            ->willReturn(null);
        $this->repository->expects($this->never())->method('findForMovie');
        $this->repository->expects($this->once())->method('save');

        $activity = ($this->handler)(new RecordPlayCommand(
            userId: $userId,
            songId: $songId,
            movieId: $movieId,
        ));

        $this->assertTrue($activity->getSongId()->equals($songId));
        $this->assertTrue($activity->getMovieId()->equals($movieId));
        $this->assertSame(1, $activity->getPlayCount());
    }

    public function testRecordsMoviePlayOnExistingMovieActivity(): void
    {
        $userId = Uuid::v4();
        $movieId = Uuid::v4();
        $existing = MediaActivity::create(userId: $userId, activityType: 'play', movieId: $movieId);

        $this->repository->expects($this->once())
            ->method('findForMovie')
            ->with($userId, $movieId)
            ->willReturn($existing);
        $this->repository->expects($this->once())->method('save')->with($existing);

        $result = ($this->handler)(new RecordPlayCommand(userId: $userId, movieId: $movieId));

        $this->assertSame($existing, $result);
        $this->assertSame(1, $result->getPlayCount());
        $this->assertNull($result->getLastPlatform());
        $this->assertNull($result->getLastPlayer());
    }

    public function testCreateNewActivityWithoutPlatformOrPlayer(): void
    {
        $userId = Uuid::v4();
        $songId = Uuid::v4();

        $this->repository->expects($this->once())
            ->method('findForSong')
            ->willReturn(null);
        $this->repository->expects($this->once())->method('save');

        $activity = ($this->handler)(new RecordPlayCommand(userId: $userId, songId: $songId));

        $this->assertSame(1, $activity->getPlayCount());
        $this->assertNull($activity->getLastPlatform());
        $this->assertNull($activity->getLastPlayer());
    }

    public function testCarriesAlbumAndArtistIdsOntoNewActivity(): void
    {
        $userId = Uuid::v4();
        $songId = Uuid::v4();
        $albumId = Uuid::v4();
        $artistId = Uuid::v4();

        $this->repository->expects($this->once())->method('findForSong')->willReturn(null);
        $this->repository->expects($this->once())->method('save');

        $activity = ($this->handler)(new RecordPlayCommand(
            userId: $userId,
            songId: $songId,
            albumId: $albumId,
            artistId: $artistId,
        ));

        $this->assertTrue($activity->getSongId()->equals($songId));
        $this->assertTrue($activity->getAlbumId()->equals($albumId));
        $this->assertTrue($activity->getArtistId()->equals($artistId));
    }
}
