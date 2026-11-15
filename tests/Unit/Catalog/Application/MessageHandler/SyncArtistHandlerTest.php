<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\MessageHandler;

use App\Catalog\Application\MessageHandler\SyncArtistHandler;
use App\Catalog\Application\Port\ArtistMergePortInterface;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchCatalogReadInterface;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\MatchComparisonBuilder;
use App\Catalog\Application\Service\ArtistSyncQueue;
use App\Catalog\Application\Service\MusicBrainzMatch\ArtistMatchWriter;
use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtist;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzMerged;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRejected;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzUnavailable;
use App\Metadata\Application\Message\SyncArtistMessage;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\InMemoryQueuedArtistSyncs;
use App\Tests\Fixtures\Catalog\PassThroughTransaction;
use App\Tests\Unit\Catalog\Application\QueryHandler\MusicBrainzMatch\FakeMusicBrainzLookup;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/** The fill of one artist's empty details from MusicBrainz, by the MusicBrainz ID it holds. */
final class SyncArtistHandlerTest extends TestCase
{
    private const string POST_MALONE_ID = 'b1e26560-60e5-4236-bbdb-9aa5a8d5ee19';
    private const string AUSTIN_POST_ID = '2b1d4a4e-7c0f-4d6e-9a1b-3c5d7e9f1a02';
    private const int MINUTE_MS = 60_000;

    /** @var array<string, Artist> the stored artists, by ID; every read returns a copy */
    private array $rows = [];
    /** @var list<string> the IDs of the artists saved, in order */
    private array $saved = [];
    private SyncArtistLog $logger;
    /** @var list<Envelope> */
    private array $sent = [];
    private ?\Throwable $saveFailure = null;
    private FakeMusicBrainzLookup $musicBrainz;
    private InMemoryQueuedArtistSyncs $marks;
    private PassThroughTransaction $transaction;

    protected function setUp(): void
    {
        $this->musicBrainz = new FakeMusicBrainzLookup();
        $this->marks = new InMemoryQueuedArtistSyncs();
        $this->transaction = new PassThroughTransaction();
        $this->logger = new SyncArtistLog();
    }

    /**
     * An artist with a MusicBrainz ID, no country, a type an admin set and a locked sort name takes
     * MusicBrainz's country, keeps its type, sort name, name and ID, and is saved once.
     */
    public function testAnEmptyUnlockedFieldTakesMusicBrainzsValueWhileHeldAndLockedFieldsTheNameAndTheIdStay(): void
    {
        $artist = Artist::create('Post Malone', type: 'Group', mbid: self::POST_MALONE_ID);
        $artist->lockField('sortName');
        $this->store($artist);
        $this->musicBrainz->artists[self::POST_MALONE_ID] = self::answer(self::POST_MALONE_ID, name: 'Posty', sortName: 'Malone, Post', type: 'Person', country: 'US');

        $this->sync($artist);

        $stored = $this->stored($artist);
        self::assertSame('US', $stored->getCountry());
        self::assertSame('Group', $stored->getType());
        self::assertNull($stored->getSortName());
        self::assertSame('Post Malone', $stored->getName());
        self::assertSame(self::POST_MALONE_ID, $stored->getMbid());
        self::assertSame('2016-12-01', $stored->getLifeSpanBegin()?->format('Y-m-d'), 'A partial MusicBrainz date fills its first day.');
        self::assertSame([$artist->getId()->toString()], $this->saved);
        self::assertSame(['fetchArtist:' . self::POST_MALONE_ID], $this->musicBrainz->calls);
    }

