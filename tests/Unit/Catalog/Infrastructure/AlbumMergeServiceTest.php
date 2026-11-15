<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Infrastructure;

use App\Catalog\Application\Port\AlbumMatchRequestInterface;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Infrastructure\AlbumMergeService;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

final class AlbumMergeServiceTest extends TestCase
{
    private Album $target;
    private Album $source;

    /** @var list<Song> the source album's songs */
    private array $sourceSongs = [];

    /** @var list<string> what the merge asked of its ports, in order */
    private array $calls = [];

    protected function setUp(): void
    {
        $library = new Uuid();
        $this->target = Album::create($library, 'Abbey Road', 'album');
        $this->source = Album::create($library, 'Abbey Road (Remaster)', 'album', year: 1969, label: 'Apple');
    }

    public function testMergingIntoAnAlbumWithALockedEmptyFieldLeavesItEmptyAndMergesTheRest(): void
    {
        $this->target->lockField('label');

        $merged = $this->merge($this->createStub(AlbumMatchRequestInterface::class));

        self::assertNull($merged->getLabel());
        self::assertSame(1969, $merged->getYear());
        self::assertTrue($merged->isFieldLocked('label'));
    }

    public function testAMergeRequestsAMatchOfTheTargetOnceItsSongsAndTheTargetAreSaved(): void
    {
        $this->sourceSongs = [Song::create($this->source->getId(), 'Something', '/music/abbey-road/02.flac', 1_000, 'audio/flac', hash: 'something')];
        $requests = $this->createMock(AlbumMatchRequestInterface::class);
        $requests->expects(self::once())->method('requestAfterImport')->willReturnCallback(function (Uuid ...$albumIds): void {
            $this->calls[] = 'request ' . implode(' ', array_map(static fn (Uuid $id): string => $id->toString(), $albumIds));
        });

        $this->merge($requests);

        self::assertSame(['save song', 'delete source', 'save target', 'request ' . $this->target->getId()->toString()], $this->calls);
    }

    public function testAFailedMatchRequestIsLoggedAndTheMergeCompletes(): void
    {
        $this->sourceSongs = [Song::create($this->source->getId(), 'Something', '/music/abbey-road/02.flac', 1_000, 'audio/flac', hash: 'something')];
        $requests = $this->createMock(AlbumMatchRequestInterface::class);
        $requests->expects(self::once())->method('requestAfterImport')->willThrowException(new RuntimeException('PostgreSQL is gone'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            self::stringContains('Could not request a MusicBrainz match'),
            self::callback(fn (array $context): bool => $context['album_id'] === $this->target->getId()->toString()
                && $context['exception'] instanceof RuntimeException),
        );

        $merged = $this->merge($requests, $logger);

        self::assertSame($this->target, $merged);
        self::assertTrue($this->sourceSongs[0]->getAlbumId()->equals($this->target->getId()));
        self::assertSame(['save song', 'delete source', 'save target'], $this->calls);
    }

    private function merge(AlbumMatchRequestInterface $requests, LoggerInterface $logger = new NullLogger()): Album
    {
        return new AlbumMergeService($this->albums(), $this->songs(), $requests, $logger)
            ->mergeAlbums($this->target->getId(), $this->source->getId());
    }

    private function albums(): AlbumPortInterface
    {
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByUuid')->willReturnCallback(
            fn (Uuid $id): ?Album => array_find([$this->target, $this->source], static fn (Album $album): bool => $album->getId()->equals($id)),
        );
        $albums->method('delete')->willReturnCallback(function (Album $album): void {
            $this->calls[] = $album === $this->source ? 'delete source' : 'delete target';
        });
        $albums->method('save')->willReturnCallback(function (Album $album): void {
            $this->calls[] = $album === $this->target ? 'save target' : 'save source';
        });

        return $albums;
    }

    private function songs(): SongPortInterface
    {
        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('findByAlbum')->willReturnCallback(
            fn (Uuid $albumId): array => $albumId->equals($this->source->getId()) ? $this->sourceSongs : [],
        );
        $songs->method('save')->willReturnCallback(function (): void {
            $this->calls[] = 'save song';
        });

        return $songs;
    }
}
