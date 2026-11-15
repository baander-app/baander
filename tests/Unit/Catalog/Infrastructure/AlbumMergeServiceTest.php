<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Infrastructure;

use App\Catalog\Application\Port\AlbumMatchRequestInterface;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Application\Service\CoverImageDiscarder;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Infrastructure\AlbumMergeService;
use App\Media\Application\Port\ImagePortInterface;
use App\Media\Application\Port\StoragePortInterface;
use App\Media\Domain\Model\Image;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\TransactionDeadlockException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

final class AlbumMergeServiceTest extends TestCase
{
    private Album $target;
    private Album $source;

    /** @var list<Song> the target album's songs, in the order the song port returns them */
    private array $targetSongs = [];

    /** @var list<Song> the source album's songs */
    private array $sourceSongs = [];

    /** @var list<string> what the merge did through its ports, in order */
    private array $calls = [];

    /** @var list<array{string, string, array<string, mixed>}> level, message and context of each log entry */
    private array $logged = [];

    private bool $sourceGoneUnderTheLocks = false;

    protected function setUp(): void
    {
        $library = new Uuid();
        $this->target = Album::create($library, 'Abbey Road', 'album');
        $this->source = Album::create($library, 'Abbey Road (Remaster)', 'album', year: 1969, label: 'Apple');
    }

    public function testMergingIntoAnAlbumWithALockedEmptyFieldLeavesItEmptyAndMergesTheRest(): void
    {
        $this->target->lockField('label');

        $merged = $this->merge();

        self::assertNull($merged->getLabel());
        self::assertSame(1969, $merged->getYear());
        self::assertTrue($merged->isFieldLocked('label'));
    }

    public function testTheSourcesCoverIsDiscardedAndTheTargetsMatchRequestedOnlyOnceTheMergeCommitted(): void
    {
        $this->source->setCoverImage(new Uuid());
        $this->sourceSongs = [$this->song($this->source, 'Something', 'something')];

        $this->merge();

        self::assertSame(['delete source', 'save target', 'commit', 'discard cover', 'request target'], array_slice($this->calls, -5));
    }

    public function testAMergeWhoseTransactionFailsDiscardsNoCoverAndRequestsNoMatch(): void
    {
        $this->source->setCoverImage(new Uuid());
        $this->sourceSongs = [$this->song($this->source, 'Something', 'something')];

        try {
            $this->merge(transaction: $this->failingTransaction(new RuntimeException('The connection was lost before the commit.')));
            self::fail('The merge did not fail.');
        } catch (RuntimeException $failure) {
            self::assertSame('The connection was lost before the commit.', $failure->getMessage());
        }

        self::assertNotContains('discard cover', $this->calls);
        self::assertNotContains('request target', $this->calls);
    }

    public function testAMergeTheDatabaseEndedToBreakADeadlockIsAConflictNamingBothAlbumsAndIsLogged(): void
    {
        $deadlock = new TransactionDeadlockException(new RuntimeException('deadlock detected'));

        try {
            $this->merge(transaction: $this->failingTransaction($deadlock));
            self::fail('The merge did not fail.');
        } catch (ConflictException $conflict) {
            self::assertSame(['reason' => 'merge_busy'], $conflict->details);
            self::assertSame(
                'Another change to "Abbey Road (Remaster)" or "Abbey Road" ran at the same time and nothing was merged. Merge the albums again.',
                $conflict->getMessage(),
            );
            self::assertSame($deadlock, $conflict->getPrevious());
        }

        $warning = array_find($this->logged, static fn (array $entry): bool => $entry[0] === 'warning');
        self::assertNotNull($warning, 'The deadlock is logged.');
        self::assertSame($this->source->getId()->toString(), $warning[2]['source_album_id'] ?? null);
        self::assertSame($this->target->getId()->toString(), $warning[2]['target_album_id'] ?? null);
        self::assertNotContains('request target', $this->calls);
    }

    public function testEverySongOfTheSourceIsReadUnderTheLocksAndMovedWithoutTheCappedRead(): void
    {
        $this->sourceSongs = array_map(fn (int $track): Song => $this->song($this->source, 'Track ' . $track, 'track-' . $track), range(1, 150));

        $this->merge();

        self::assertCount(150, array_filter($this->sourceSongs, fn (Song $song): bool => $song->getAlbumId()->equals($this->target->getId())));
        self::assertContains('lock songs', $this->calls);
    }

