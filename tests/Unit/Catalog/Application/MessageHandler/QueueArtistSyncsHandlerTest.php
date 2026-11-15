<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\MessageHandler;

use App\Catalog\Application\MessageHandler\QueueArtistSyncsHandler;
use App\Catalog\Application\MessageHandler\SyncArtistHandler;
use App\Catalog\Application\Port\ArtistMergePortInterface;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchCatalogReadInterface;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\MatchComparisonBuilder;
use App\Catalog\Application\Service\ArtistSyncQueue;
use App\Catalog\Application\Service\MusicBrainzMatch\ArtistMatchWriter;
use App\Catalog\Domain\Model\Artist;
use App\Metadata\Application\Message\SyncArtistMessage;
use App\Metadata\Application\Message\SyncArtistsMessage;
use App\Shared\Application\JobCancelledException;
use App\Shared\Application\Port\CurrentJobInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\InMemoryQueuedArtistSyncs;
use App\Tests\Fixtures\Catalog\PassThroughTransaction;
use App\Tests\Fixtures\Messaging\CancelAtCheckpoint;
use App\Tests\Unit\Catalog\Application\QueryHandler\MusicBrainzMatch\FakeMusicBrainzLookup;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/** The admin sync's walk that queues the fill of every identified artist with an empty detail. */
final class QueueArtistSyncsHandlerTest extends TestCase
{
    /** @var array<string, string> the MusicBrainz ID of each artist the catalog query finds, by artist ID */
    private array $identified = [];
    /** @var list<Envelope> */
    private array $sent = [];
    private InMemoryQueuedArtistSyncs $marks;

    protected function setUp(): void
    {
        $this->marks = new InMemoryQueuedArtistSyncs();
    }

    public function testEveryArtistTheQueryFindsIsQueuedOnceInTwoSecondSteps(): void
    {
        $artists = $this->identify(3);

        $queued = $this->handler()(new SyncArtistsMessage());

        self::assertSame(3, $queued);
        self::assertSame($artists, array_map(static fn (Envelope $sent): string => $sent->getMessage()->artistId->toString(), $this->sent));
        self::assertSame([0, 2_000, 4_000], array_map(static fn (Envelope $sent): ?int => $sent->last(DelayStamp::class)?->getDelay(), $this->sent));
        $runs = array_map(static fn (Envelope $sent): ?string => $sent->getMessage()->runId?->toString(), $this->sent) |> array_unique(...);
        self::assertCount(1, $runs, 'Every sync names the one run.');
        self::assertSame(['sync-job'], array_keys($this->marks->jobRuns));
        self::assertSame(array_first($runs), $this->marks->jobRuns['sync-job'][0]);
    }

    public function testTheWalkReadsEveryPageOfTheQuery(): void
    {
        $this->identify(1_001);

        self::assertSame(1_001, $this->handler()(new SyncArtistsMessage()));
        self::assertCount(1_001, array_unique(array_map(static fn (Envelope $sent): string => $sent->getMessage()->artistId->toString(), $this->sent)));
    }

    public function testAnArtistWithAQueuedMarkOrAnAnswerMarkForItsIdIsNotQueued(): void
    {
        [$queued, $answered, $answeredBefore, $free] = $this->identify(4);
        $this->marks->queued[$queued] = 60;
        $this->marks->markAnswered(Uuid::fromString($answered), $this->identified[$answered], ArtistSyncQueue::ANSWER_TTL_SECONDS);
        $this->marks->markAnswered(Uuid::fromString($answeredBefore), '0b4f1a46-4a2e-4f3a-9d6c-6a3e2b7f1c11', ArtistSyncQueue::ANSWER_TTL_SECONDS);

        self::assertSame(2, $this->handler()(new SyncArtistsMessage()));
        self::assertSame([$answeredBefore, $free], array_map(static fn (Envelope $sent): string => $sent->getMessage()->artistId->toString(), $this->sent));
    }