    public function testAnArtistWithoutAnIdOrWithoutAnEmptyUnlockedFieldCausesNoRequestAndNoWrite(): void
    {
        $withoutId = Artist::create('Disturbed');
        $complete = Artist::create(
            'Post Malone', country: 'US', type: 'Person', lifeSpanBegin: new DateTimeImmutable('1995-07-04'), lifeSpanEnd: new DateTimeImmutable('2095-07-04'),
            disambiguation: 'US rapper', sortName: 'Malone, Post', mbid: self::POST_MALONE_ID,
        );
        $lockedEmpty = Artist::create(
            'Austin Post', country: 'US', type: 'Person', lifeSpanBegin: new DateTimeImmutable('1995-07-04'),
            disambiguation: 'US rapper', sortName: 'Post, Austin', mbid: self::AUSTIN_POST_ID,
        );
        $lockedEmpty->lockField('lifeSpanEnd');
        foreach ([$withoutId, $complete, $lockedEmpty] as $artist) {
            $this->store($artist);
            $this->marks->queued[$artist->getId()->toString()] = 60;

            $this->sync($artist);

            self::assertArrayNotHasKey($artist->getId()->toString(), $this->marks->queued, 'The sync has run.');
        }

        self::assertSame([], $this->musicBrainz->calls);
        self::assertSame([], $this->saved);
        self::assertSame([], $this->marks->answered);
    }

    /** @return iterable<string, array{MusicBrainzRejected|MusicBrainzUnavailable|null, bool}> the answer, and whether it is an answer that counts */
    public static function answersWithoutARecord(): iterable
    {
        yield 'a 404' => [null, true];
        yield 'a refusal' => [new MusicBrainzRejected(403), false];
        yield 'an outage' => [new MusicBrainzUnavailable('HTTP 503'), false];
    }

    #[DataProvider('answersWithoutARecord')]
    public function testAnAnswerWithoutARecordWritesNothingEndsQuietlyAndIsLoggedWithTheArtist(MusicBrainzRejected|MusicBrainzUnavailable|null $answer, bool $counts): void
    {
        $artist = $this->store(Artist::create('Post Malone', mbid: self::POST_MALONE_ID));
        if ($answer !== null) {
            $this->musicBrainz->artists[self::POST_MALONE_ID] = $answer;
        }

        $this->sync($artist);

        self::assertSame([], $this->saved);
        self::assertTrue($this->logged($artist->getId()->toString()), 'The log names the artist.');
        self::assertSame($counts ? [$artist->getId()->toString() . ':' . self::POST_MALONE_ID => 30 * 86_400] : [], $this->marks->answered);
    }

    public function testAnOutageQueuesTheSameFillAgainAfterFiveFifteenAndSixtyMinutesThenNoMore(): void
    {
        $artist = $this->store(Artist::create('Post Malone', mbid: self::POST_MALONE_ID));
        $this->musicBrainz->artists[self::POST_MALONE_ID] = new MusicBrainzUnavailable('HTTP 503');
        $runId = Uuid::v7();
        $this->marks->queued[$artist->getId()->toString()] = 60;

        $message = new SyncArtistMessage($artist->getId(), $runId);
        foreach ([5, 15, 60] as $outage => $minutes) {
            $this->sent = [];
            $this->handler()($message);

            self::assertCount(1, $this->sent, 'Outage ' . ($outage + 1) . ' queues the fill again.');
            $queuedAgain = array_first($this->sent()) ?? self::fail('The fill is queued again.');
            $message = $queuedAgain->getMessage();
            self::assertEquals(new SyncArtistMessage($artist->getId(), $runId, $outage + 1), $message, 'The fill keeps its run and counts its outages.');
            self::assertSame($minutes * self::MINUTE_MS, $queuedAgain->last(DelayStamp::class)?->getDelay());
            self::assertArrayHasKey($artist->getId()->toString(), $this->marks->queued, 'The artist stays queued.');
            $this->marks->latestDueMs = null;
        }

        $this->sent = [];
        $this->handler()($message);

        self::assertSame([], $this->sent, 'The fourth outage in a row queues nothing.');
        self::assertArrayNotHasKey($artist->getId()->toString(), $this->marks->queued);
        self::assertSame([], $this->saved);
        self::assertSame([], $this->marks->answered);
    }

    public function testAFillQueuedAgainFallsDueAtTheSharedPaceWhenThatIsLaterThanItsPause(): void
    {
        $artist = $this->store(Artist::create('Post Malone', mbid: self::POST_MALONE_ID));
        $this->musicBrainz->artists[self::POST_MALONE_ID] = new MusicBrainzUnavailable('HTTP 503');
        $this->marks->latestDueMs = 10 * self::MINUTE_MS;

        $this->sync($artist);

        self::assertSame(10 * self::MINUTE_MS + ArtistSyncQueue::SPACING_MS, $this->sent[0]->last(DelayStamp::class)?->getDelay());
    }

