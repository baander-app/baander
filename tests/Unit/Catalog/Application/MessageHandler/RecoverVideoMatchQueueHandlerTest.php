<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\MessageHandler;

use App\Catalog\Application\Command\RecoverVideoMatchQueueCommand;
use App\Catalog\Application\Message\DrainVideoMatchesMessage;
use App\Catalog\Application\MessageHandler\RecoverVideoMatchQueueHandler;
use App\Catalog\Application\Port\VideoMatchTargetLoaderInterface;
use App\Catalog\Application\Service\VideoMatch\LibraryProviderOrderService;
use App\Catalog\Application\Service\VideoMatch\VideoMatchRequester;
use App\Catalog\Application\Service\VideoMatch\VideoMatchTarget;
use App\Catalog\Domain\Model\VideoMatchState;
use App\Catalog\Domain\Repository\LibraryProviderOrderRepositoryInterface;
use App\Catalog\Domain\Repository\VideoMatchStateRepositoryInterface;
use App\Library\Application\Port\LibraryTypeLookupInterface;
use App\Library\Domain\ValueObject\LibraryType;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Metadata\Application\DTO\Video\VideoSidecar;
use App\Metadata\Application\DTO\Video\VideoSidecarKind;
use App\Metadata\Application\Port\VideoMetadataPortInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RecoverVideoMatchQueueHandlerTest extends TestCase
{
    public function testPersistentlyAwaitingTitlesDoNotStarveLaterPages(): void
    {
        $clock = new MockClock('2026-11-28T12:00:00+00:00');
        $library = Uuid::v7();
        $page = [];
        for ($index = 0; $index < 100; ++$index) {
            $page[] = new VideoMatchState(Uuid::v7(), Uuid::v7(), null, 'awaiting_provider', 'ingest', $clock->now());
        }
        $late = new VideoMatchState(Uuid::v7(), Uuid::v7(), null, 'awaiting_provider', 'ingest', $clock->now());
        $states = $this->createMock(VideoMatchStateRepositoryInterface::class);
        $states->expects(self::exactly(2))->method('awaitingProviders')->willReturnCallback(static function (int $limit, ?Uuid $cursor) use ($page, $late): array {
            self::assertSame(100, $limit);
            if ($cursor === null) {
                return $page;
            }
            self::assertTrue($cursor->equals($page[99]->id));
            return [$late];
        });
        $states->expects(self::once())->method('requestSeries')->with($late->seriesId, 'ingest', $clock->now())->willReturn(true);
        $states->method('nextRequestDueAt')->willReturn($clock->now());
        $targets = $this->createStub(VideoMatchTargetLoaderInterface::class);
        $targets->method('load')->willReturnCallback(static fn (VideoMatchState $state): VideoMatchTarget => new VideoMatchTarget(
            $state->seriesId,
            new VideoRequest(VideoKind::Series, 'Show', libraryId: $library, sidecars: $state->id->equals($late->id) ? [new VideoSidecar(VideoSidecarKind::SeriesNfo, '/library/tvshow.nfo')] : []),
            [],
            false,
        ));
        $providers = $this->createStub(VideoMetadataPortInterface::class);
        $providers->method('providers')->willReturn([VideoProviderId::Tvdb, VideoProviderId::Tmdb, VideoProviderId::Local]);
        $providers->method('usableProviders')->willReturn([VideoProviderId::Local]);
        $libraries = $this->createStub(LibraryTypeLookupInterface::class);
        $libraries->method('findType')->willReturn(LibraryType::TvShow);
        $orders = new LibraryProviderOrderService($libraries, $providers, $this->createStub(LibraryProviderOrderRepositoryInterface::class));
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(self::isInstanceOf(DrainVideoMatchesMessage::class))->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $requester = new VideoMatchRequester($targets, $states, $providers, $orders, $bus, $clock);
        $handler = new RecoverVideoMatchQueueHandler($states, $targets, $requester, $bus, $clock);

        self::assertSame('video match queue started', $handler(new RecoverVideoMatchQueueCommand()));
    }

    public function testEnablingAProviderLetsRecoveryQueueTheAwaitingMovie(): void
    {
        $clock = new MockClock('2026-11-28T12:00:00+00:00');
        $state = new VideoMatchState(Uuid::v7(), null, Uuid::v7(), 'awaiting_provider', 'ingest', $clock->now());
        $progress = new \stdClass();
        $progress->requested = false;
        $states = $this->createMock(VideoMatchStateRepositoryInterface::class);
        $states->method('awaitingProviders')->willReturn([$state]);
        $states->expects(self::once())->method('requestMovie')->with($state->movieId, 'ingest', $clock->now())->willReturnCallback(static function () use ($progress): bool {
            $progress->requested = true;
            return true;
        });
        $states->expects(self::exactly(2))->method('nextRequestDueAt')->willReturnOnConsecutiveCalls(null, $clock->now());
        $targets = $this->createStub(VideoMatchTargetLoaderInterface::class);
        $targets->method('load')->willReturn(new VideoMatchTarget($state->movieId, new VideoRequest(VideoKind::Movie, 'Heat', libraryId: Uuid::v7()), [], false));
        $providers = $this->createStub(VideoMetadataPortInterface::class);
        $providers->method('providers')->willReturn([VideoProviderId::Tmdb, VideoProviderId::Local]);
        $providers->method('usableProviders')->willReturnOnConsecutiveCalls([], [VideoProviderId::Tmdb], [VideoProviderId::Tmdb]);
        $libraries = $this->createStub(LibraryTypeLookupInterface::class);
        $libraries->method('findType')->willReturn(LibraryType::Movie);
        $orders = new LibraryProviderOrderService($libraries, $providers, $this->createStub(LibraryProviderOrderRepositoryInterface::class));
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $requester = new VideoMatchRequester($targets, $states, $providers, $orders, $bus, $clock);
        $handler = new RecoverVideoMatchQueueHandler($states, $targets, $requester, $bus, $clock);

        self::assertSame('no video match due', $handler(new RecoverVideoMatchQueueCommand()));
        self::assertFalse($progress->requested);
        self::assertSame('video match queue started', $handler(new RecoverVideoMatchQueueCommand()));
        self::assertTrue($progress->requested);
    }
}