    public function testASourceSongWithoutAHashIsMovedAlsoWhenTheTargetHoldsASongWithoutOne(): void
    {
        $this->targetSongs = [$this->song($this->target, 'Come Together', null)];
        $unhashed = $this->song($this->source, 'Her Majesty', null);
        $this->sourceSongs = [$unhashed];

        $this->merge();

        self::assertTrue($unhashed->getAlbumId()->equals($this->target->getId()));
        self::assertNotContains('delete Her Majesty', $this->calls);
    }

    public function testADuplicateIsRemovedAsTheDuplicateOfTheTargetsOldestSongOfItsHash(): void
    {
        $older = $this->song($this->target, 'Come Together', 'come-together');
        $newer = $this->song($this->target, 'Come Together (Take 1)', 'come-together');
        // The newer first, so a map that kept the first song of a hash would name it.
        $this->targetSongs = [$newer, $older];
        $this->sourceSongs = [$this->song($this->source, 'Come Together (Remaster)', 'come-together')];

        $this->merge();

        self::assertContains('delete Come Together (Remaster)', $this->calls);
        $removal = array_find($this->logged, static fn (array $entry): bool => $entry[0] === 'info' && isset($entry[2]['kept_song_id']));
        self::assertNotNull($removal, 'The removed duplicate is logged with the song it duplicates.');
        self::assertSame($older->getId()->toString(), $removal[2]['kept_song_id']);
    }

    public function testAnAlbumGoneByTheTimeTheMergeHoldsItsLocksIsNotFoundAndNothingIsWritten(): void
    {
        $this->sourceSongs = [$this->song($this->source, 'Something', 'something')];
        $this->sourceGoneUnderTheLocks = true;

        try {
            $this->merge();
            self::fail('The merge did not fail.');
        } catch (NotFoundException $notFound) {
            self::assertSame('Source album not found.', $notFound->getMessage());
        }

        self::assertSame(['lock albums'], array_values(array_diff($this->calls, ['begin'])));
    }

    public function testAFailedMatchRequestIsLoggedAndTheMergeCompletes(): void
    {
        $this->sourceSongs = [$this->song($this->source, 'Something', 'something')];
        $requests = $this->createMock(AlbumMatchRequestInterface::class);
        $requests->expects(self::once())->method('requestAfterImport')->willThrowException(new RuntimeException('PostgreSQL is gone'));

        $merged = $this->merge(requests: $requests);

        self::assertSame($this->target, $merged);
        self::assertTrue($this->sourceSongs[0]->getAlbumId()->equals($this->target->getId()));
        $error = array_find($this->logged, static fn (array $entry): bool => $entry[0] === 'error');
        self::assertNotNull($error);
        self::assertStringContainsString('Could not request a MusicBrainz match', $error[1]);
        self::assertSame($this->target->getId()->toString(), $error[2]['album_id'] ?? null);
        self::assertInstanceOf(RuntimeException::class, $error[2]['exception'] ?? null);
    }

    private function merge(?AlbumMatchRequestInterface $requests = null, ?TransactionPortInterface $transaction = null): Album
    {
        return new AlbumMergeService(
            $this->albums(),
            $this->songs(),
            $requests ?? $this->requests(),
            $transaction ?? $this->transaction(),
            new CoverImageDiscarder($this->images(), $this->createStub(StoragePortInterface::class), $this->logger()),
            $this->logger(),
        )->mergeAlbums($this->target->getId(), $this->source->getId());
    }

    private function song(Album $album, string $title, ?string $hash): Song
    {
        return Song::create($album->getId(), $title, '/music/abbey-road/' . $title . '.flac', 1_000, 'audio/flac', hash: $hash);
    }