    /** @return iterable<string, array{MusicBrainzArtist|MusicBrainzMerged, bool}> the answer, and whether another stored artist holds its ID */
    public static function answersForAnotherId(): iterable
    {
        yield 'a merge into another artist' => [new MusicBrainzMerged(self::POST_MALONE_ID, self::AUSTIN_POST_ID), false];
        yield 'a merge into an artist the catalog holds' => [new MusicBrainzMerged(self::POST_MALONE_ID, self::AUSTIN_POST_ID), true];
        yield 'a record of another artist' => [self::answer(self::AUSTIN_POST_ID, country: 'US'), false];
        yield 'a record of an artist the catalog holds' => [self::answer(self::AUSTIN_POST_ID, country: 'US'), true];
    }

    #[DataProvider('answersForAnotherId')]
    public function testAnAnswerForAnotherIdWritesNothingLogsBothIdsAndCountsAsAnswered(MusicBrainzArtist|MusicBrainzMerged $answer, bool $heldByAnother): void
    {
        $artist = $this->store(Artist::create('Post Malone', mbid: self::POST_MALONE_ID));
        $holder = $heldByAnother ? $this->store(Artist::create('Austin Post', mbid: self::AUSTIN_POST_ID)) : null;
        $this->musicBrainz->artists[self::POST_MALONE_ID] = $answer;

        $this->sync($artist);

        self::assertSame([], $this->saved, 'No artist is written or merged.');
        self::assertNull($this->stored($artist)->getCountry());
        self::assertSame(self::POST_MALONE_ID, $this->stored($artist)->getMbid());
        if ($holder !== null) {
            self::assertNotNull($this->rows[$holder->getId()->toString()] ?? null);
        }
        self::assertTrue($this->logged(self::POST_MALONE_ID, self::AUSTIN_POST_ID), 'The log names both IDs.');
        self::assertSame([$artist->getId()->toString() . ':' . self::POST_MALONE_ID => 30 * 86_400], $this->marks->answered);
    }

    public function testAnArtistDeletedDuringTheLookupIsNotCreatedAgain(): void
    {
        $artist = $this->store(Artist::create('Post Malone', mbid: self::POST_MALONE_ID));
        $this->musicBrainz->artists[self::POST_MALONE_ID] = self::answer(self::POST_MALONE_ID, country: 'US');
        $this->musicBrainz->duringArtistLookup = function () use ($artist): void {
            unset($this->rows[$artist->getId()->toString()]);
        };

        $this->sync($artist);

        self::assertSame([], $this->rows);
        self::assertSame([], $this->saved);
    }

    public function testAnArtistGivenAnotherIdDuringTheLookupIsNotWritten(): void
    {
        $artist = $this->store(Artist::create('Post Malone', mbid: self::POST_MALONE_ID));
        $this->musicBrainz->artists[self::POST_MALONE_ID] = self::answer(self::POST_MALONE_ID, country: 'US');
        $this->musicBrainz->duringArtistLookup = function () use ($artist): void {
            $this->change($artist, static fn (Artist $stored) => $stored->updateExternalIds(mbid: self::AUSTIN_POST_ID));
        };

        $this->sync($artist);

        self::assertSame([], $this->saved);
        self::assertNull($this->stored($artist)->getCountry());
        self::assertSame(self::AUSTIN_POST_ID, $this->stored($artist)->getMbid());
    }

    public function testAFieldFilledOrLockedDuringTheLookupIsNotWrittenAndTheOthersAre(): void
    {
        $artist = $this->store(Artist::create('Post Malone', mbid: self::POST_MALONE_ID));
        $this->musicBrainz->artists[self::POST_MALONE_ID] = self::answer(self::POST_MALONE_ID, sortName: 'Malone, Post', type: 'Person', country: 'US');
        $this->musicBrainz->duringArtistLookup = function () use ($artist): void {
            $this->change($artist, static function (Artist $stored): void {
                $stored->updateMetadata(country: 'GB');
                $stored->lockField('type');
            });
        };

        $this->sync($artist);

        $stored = $this->stored($artist);
        self::assertSame('GB', $stored->getCountry());
        self::assertNull($stored->getType());
        self::assertSame('Malone, Post', $stored->getSortName());
    }

