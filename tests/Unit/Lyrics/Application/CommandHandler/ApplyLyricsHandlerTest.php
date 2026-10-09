<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lyrics\Application\CommandHandler;

use App\Lyrics\Application\Command\ApplyLyricsCommand;
use App\Lyrics\Application\CommandHandler\ApplyLyricsHandler;
use App\Lyrics\Application\DTO\LrclibResult;
use App\Lyrics\Application\DTO\LrclibUnavailable;
use App\Lyrics\Application\Exception\LyricsProviderUnavailableException;
use App\Lyrics\Application\Port\LrclibClientInterface;
use App\Lyrics\Domain\Model\Lyrics;
use App\Lyrics\Domain\Repository\LyricsRepositoryInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ApplyLyricsHandlerTest extends TestCase
{
    private LrclibClientInterface&MockObject $lrclib;
    private LyricsRepositoryInterface&MockObject $lyrics;
    private ApplyLyricsHandler $handler;

    protected function setUp(): void
    {
        $this->lrclib = $this->createMock(LrclibClientInterface::class);
        $this->lyrics = $this->createMock(LyricsRepositoryInterface::class);
        $this->handler = new ApplyLyricsHandler($this->lrclib, $this->lyrics, new NullLogger());
    }

    public function testStoresTheResultForASongWithoutLyrics(): void
    {
        $songId = Uuid::v7();
        $this->lyrics->method('findBySongId')->willReturn(null);
        $this->lrclib->expects($this->once())->method('getById')->with(912345)
            ->willReturn(new LrclibResult(912345, 'Song', 'Artist', 'Album', 200.0, false, 'Applied lyrics', '[00:01.00] Applied lyrics'));
        $this->lyrics->expects($this->once())->method('save')->with($this->isInstanceOf(Lyrics::class));

        $stored = ($this->handler)(new ApplyLyricsCommand(912345, $songId));

        $this->assertTrue($stored->getSongId()->equals($songId));
        $this->assertSame('Applied lyrics', $stored->getLyrics());
        $this->assertSame('[00:01.00] Applied lyrics', $stored->getSyncedLyrics());
        $this->assertSame('lrclib', $stored->getSource());
        $this->assertSame(912345, $stored->getLrclibId());
    }

    public function testASongThatHasLyricsIsAConflictAndKeepsThem(): void
    {
        $songId = Uuid::v7();
        $this->lyrics->method('findBySongId')->willReturn(Lyrics::create($songId, 'Existing lyrics', 'embedded'));
        $this->lrclib->expects($this->never())->method('getById');
        $this->lyrics->expects($this->never())->method('save');

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('The song already has lyrics.');

        ($this->handler)(new ApplyLyricsCommand(912345, $songId));
    }

    /** The same recording on an album and a compilation, or in two libraries, shares one LRCLIB record. */
    #[AllowMockObjectsWithoutExpectations]
    public function testAResultAnotherSongHasIsStoredForASongWithoutLyricsToo(): void
    {
        $otherSong = Uuid::v7();
        $songId = Uuid::v7();
        $repository = new class implements LyricsRepositoryInterface {
            /** @var array<string, Lyrics> */
            public array $bySong = [];

            public function save(Lyrics $lyrics): void
            {
                $this->bySong[$lyrics->getSongId()->toString()] = $lyrics;
            }

            public function findBySongId(Uuid $songId): ?Lyrics
            {
                return $this->bySong[$songId->toString()] ?? null;
            }

            public function delete(Lyrics $lyrics): void
            {
                unset($this->bySong[$lyrics->getSongId()->toString()]);
            }
        };
        $repository->save(Lyrics::create($otherSong, 'This was a triumph', 'lrclib', lrclibId: 912345));
        $this->lrclib->expects($this->once())->method('getById')->with(912345)
            ->willReturn(new LrclibResult(912345, 'Still Alive', 'GLaDOS', 'Portal', 175.0, false, 'This was a triumph', null));

        $stored = (new ApplyLyricsHandler($this->lrclib, $repository, new NullLogger()))(new ApplyLyricsCommand(912345, $songId));

        $this->assertSame($stored, $repository->findBySongId($songId));
        $this->assertSame(912345, $stored->getLrclibId());
        $this->assertSame(912345, $repository->findBySongId($otherSong)?->getLrclibId());
    }

    public function testAnUnknownResultIsNotFound(): void
    {
        $this->lyrics->method('findBySongId')->willReturn(null);
        $this->lrclib->expects($this->once())->method('getById')->with(404404)->willReturn(null);
        $this->lyrics->expects($this->never())->method('save');

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('LRCLIB has no lyrics with ID 404404.');

        ($this->handler)(new ApplyLyricsCommand(404404, Uuid::v7()));
    }

    public function testAnOutageIsReportedAsProviderUnavailable(): void
    {
        $this->lyrics->method('findBySongId')->willReturn(null);
        $this->lrclib->expects($this->once())->method('getById')->willReturn(new LrclibUnavailable('HTTP 502'));
        $this->lyrics->expects($this->never())->method('save');

        $this->expectException(LyricsProviderUnavailableException::class);

        ($this->handler)(new ApplyLyricsCommand(912345, Uuid::v7()));
    }
}
