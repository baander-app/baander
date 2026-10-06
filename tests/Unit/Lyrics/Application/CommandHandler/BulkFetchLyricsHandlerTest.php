<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lyrics\Application\CommandHandler;

use App\Catalog\Application\Port\SongLookupInterface;
use App\Lyrics\Application\Command\BulkFetchLyricsCommand;
use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Lyrics\Application\CommandHandler\BulkFetchLyricsHandler;
use App\Lyrics\Domain\Model\Lyrics;
use App\Lyrics\Domain\Repository\LyricsRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class BulkFetchLyricsHandlerTest extends TestCase
{
    public function testWalksSongIdCursorToTheEndAndDispatchesOneFetchPerSongWithoutLyrics(): void
    {
        $firstPage = array_map(static fn (): Uuid => Uuid::v7(), range(1, 50));
        $secondPage = array_map(static fn (): Uuid => Uuid::v7(), range(1, 50));
        $cached = $secondPage[10];
        $calls = [];
        $songs = $this->createMock(SongLookupInterface::class);
        $songs->expects($this->exactly(3))->method('songIdsAfter')
            ->willReturnCallback(static function (?Uuid $after, int $limit) use (&$calls, $firstPage, $secondPage): array {
                $calls[] = [$after?->toString(), $limit];

                return match ($after?->toString()) {
                    null => $firstPage,
                    end($firstPage)->toString() => $secondPage,
                    end($secondPage)->toString() => [],
                    default => self::fail('Unexpected continuation ' . $after->toString()),
                };
            });
        $lyrics = $this->createStub(LyricsRepositoryInterface::class);
        $lyrics->method('findBySongId')->willReturnCallback(
            static fn (Uuid $songId): ?Lyrics => $songId->equals($cached) ? Lyrics::create($songId, 'Cached', 'embedded') : null,
        );
        $dispatched = [];
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (FetchLyricsCommand $command) use (&$dispatched): Envelope {
            $dispatched[] = $command->getSongId()->toString();

            return new Envelope($command);
        });
        $handler = new BulkFetchLyricsHandler($songs, $lyrics, $bus, new NullLogger());

        self::assertSame(99, $handler(new BulkFetchLyricsCommand(delayMs: 0)));
        self::assertSame([
            [null, 50],
            [end($firstPage)->toString(), 50],
            [end($secondPage)->toString(), 50],
        ], $calls);
        $expected = array_values(array_filter(
            array_map(static fn (Uuid $id): string => $id->toString(), [...$firstPage, ...$secondPage]),
            static fn (string $id): bool => $id !== $cached->toString(),
        ));
        self::assertSame($expected, $dispatched);
    }

    public function testShortPageEndsTheWalkAndLimitBoundsThePageSize(): void
    {
        $page = [Uuid::v7(), Uuid::v7()];
        $songs = $this->createMock(SongLookupInterface::class);
        $songs->expects($this->once())->method('songIdsAfter')->with(null, 3)->willReturn($page);
        $lyrics = $this->createStub(LyricsRepositoryInterface::class);
        $lyrics->method('findBySongId')->willReturn(null);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->exactly(2))->method('dispatch')->willReturnCallback(
            static fn (FetchLyricsCommand $command): Envelope => new Envelope($command),
        );
        $handler = new BulkFetchLyricsHandler($songs, $lyrics, $bus, new NullLogger());

        self::assertSame(2, $handler(new BulkFetchLyricsCommand(limit: 3, delayMs: 0)));
    }
}