    public function testACancelledJobStopsBeforeItsNextArtistAndCancelsWhatItQueued(): void
    {
        $this->identify(3);
        $checkpoint = new CancelAtCheckpoint(passes: 2);

        try {
            $this->handler($checkpoint)(new SyncArtistsMessage());
            self::fail('A cancelled artist sync stops.');
        } catch (JobCancelledException) {
        }

        self::assertCount(2, $this->sent);
        self::assertSame(3, $checkpoint->checks);
        self::assertSame([$this->sent[0]->getMessage()->runId?->toString()], array_keys($this->marks->cancelledRuns));
        self::assertSame([], $this->marks->jobRuns);
    }

    /**
     * A second sync started while the first one's syncs are queued queues none of their artists.
     * Cancelling the first sync's job after it finished makes its syncs end without a request, and
     * a sync queued after the cancel falls due at once.
     */
    public function testASecondSyncSkipsTheQueuedArtistsAndACancelAfterTheFirstFinishedEndsItsSyncs(): void
    {
        $this->identify(2);
        $this->handler()(new SyncArtistsMessage());
        $first = $this->sent;
        $this->sent = [];

        self::assertSame(0, $this->handler()(new SyncArtistsMessage()));

        self::assertTrue($this->marks->cancelQueuedWork('sync-job'));
        $musicBrainz = new FakeMusicBrainzLookup();
        foreach ($first as $sync) {
            $this->syncHandler($musicBrainz)($sync->getMessage());
        }
        self::assertSame([], $musicBrainz->calls);

        $this->sent = [];
        $this->identify(1);
        self::assertSame(3, $this->handler()(new SyncArtistsMessage()), 'The cancelled syncs asked nothing, so their artists are queued again.');
        self::assertSame([0, 2_000, 4_000], array_map(static fn (Envelope $sent): ?int => $sent->last(DelayStamp::class)?->getDelay(), $this->sent));
    }

    /** @return list<string> the IDs of the artists added, in ID order */
    private function identify(int $count): array
    {
        $added = [];
        foreach (range(1, $count) as $index) {
            $id = Uuid::v7()->toString();
            $this->identified[$id] = sprintf('6c1e0000-0000-4000-8000-%012d', $index);
            $added[] = $id;
        }
        ksort($this->identified);

        return $added;
    }

    private function handler(?CancelAtCheckpoint $checkpoint = null): QueueArtistSyncsHandler
    {
        $artists = $this->createStub(ArtistPortInterface::class);
        $artists->method('identifiedWithMissingDetailsAfter')->willReturnCallback(function (?Uuid $after, int $limit): array {
            $later = array_filter($this->identified, static fn (string $id): bool => $after === null || strcmp($id, $after->toString()) > 0, ARRAY_FILTER_USE_KEY);

            return array_slice($later, 0, $limit, true);
        });
        $currentJob = $this->createStub(CurrentJobInterface::class);
        $currentJob->method('currentJobId')->willReturn('sync-job');

        return new QueueArtistSyncsHandler($artists, new ArtistSyncQueue($this->marks, $this->bus()), $this->marks, $checkpoint ?? new CancelAtCheckpoint(), $currentJob, new NullLogger());
    }

    /** The per-artist sync over the same marks, for an artist the test never stored. */
    private function syncHandler(FakeMusicBrainzLookup $musicBrainz): SyncArtistHandler
    {
        $artists = $this->createStub(ArtistPortInterface::class);
        $artists->method('findFreshByUuid')->willReturnCallback(static fn (Uuid $id): Artist => Artist::create('Post Malone', mbid: 'b1e26560-60e5-4236-bbdb-9aa5a8d5ee19'));

        return new SyncArtistHandler(
            $artists,
            $this->createStub(ArtistMergePortInterface::class),
            $musicBrainz,
            new MatchComparisonBuilder($this->createStub(MatchCatalogReadInterface::class), $artists),
            new ArtistMatchWriter($artists),
            $this->marks,
            new ArtistSyncQueue($this->marks, $this->bus()),
            new PassThroughTransaction(),
            new NullLogger(),
        );
    }

    private function bus(): MessageBusInterface
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message, array $stamps = []): Envelope {
            self::assertInstanceOf(SyncArtistMessage::class, $message);
            $envelope = new Envelope($message, $stamps);
            $this->sent[] = $envelope;

            return $envelope;
        });

        return $bus;
    }
}