    public function testAnAnswerThatFillsNothingSavesNothing(): void
    {
        $artist = $this->store(Artist::create('Post Malone', country: 'US', mbid: self::POST_MALONE_ID));
        $this->musicBrainz->artists[self::POST_MALONE_ID] = self::answer(self::POST_MALONE_ID, country: 'GB', lifeSpanBegin: null);

        $this->sync($artist);

        self::assertSame([], $this->saved);
        self::assertSame([$artist->getId()->toString() . ':' . self::POST_MALONE_ID => 30 * 86_400], $this->marks->answered, 'The lookup was answered.');
    }

    public function testAnArtistMusicBrainzAnsweredAboutItsIdIsNotAskedAgain(): void
    {
        $artist = $this->store(Artist::create('Post Malone', mbid: self::POST_MALONE_ID));
        $this->musicBrainz->artists[self::POST_MALONE_ID] = self::answer(self::POST_MALONE_ID, country: 'US');
        $this->marks->markAnswered($artist->getId(), self::POST_MALONE_ID, 30 * 86_400);

        $this->sync($artist);

        self::assertSame([], $this->musicBrainz->calls);
        self::assertSame([], $this->saved);
    }

    public function testAnArtistAnsweredAboutAnEarlierIdIsAskedAboutItsNewOne(): void
    {
        $artist = $this->store(Artist::create('Post Malone', mbid: self::POST_MALONE_ID));
        $this->musicBrainz->artists[self::POST_MALONE_ID] = self::answer(self::POST_MALONE_ID, country: 'US');
        $this->marks->markAnswered($artist->getId(), self::AUSTIN_POST_ID, 30 * 86_400);

        $this->sync($artist);

        self::assertSame(['fetchArtist:' . self::POST_MALONE_ID], $this->musicBrainz->calls);
        self::assertSame('US', $this->stored($artist)->getCountry());
    }

    public function testAWriteThatFailsLeavesNoAnswerMarkAndTheRetryOfTheMessageFillsTheArtist(): void
    {
        $artist = $this->store(Artist::create('Post Malone', mbid: self::POST_MALONE_ID));
        $this->musicBrainz->artists[self::POST_MALONE_ID] = self::answer(self::POST_MALONE_ID, country: 'US');
        $this->saveFailure = new \RuntimeException('The connection dropped.');
        $message = new SyncArtistMessage($artist->getId());
        $this->marks->queued[$artist->getId()->toString()] = 60;

        try {
            $this->handler()($message);
            self::fail('The failed write fails the message, which is retried.');
        } catch (\RuntimeException) {
        }
        self::assertSame([], $this->marks->answered);
        self::assertArrayHasKey($artist->getId()->toString(), $this->marks->queued, 'The artist stays queued for the retry.');

        $this->saveFailure = null;
        $this->handler()($message);

        self::assertSame('US', $this->stored($artist)->getCountry());
        self::assertSame([$artist->getId()->toString() . ':' . self::POST_MALONE_ID => 30 * 86_400], $this->marks->answered);
        self::assertArrayNotHasKey($artist->getId()->toString(), $this->marks->queued);
    }

    public function testAFillOfACancelledRunEndsWithoutARequest(): void
    {
        $artist = $this->store(Artist::create('Post Malone', mbid: self::POST_MALONE_ID));
        $runId = Uuid::v7();
        $this->marks->markRunCancelled($runId, 60);
        $this->marks->queued[$artist->getId()->toString()] = 60;

        $this->handler()(new SyncArtistMessage($artist->getId(), $runId));

        self::assertSame([], $this->musicBrainz->calls);
        self::assertArrayNotHasKey($artist->getId()->toString(), $this->marks->queued);
    }

    /** @return list<Envelope> the messages sent so far */
    private function sent(): array
    {
        return $this->sent;
    }

