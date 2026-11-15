<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\Service;

use App\Catalog\Application\MessageHandler\QueueArtistSyncsHandler;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Port\IdentifiedArtist;
use App\Catalog\Application\Service\ArtistSyncQueue;
use App\Catalog\Application\Service\ArtistSyncRequester;
use App\Metadata\Application\Message\SyncArtistMessage;
use App\Metadata\Application\Message\SyncArtistsMessage;
use App\Metadata\Application\Settings\MetadataSettingDefinitions;
use App\Shared\Application\Port\CurrentJobInterface;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\InMemoryQueuedArtistSyncs;
use App\Tests\Fixtures\Messaging\CancelAtCheckpoint;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * The fills a match apply or an import batch queues for the artists it identified, through the
 * marks and the shared pace every producer uses, with the store and the transport in memory.
 */
final class ArtistSyncRequesterTest extends TestCase
{
    private const string HEADHUNTERZ_ID = '6c1e0000-0000-4000-8000-000000000001';
    private const string KREWELLA_ID = '6c1e0000-0000-4000-8000-000000000002';

    private InMemoryQueuedArtistSyncs $marks;
    private bool $automaticMatching = true;
    /** @var list<Envelope> */
    private array $sent = [];
    /** Fails every dispatch from the given one on, counted from one. */
    private ?int $failFromDispatch = null;
    /** @var list<array{string, array<string, mixed>}> the message and context of each entry logged */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->marks = new InMemoryQueuedArtistSyncs();
    }

    public function testAnImportQueuesOneFillForEachArtistItIdentifiedTwoSecondsApart(): void
    {
        [$headhunterz, $krewella] = [self::artist(self::HEADHUNTERZ_ID), self::artist(self::KREWELLA_ID)];

        $this->requester()->requestAfterImport($headhunterz, $krewella);

        self::assertSame([$headhunterz->artistId->toString(), $krewella->artistId->toString()], $this->queuedArtists());
        self::assertSame([0, 2_000], $this->delays());
        self::assertSame([null, null], array_map(static fn (Envelope $sent): ?Uuid => $sent->getMessage()->runId, $this->sent), 'A fill a writer queues belongs to no admin sync run.');
    }

    public function testWhileAutomaticMatchingIsOffAnImportQueuesNoFillAndAMatchApplyStillDoes(): void
    {
        $this->automaticMatching = false;
        $artist = self::artist(self::HEADHUNTERZ_ID);

        $this->requester()->requestAfterImport($artist);
        self::assertSame([], $this->queuedArtists());

        $this->requester()->requestAfterMatch($artist);
        self::assertSame([$artist->artistId->toString()], $this->queuedArtists());
    }

    public function testAnArtistWithAQueuedFillOrAnAnswerForItsIdIsNotQueuedAgain(): void
    {
        [$queued, $answered, $answeredBefore] = [self::artist(self::HEADHUNTERZ_ID), self::artist(self::KREWELLA_ID), self::artist(self::KREWELLA_ID)];
        $this->requester()->requestAfterMatch($queued);
        $this->marks->markAnswered($answered->artistId, self::KREWELLA_ID, ArtistSyncQueue::ANSWER_TTL_SECONDS);
        $this->marks->markAnswered($answeredBefore->artistId, self::HEADHUNTERZ_ID, ArtistSyncQueue::ANSWER_TTL_SECONDS);

        $this->requester()->requestAfterImport($queued, $answered, $answeredBefore);

        self::assertSame(
            [$queued->artistId->toString(), $answeredBefore->artistId->toString()],
            $this->queuedArtists(),
            'An artist MusicBrainz answered about under another ID is asked about the ID it holds now.',
        );
    }

    public function testAFailureToQueueIsLoggedWithTheArtistsNotQueuedAndGoesNoFurther(): void
    {
        $this->failFromDispatch = 2;
        [$first, $second, $third] = [self::artist(self::HEADHUNTERZ_ID), self::artist(self::KREWELLA_ID), self::artist(self::KREWELLA_ID)];

        $this->requester()->requestAfterMatch($first, $second, $third);

        self::assertSame([$first->artistId->toString()], $this->queuedArtists());
        self::assertCount(1, $this->logged);
        [$message, $context] = $this->logged[0];
        self::assertSame('Could not queue the MusicBrainz fill of {count} artist(s); a later metadata sync from the admin panel queues them.', $message);
        self::assertSame([2, [$second->artistId->toString(), $third->artistId->toString()]], [$context['count'], $context['artist_ids']]);
        self::assertInstanceOf(\RuntimeException::class, $context['exception']);
    }

    public function testAnArtistWhoseFillCouldNotBeSentIsQueuedByTheNextProducerOnceTheTransportAnswers(): void
    {
        $this->failFromDispatch = 1;
        $artist = self::artist(self::HEADHUNTERZ_ID);

        $this->requester()->requestAfterMatch($artist);

        self::assertSame([], $this->queuedArtists(), 'The transport refused the fill.');
        $this->failFromDispatch = null;

        $this->requester()->requestAfterImport($artist);

        self::assertSame([$artist->artistId->toString()], $this->queuedArtists(), 'Nothing marks the artist as queued while no fill is on its way.');
    }

    public function testFillsAnImportAndAnAdminSyncQueueTogetherFallDueAtLeastTwoSecondsApart(): void
    {
        $catalog = [Uuid::v7()->toString() => '6c1e0000-0000-4000-8000-000000000011', Uuid::v7()->toString() => '6c1e0000-0000-4000-8000-000000000012'];
        $artists = $this->createStub(ArtistPortInterface::class);
        $artists->method('identifiedWithMissingDetailsAfter')->willReturnCallback(
            static fn (?Uuid $after): array => $after === null ? $catalog : [],
        );
        $adminSync = new QueueArtistSyncsHandler($artists, new ArtistSyncQueue($this->marks, $this->bus()), $this->marks, new CancelAtCheckpoint(), $this->createStub(CurrentJobInterface::class), new NullLogger());

        $this->requester()->requestAfterImport(self::artist(self::HEADHUNTERZ_ID));
        $adminSync(new SyncArtistsMessage());
        $this->requester()->requestAfterImport(self::artist(self::KREWELLA_ID));

        self::assertSame([0, 2_000, 4_000, 6_000], $this->delays());
    }

    private static function artist(string $musicbrainzId): IdentifiedArtist
    {
        return new IdentifiedArtist(Uuid::v7(), $musicbrainzId);
    }

    private function requester(): ArtistSyncRequester
    {
        $settings = $this->createStub(SystemSettingsPortInterface::class);
        $settings->method('get')->willReturnCallback(fn (string $key): bool => $key === MetadataSettingDefinitions::AUTO_SYNC && $this->automaticMatching);
        $record = function (string $message, array $context): void {
            $this->logged[] = [$message, $context];
        };
        $logger = new class ($record) extends AbstractLogger {
            public function __construct(private readonly \Closure $record)
            {
            }

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                ($this->record)((string) $message, $context);
            }
        };

        return new ArtistSyncRequester(new ArtistSyncQueue($this->marks, $this->bus()), $settings, $logger);
    }

    private function bus(): MessageBusInterface
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message, array $stamps = []): Envelope {
            self::assertInstanceOf(SyncArtistMessage::class, $message);
            $dispatch = count($this->sent) + 1;
            if ($this->failFromDispatch !== null && $dispatch >= $this->failFromDispatch) {
                throw new \RuntimeException('The transport cannot be reached.');
            }
            $envelope = new Envelope($message, $stamps);
            $this->sent[] = $envelope;

            return $envelope;
        });

        return $bus;
    }

    /** @return list<string> the artist of each fill queued, in order */
    private function queuedArtists(): array
    {
        return array_map(static fn (Envelope $sent): string => $sent->getMessage()->artistId->toString(), $this->sent);
    }

    /** @return list<int|null> the delay of each fill queued, in milliseconds */
    private function delays(): array
    {
        return array_map(static fn (Envelope $sent): ?int => $sent->last(DelayStamp::class)?->getDelay(), $this->sent);
    }
}