    private function albums(): AlbumPortInterface
    {
        $find = fn (Uuid $id): ?Album => array_find([$this->target, $this->source], static fn (Album $album): bool => $album->getId()->equals($id));
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByUuid')->willReturnCallback($find);
        $albums->method('findFreshByUuid')->willReturnCallback(
            fn (Uuid $id): ?Album => $this->sourceGoneUnderTheLocks && $id->equals($this->source->getId()) ? null : $find($id),
        );
        $albums->method('lockForMerge')->willReturnCallback(function (): void {
            $this->calls[] = 'lock albums';
        });
        $albums->method('delete')->willReturnCallback(function (Album $album, bool $deleteCover = true): void {
            $this->calls[] = ($album === $this->source ? 'delete source' : 'delete target') . ($deleteCover ? ' with its cover' : '');
        });
        $albums->method('save')->willReturnCallback(function (Album $album): void {
            $this->calls[] = $album === $this->target ? 'save target' : 'save source';
        });

        return $albums;
    }

    /** The songs of both albums; the merge never reads an album's songs through the capped read. */
    private function songs(): SongPortInterface
    {
        $all = fn (): array => [...$this->targetSongs, ...$this->sourceSongs];
        $songs = $this->createMock(SongPortInterface::class);
        $songs->expects(self::never())->method('findByAlbum');
        $songs->method('findIdsByAlbums')->willReturnCallback(fn (): array => array_map(static fn (Song $song): Uuid => $song->getId(), $all()));
        $songs->method('lockInIdOrder')->willReturnCallback(function (): void {
            $this->calls[] = 'lock songs';
        });
        $songs->method('findFreshByUuids')->willReturnCallback(function (array $ids) use ($all): array {
            $found = [];
            foreach ($all() as $song) {
                if (array_any($ids, $song->getId()->equals(...))) {
                    $found[$song->getId()->toString()] = $song;
                }
            }

            return $found;
        });
        $songs->method('persist')->willReturnCallback(function (Song $song): void {
            $this->calls[] = 'move ' . $song->getTitle();
        });
        $songs->method('flush')->willReturnCallback(function (): void {
            $this->calls[] = 'flush songs';
        });
        $songs->method('delete')->willReturnCallback(function (Song $song): void {
            $this->calls[] = 'delete ' . $song->getTitle();
        });

        return $songs;
    }

    private function requests(): AlbumMatchRequestInterface
    {
        $requests = $this->createStub(AlbumMatchRequestInterface::class);
        $requests->method('requestAfterImport')->willReturnCallback(function (Uuid ...$albumIds): void {
            foreach ($albumIds as $albumId) {
                $this->calls[] = $albumId->equals($this->target->getId()) ? 'request target' : 'request ' . $albumId->toString();
            }
        });

        return $requests;
    }

    /** Runs the operation in place and records the commit once it returned. */
    private function transaction(): TransactionPortInterface
    {
        return new readonly class ($this->record(...)) implements TransactionPortInterface {
            public function __construct(private \Closure $record)
            {
            }

            public function run(callable $operation): mixed
            {
                ($this->record)('begin');
                $result = $operation();
                ($this->record)('commit');

                return $result;
            }
        };
    }

    /** Runs the operation in place and then fails as a commit the database refused would. */
    private function failingTransaction(\Throwable $failure): TransactionPortInterface
    {
        return new readonly class ($this->record(...), $failure) implements TransactionPortInterface {
            public function __construct(private \Closure $record, private \Throwable $failure)
            {
            }

            public function run(callable $operation): mixed
            {
                ($this->record)('begin');
                $operation();

                throw $this->failure;
            }
        };
    }

    private function record(string $call): void
    {
        $this->calls[] = $call;
    }

    private function images(): ImagePortInterface
    {
        $images = $this->createStub(ImagePortInterface::class);
        $images->method('findByUuid')->willReturnCallback(
            fn (Uuid $id): ?Image => $id->equals($this->source->getCoverImageId() ?? new Uuid())
                ? Image::create('images/album/abbey-road.jpg', 'jpg', 'image/jpeg', 5, 1, 1, 'album', $this->source->getId())
                : null,
        );
        $images->method('delete')->willReturnCallback(function (): void {
            $this->calls[] = 'discard cover';
        });

        return $images;
    }

    private function logger(): AbstractLogger
    {
        return new class ($this->log(...)) extends AbstractLogger {
            public function __construct(private readonly \Closure $log)
            {
            }

            /** @param array<string, mixed> $context */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                ($this->log)((string) $level, (string) $message, $context);
            }
        };
    }

    /** @param array<string, mixed> $context */
    private function log(string $level, string $message, array $context): void
    {
        $this->logged[] = [$level, $message, $context];
    }
}