    private function sync(Artist $artist): void
    {
        $this->handler()(new SyncArtistMessage($artist->getId()));
    }

    private function handler(): SyncArtistHandler
    {
        $artists = $this->artistPort();
        $merges = $this->createStub(ArtistMergePortInterface::class);
        $merges->method('lock')->willReturnCallback(function (array $artistIds): array {
            self::assertTrue($this->transaction->active, 'The artist is locked inside the transaction.');
            $present = array_filter($artistIds, fn (Uuid $id): bool => isset($this->rows[$id->toString()]));

            return array_fill_keys(array_map(static fn (Uuid $id): string => $id->toString(), $present), false);
        });
        $merges->method('merge')->willThrowException(new \LogicException('An artist sync merges no artist.'));
        $merges->method('remove')->willThrowException(new \LogicException('An artist sync removes no artist.'));
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message, array $stamps = []): Envelope {
            $envelope = new Envelope($message, $stamps);
            $this->sent[] = $envelope;

            return $envelope;
        });

        return new SyncArtistHandler(
            $artists,
            $merges,
            $this->musicBrainz,
            new MatchComparisonBuilder($this->createStub(MatchCatalogReadInterface::class), $artists),
            new ArtistMatchWriter($artists),
            $this->marks,
            new ArtistSyncQueue($this->marks, $bus),
            $this->transaction,
            $this->logger,
        );
    }

    /** An artist port over the stored rows: every read is a copy, as a fresh read from the database is. */
    private function artistPort(): ArtistPortInterface
    {
        $artists = $this->createStub(ArtistPortInterface::class);
        $artists->method('findFreshByUuid')->willReturnCallback(fn (Uuid $id): ?Artist => isset($this->rows[$id->toString()]) ? self::copy($this->rows[$id->toString()]) : null);
        $artists->method('findByMbid')->willReturnCallback(fn (?MusicbrainzId $mbid): ?Artist => array_find(
            $this->rows,
            static fn (Artist $artist): bool => $mbid !== null && $artist->getMbid() === $mbid->toString(),
        ) |> (static fn (?Artist $artist): ?Artist => $artist === null ? null : self::copy($artist)));
        $artists->method('save')->willReturnCallback(function (Artist $artist): void {
            self::assertTrue($this->transaction->active, 'The artist is written inside the transaction.');
            if ($this->saveFailure !== null) {
                throw $this->saveFailure;
            }
            $this->rows[$artist->getId()->toString()] = self::copy($artist);
            $this->saved[] = $artist->getId()->toString();
        });

        return $artists;
    }

    /** Whether one log entry's context holds every value. */
    private function logged(string ...$values): bool
    {
        return array_any($this->logger->contexts, static fn (array $context): bool => array_all($values, static fn (string $value): bool => in_array($value, $context, true)));
    }

    private function store(Artist $artist): Artist
    {
        $this->rows[$artist->getId()->toString()] = self::copy($artist);

        return $artist;
    }

    /** Another writer's commit to the stored artist. */
    private function change(Artist $artist, \Closure $change): void
    {
        $stored = self::copy($this->rows[$artist->getId()->toString()]);
        $change($stored);
        $this->rows[$artist->getId()->toString()] = $stored;
    }

    private function stored(Artist $artist): Artist
    {
        return $this->rows[$artist->getId()->toString()] ?? throw new \LogicException('The artist is gone.');
    }

    private static function copy(Artist $artist): Artist
    {
        $copy = unserialize(serialize($artist));
        self::assertInstanceOf(Artist::class, $copy);

        return $copy;
    }

    private static function answer(
        string $id,
        string $name = 'Post Malone',
        ?string $sortName = null,
        ?string $type = null,
        ?string $country = null,
        ?string $lifeSpanBegin = '2016-12',
    ): MusicBrainzArtist {
        return new MusicBrainzArtist($id, $name, 0, $sortName, $type, $country, null, $lifeSpanBegin, null, false);
    }
}

/** Keeps the context of every log entry. */
final class SyncArtistLog extends AbstractLogger
{
    /** @var list<array<string, mixed>> */
    public array $contexts = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->contexts[] = $context;
    }
}
