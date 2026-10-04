<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lyrics\Application\CommandHandler;

use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Song;
use App\Lyrics\Application\Command\BulkFetchLyricsCommand;
use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Lyrics\Application\CommandHandler\BulkFetchLyricsHandler;
use App\Lyrics\Domain\Repository\LyricsRepositoryInterface;
use App\Shared\Domain\Model\Cursor;
use App\Shared\Domain\Model\CursorDirection;
use App\Shared\Domain\Model\CursorPage;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Pagination\CursorCodec;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class BulkFetchLyricsHandlerTest extends TestCase
{
    public function testDecodesContinuationBeforeFetchingNextPage(): void
    {
        $first = Song::create(Uuid::v7(), 'First', '/first.flac', 100, 'audio/flac');
        $second = Song::create(Uuid::v7(), 'Second', '/second.flac', 100, 'audio/flac');
        $codec = new CursorCodec(new JsonEncoder());
        $cursor = Cursor::create(CursorDirection::Next, ['id' => $first->getId()->toString()]);
        $songs = $this->createMock(SongPortInterface::class);
        $songs->expects($this->exactly(2))->method('searchWithCursor')
            ->willReturnCallback(static function (SearchOptions $options) use ($first, $second, $codec, $cursor): CursorPage {
                if ($options->getCursor() === null) {
                    return new CursorPage([$first], $codec->encode($cursor), null, true, false, 2, false, 50);
                }
                self::assertSame($cursor->getValues(), $options->getCursor()->getValues());
                return new CursorPage([$second], null, null, false, true, 2, false, 50);
            });
        $lyrics = $this->createStub(LyricsRepositoryInterface::class);
        $lyrics->method('findBySongId')->willReturn(null);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->exactly(2))->method('dispatch')->willReturnCallback(
            static fn (FetchLyricsCommand $command): Envelope => new Envelope($command),
        );
        $handler = new BulkFetchLyricsHandler($songs, $lyrics, $bus, new NullLogger(), $codec);

        self::assertSame(2, $handler(new BulkFetchLyricsCommand(delayMs: 0)));
    }
}
