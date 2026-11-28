<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\Service\VideoMatch;

use App\Catalog\Application\Message\DrainVideoMatchesMessage;
use App\Catalog\Application\Port\VideoMatchTargetLoaderInterface;
use App\Catalog\Application\Service\VideoMatch\LibraryProviderOrderService;
use App\Catalog\Application\Service\VideoMatch\VideoMatchRequester;
use App\Catalog\Application\Service\VideoMatch\VideoMatchTarget;
use App\Catalog\Domain\Repository\LibraryProviderOrderRepositoryInterface;
use App\Catalog\Domain\Repository\VideoMatchStateRepositoryInterface;
use App\Library\Application\Port\LibraryTypeLookupInterface;
use App\Library\Domain\ValueObject\LibraryType;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Metadata\Application\Port\VideoMetadataPortInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class VideoMatchRequesterTest extends TestCase
{
    public function testAnImportWithoutAUsableProviderRecordsAwaitingAndDispatchesNothing(): void
    {
        $library = Uuid::v7();
        $title = Uuid::v7();
        $clock = new MockClock('2026-11-28T12:00:00+00:00');
        $targets = $this->createStub(VideoMatchTargetLoaderInterface::class);
        $targets->method('forObservations')->willReturn([new VideoMatchTarget($title, new VideoRequest(VideoKind::Movie, 'Heat', libraryId: $library, titleId: $title), [], false)]);
        $states = $this->createMock(VideoMatchStateRepositoryInterface::class);
        $states->expects(self::once())->method('markAwaitingMovie')->with($title, 'ingest', $clock->now());
        $states->expects(self::never())->method('requestMovie');
        $providers = $this->providers([VideoProviderId::Local]);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        new VideoMatchRequester($targets, $states, $providers, $this->orders($providers), $bus, $clock)->requestAfterImport($library, ['Heat/movie.mp4']);
    }

    public function testAnImportRequestsMatchingWhenTheOnlineProviderIsUsable(): void
    {
        $library = Uuid::v7();
        $title = Uuid::v7();
        $clock = new MockClock('2026-11-28T12:00:00+00:00');
        $targets = $this->createStub(VideoMatchTargetLoaderInterface::class);
        $targets->method('forObservations')->willReturn([new VideoMatchTarget($title, new VideoRequest(VideoKind::Movie, 'Heat', libraryId: $library, titleId: $title), [], false)]);
        $states = $this->createMock(VideoMatchStateRepositoryInterface::class);
        $states->expects(self::once())->method('requestMovie')->with($title, 'ingest', $clock->now())->willReturn(true);
        $providers = $this->providers([VideoProviderId::Tmdb]);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(self::isInstanceOf(DrainVideoMatchesMessage::class))->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        new VideoMatchRequester($targets, $states, $providers, $this->orders($providers), $bus, $clock)->requestAfterImport($library, ['Heat/movie.mp4']);
    }

    public function testASidecarRescanDoesNotRequeueAnAlreadyMatchedTitle(): void
    {
        $library = Uuid::v7();
        $title = Uuid::v7();
        $targets = $this->createStub(VideoMatchTargetLoaderInterface::class);
        $targets->method('forObservations')->willReturn([new VideoMatchTarget($title, new VideoRequest(VideoKind::Movie, 'Heat', libraryId: $library), [], true)]);
        $states = $this->createMock(VideoMatchStateRepositoryInterface::class);
        $states->expects(self::never())->method('requestMovie');
        $states->expects(self::never())->method('markAwaitingMovie');
        $providers = $this->createMock(VideoMetadataPortInterface::class);
        $providers->expects(self::never())->method('usableProviders');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        new VideoMatchRequester($targets, $states, $providers, $this->orders($providers), $bus, new MockClock())->requestAfterImport($library, ['Heat/movie.nfo']);
    }

    /** @param list<VideoProviderId> $usable */
    private function providers(array $usable): VideoMetadataPortInterface
    {
        $providers = $this->createStub(VideoMetadataPortInterface::class);
        $providers->method('providers')->willReturn([VideoProviderId::Tmdb, VideoProviderId::Local]);
        $providers->method('usableProviders')->willReturn($usable);

        return $providers;
    }

    private function orders(VideoMetadataPortInterface $providers): LibraryProviderOrderService
    {
        $libraries = $this->createStub(LibraryTypeLookupInterface::class);
        $libraries->method('findType')->willReturn(LibraryType::Movie);

        return new LibraryProviderOrderService($libraries, $providers, $this->createStub(LibraryProviderOrderRepositoryInterface::class));
    }
}
