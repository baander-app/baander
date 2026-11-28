<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\Service\VideoMatch;

use App\Catalog\Application\CommandHandler\VideoMatch\ApplyVideoMatchHandler;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\PersonCreditPortInterface;
use App\Catalog\Domain\Repository\MovieRepositoryInterface;
use App\Catalog\Domain\Repository\TvCatalogRepositoryInterface;
use App\Media\Application\Port\VideoArtworkImportInterface;
use App\Metadata\Application\Port\PersonProfileSyncRequestInterface;
use App\Catalog\Application\Dto\ResolvedVideoMatch;
use App\Catalog\Application\Port\VideoMatchApplyInterface;
use App\Catalog\Application\Port\VideoMatchTargetLoaderInterface;
use App\Catalog\Application\Service\VideoMatch\AutomaticVideoMatcher;
use App\Catalog\Application\Service\VideoMatch\LibraryProviderOrderService;
use App\Catalog\Application\Service\VideoMatch\VideoMatchConfidence;
use App\Catalog\Application\Service\VideoMatch\VideoMatchOutcomeKind;
use App\Catalog\Application\Service\VideoMatch\VideoMatchStructureLookup;
use App\Catalog\Application\Service\VideoMatch\VideoMatchTarget;
use App\Catalog\Domain\Model\LibraryProviderOrder;
use App\Catalog\Domain\Model\VideoMatchState;
use App\Catalog\Domain\Model\VideoProviderPause;
use App\Catalog\Domain\Repository\LibraryProviderOrderRepositoryInterface;
use App\Catalog\Domain\Repository\VideoProviderPauseRepositoryInterface;
use App\Library\Application\Port\LibraryTypeLookupInterface;
use App\Library\Domain\ValueObject\LibraryType;
use App\Metadata\Application\DTO\Video\VideoCandidate;
use App\Metadata\Application\DTO\Video\VideoEpisode;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoOrdering;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoProviderProblem;
use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Metadata\Application\DTO\Video\VideoSeason;
use App\Metadata\Application\DTO\Video\VideoSidecar;
use App\Metadata\Application\DTO\Video\VideoSidecarKind;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Metadata\Application\Port\VideoMetadataPortInterface;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class AutomaticVideoMatcherTest extends TestCase
{
    private VideoMetadataPortInterface&MockObject $providers;
    private VideoProviderPauseRepositoryInterface&MockObject $pauses;
    private VideoMatchApplyInterface&MockObject $apply;
    private VideoMatchTargetLoaderInterface&MockObject $targets;
    private MockClock $clock;
    private Uuid $library;
    private Uuid $title;

    protected function setUp(): void
    {
        $this->providers = $this->createMock(VideoMetadataPortInterface::class);
        $this->pauses = $this->createMock(VideoProviderPauseRepositoryInterface::class);
        $this->apply = $this->createMock(VideoMatchApplyInterface::class);
        $this->targets = $this->createMock(VideoMatchTargetLoaderInterface::class);
        $this->clock = new MockClock('2026-11-28T12:00:00+00:00');
        $this->library = Uuid::v7();
        $this->title = Uuid::v7();
    }

    public function testAnUnavailableFirstProviderDoesNotFallThrough(): void
    {
        $this->pauses->expects(self::once())->method('find')->with('tvdb')->willReturn(null);
        $this->providers->method('usableProviders')->willReturn([VideoProviderId::Tvdb, VideoProviderId::Tmdb]);
        $this->providers->expects(self::once())->method('search')->with(VideoProviderId::Tvdb)->willReturn(VideoProviderProblem::Unavailable);
        $this->apply->expects(self::never())->method('apply');

        $outcome = $this->matcher(VideoKind::Series)->match($this->claim(VideoKind::Series));

        self::assertSame(VideoMatchOutcomeKind::Unavailable, $outcome->kind);
        self::assertSame(VideoProviderId::Tvdb, $outcome->provider);
    }

    public function testAFailedLoginPausesItsProviderWithoutSearchingTheNextOne(): void
    {
        $this->pauses->expects(self::once())->method('find')->with('tvdb')->willReturn(null);
        $this->providers->method('usableProviders')->willReturn([VideoProviderId::Tvdb, VideoProviderId::Tmdb]);
        $this->providers->expects(self::once())->method('search')->with(VideoProviderId::Tvdb)->willReturn(VideoProviderProblem::LoginFailed);
        $this->apply->expects(self::never())->method('apply');

        $outcome = $this->matcher(VideoKind::Series)->match($this->claim(VideoKind::Series));

        self::assertSame(VideoMatchOutcomeKind::Unavailable, $outcome->kind);
        self::assertSame(VideoProviderId::Tvdb, $outcome->provider);
        self::assertSame('login_failed', $outcome->reason);
    }

    public function testAnActiveProviderPauseReturnsTheTitleUntilThatPauseEnds(): void
    {
        $this->apply->expects(self::never())->method('apply');
        $retry = $this->clock->now()->modify('+15 minutes');
        $this->providers->method('usableProviders')->willReturn([VideoProviderId::Tvdb, VideoProviderId::Tmdb]);
        $this->pauses->expects(self::once())->method('find')->with('tvdb')->willReturn(new VideoProviderPause('tvdb', 'unavailable', 2, $retry));
        $this->providers->expects(self::never())->method('search');

        $outcome = $this->matcher(VideoKind::Series)->match($this->claim(VideoKind::Series));

        self::assertSame(VideoMatchOutcomeKind::RetryLater, $outcome->kind);
        self::assertSame($retry, $outcome->retryAt);
    }

    public function testThePausedProviderMatchesAgainAtItsRetryDeadline(): void
    {
        $retry = $this->clock->now()->modify('+15 minutes');
        $this->clock->sleep(15 * 60);
        $metadata = new VideoTitle(new VideoCandidate('121361', 'Heat', 1995), orderings: [new VideoOrdering('aired', 'Aired', 'aired')]);
        $this->pauses->expects(self::once())->method('find')->with('tvdb')->willReturn(new VideoProviderPause('tvdb', 'unavailable', 1, $retry));
        $this->pauses->expects(self::once())->method('clear')->with('tvdb');
        $this->providers->method('usableProviders')->willReturn([VideoProviderId::Tvdb, VideoProviderId::Tmdb]);
        $this->providers->expects(self::once())->method('search')->with(VideoProviderId::Tvdb)->willReturn([$metadata->entry]);
        $this->providers->expects(self::once())->method('identify')->with(VideoProviderId::Tvdb)->willReturn($metadata);
        $this->apply->expects(self::once())->method('apply')->with(self::anything(), self::anything(), VideoProviderId::Tvdb, new ResolvedVideoMatch($metadata, 'aired'))->willReturn(true);

        $outcome = $this->matcher(VideoKind::Series)->match($this->claim(VideoKind::Series));

        self::assertEquals($retry, $this->clock->now());
        self::assertSame(VideoMatchOutcomeKind::Matched, $outcome->kind);
        self::assertSame(VideoProviderId::Tvdb, $outcome->provider);
    }

    public function testUsableProvidersWithNoResultsLeaveTheSeriesForReview(): void
    {
        $calls = [];
        $this->providers->method('usableProviders')->willReturn([VideoProviderId::Tvdb, VideoProviderId::Tmdb]);
        $this->pauses->expects(self::exactly(2))->method('find')->willReturn(null);
        $this->pauses->expects(self::exactly(2))->method('clear');
        $this->providers->expects(self::exactly(2))->method('search')->willReturnCallback(static function (VideoProviderId $provider, VideoRequest $request) use (&$calls): array {
            $calls[] = $provider;
            self::assertSame(VideoKind::Series, $request->kind);
            self::assertSame('New Show', $request->title);

            return [];
        });
        $this->providers->expects(self::never())->method('identify');
        $this->apply->expects(self::never())->method('apply');
        $request = new VideoRequest(VideoKind::Series, 'New Show', libraryId: $this->library, titleId: $this->title);

        $outcome = $this->matcher(VideoKind::Series, request: $request)->match($this->claim(VideoKind::Series));

        self::assertSame([VideoProviderId::Tvdb, VideoProviderId::Tmdb], $calls);
        self::assertSame(VideoMatchOutcomeKind::NeedsReview, $outcome->kind);
        self::assertSame('no_candidates', $outcome->reason);
        self::assertNull($outcome->score);
    }

    public function testAnUnusableLocalSidecarFallsThroughToTheNextProvider(): void
    {
        $metadata = new VideoTitle(new VideoCandidate('121361', 'Heat', 1995), orderings: [new VideoOrdering('aired', 'Aired', 'aired')]);
        $this->providers->method('usableProviders')->willReturn([VideoProviderId::Local, VideoProviderId::Tvdb, VideoProviderId::Tmdb]);
        $this->pauses->expects(self::once())->method('find')->with('tvdb')->willReturn(null);
        $this->providers->expects(self::once())->method('search')->with(VideoProviderId::Tvdb)->willReturn([$metadata->entry]);
        $identified = [];
        $this->providers->expects(self::exactly(2))->method('identify')->willReturnCallback(static function (VideoProviderId $provider, VideoRequest $request) use ($metadata, &$identified): ?VideoTitle {
            $identified[] = $provider;
            if ($provider === VideoProviderId::Local) {
                self::assertCount(1, $request->sidecars);

                return null;
            }
            self::assertSame(VideoProviderId::Tvdb, $provider);

            return $metadata;
        });
        $this->apply->expects(self::once())->method('apply')->with(self::anything(), self::anything(), VideoProviderId::Tvdb, new ResolvedVideoMatch($metadata, 'aired'))->willReturn(true);
        $sidecars = [new VideoSidecar(VideoSidecarKind::SeriesNfo, '/library/tvshow.nfo')];

        $outcome = $this->matcher(VideoKind::Series, order: ['local', 'tvdb', 'tmdb'], sidecars: $sidecars)->match($this->claim(VideoKind::Series));

        self::assertSame(VideoMatchOutcomeKind::Matched, $outcome->kind);
        self::assertSame([VideoProviderId::Local, VideoProviderId::Tvdb], $identified);
    }

    public function testTheMovieProviderTagIdentifiesTheMatrixWithoutSearching(): void
    {
        $metadata = new VideoTitle(new VideoCandidate('603', 'The Matrix', 1999));
        $request = new VideoRequest(VideoKind::Movie, 'The Matrix', 1999, ['tmdb' => '603'], libraryId: $this->library, titleId: $this->title);
        $this->providers->method('usableProviders')->willReturn([VideoProviderId::Tmdb]);
        $this->pauses->expects(self::once())->method('find')->with('tmdb')->willReturn(null);
        $this->providers->expects(self::never())->method('search');
        $this->providers->expects(self::once())->method('identify')->with(VideoProviderId::Tmdb, $request)->willReturn($metadata);
        $this->apply->expects(self::once())->method('apply')->with(self::anything(), self::anything(), VideoProviderId::Tmdb, new ResolvedVideoMatch($metadata, 'aired'))->willReturn(true);

        $outcome = $this->matcher(VideoKind::Movie, request: $request)->match($this->claim(VideoKind::Movie));

        self::assertSame(VideoMatchOutcomeKind::Matched, $outcome->kind);
        self::assertSame(VideoProviderId::Tmdb, $outcome->provider);
    }

    public function testAMovieStillMatchesWhileTheTvProviderIsPaused(): void
    {
        $candidate = new VideoCandidate('949', 'Heat', 1995);
        $metadata = new VideoTitle($candidate);
        $this->providers->method('usableProviders')->willReturn([VideoProviderId::Local, VideoProviderId::Tmdb]);
        $this->providers->expects(self::once())->method('search')->with(
            VideoProviderId::Tmdb,
            self::callback(static fn (VideoRequest $request): bool => $request->kind === VideoKind::Movie && $request->title === 'Heat' && $request->year === 1995),
        )->willReturn([$candidate]);
        $this->providers->expects(self::once())->method('identify')->with(VideoProviderId::Tmdb)->willReturn($metadata);
        $this->pauses->expects(self::once())->method('find')->willReturnCallback(static fn (string $provider): ?VideoProviderPause => $provider === 'tvdb'
            ? new VideoProviderPause('tvdb', 'unavailable', 2, new DateTimeImmutable('+1 hour')) : null);
        $this->apply->expects(self::once())->method('apply')->with(self::isInstanceOf(VideoMatchState::class), self::isInstanceOf(VideoMatchTarget::class), VideoProviderId::Tmdb, new ResolvedVideoMatch($metadata, 'aired'))->willReturn(true);

        $outcome = $this->matcher(VideoKind::Movie, order: ['local', 'tmdb'])->match($this->claim(VideoKind::Movie));

        self::assertSame(VideoMatchOutcomeKind::Matched, $outcome->kind);
    }

    public function testAnUnconfiguredOnlineProviderAndAbsentNfoLeaveTheTitleAwaiting(): void
    {
        $this->apply->expects(self::never())->method('apply');
        $this->pauses->expects(self::never())->method('find');
        $this->providers->method('usableProviders')->willReturn([VideoProviderId::Local]);
        $this->providers->expects(self::never())->method('identify');
        $this->providers->expects(self::never())->method('search');

        self::assertSame(VideoMatchOutcomeKind::AwaitingProvider, $this->matcher(VideoKind::Movie)->match($this->claim(VideoKind::Movie))->kind);
    }

    public function testAProviderTagResolvesThroughThePreferredProviderWithoutSearching(): void
    {
        $this->pauses->expects(self::once())->method('find')->with('tvdb')->willReturn(null);
        $metadata = new VideoTitle(new VideoCandidate('121361', 'Heat', 1995), orderings: [new VideoOrdering('aired', 'Aired', 'aired')]);
        $this->providers->method('usableProviders')->willReturn([VideoProviderId::Tvdb, VideoProviderId::Tmdb]);
        $this->providers->expects(self::never())->method('search');
        $this->providers->expects(self::once())->method('identify')->with(VideoProviderId::Tvdb, self::callback(static fn (VideoRequest $request): bool => $request->externalIds === ['tmdb' => '1399']))->willReturn($metadata);
        $this->apply->expects(self::once())->method('apply')->willReturn(true);

        self::assertSame(VideoMatchOutcomeKind::Matched, $this->matcher(VideoKind::Series, ids: ['tmdb' => '1399'], sidecars: [new VideoSidecar(VideoSidecarKind::SeriesNfo, '/library/tvshow.nfo')])->match($this->claim(VideoKind::Series))->kind);
    }

    public function testANativeTaggedProviderIsUsedWhenThePreferredProviderHasNoCounterpart(): void
    {
        $this->pauses->expects(self::exactly(2))->method('find')->willReturn(null);
        $metadata = new VideoTitle(new VideoCandidate('1399', 'Heat', 1995), orderings: [new VideoOrdering('aired', 'Aired', 'aired')]);
        $calls = [];
        $this->providers->method('usableProviders')->willReturn([VideoProviderId::Tvdb, VideoProviderId::Tmdb]);
        $this->providers->method('identify')->willReturnCallback(static function (VideoProviderId $provider) use ($metadata, &$calls): ?VideoTitle {
            $calls[] = $provider;
            return $provider === VideoProviderId::Tmdb ? $metadata : null;
        });
        $this->providers->expects(self::never())->method('search');
        $this->apply->expects(self::once())->method('apply')->with(self::anything(), self::anything(), VideoProviderId::Tmdb, new ResolvedVideoMatch($metadata, 'aired'))->willReturn(true);

        self::assertSame(VideoMatchOutcomeKind::Matched, $this->matcher(VideoKind::Series, ids: ['tmdb' => '1399'], sidecars: [new VideoSidecar(VideoSidecarKind::SeriesNfo, '/library/tvshow.nfo')])->match($this->claim(VideoKind::Series))->kind);
        self::assertSame([VideoProviderId::Tvdb, VideoProviderId::Tmdb], $calls);
    }

    public function testAlternateOrderingFetchesAiredStructureBeforeApplyWhenAbsoluteNumbersAreMissing(): void
    {
        $this->pauses->expects(self::once())->method('find')->with('tvdb')->willReturn(null);
        $candidate = new VideoCandidate('10', 'Heat', 1995);
        $offers = [new VideoOrdering('aired', 'Aired', 'aired'), new VideoOrdering('dvd-group', 'DVD', 'dvd')];
        $alternate = new VideoTitle($candidate, orderings: $offers, seasons: [
            new VideoSeason(1, episodes: [new VideoEpisode('99', 7, 'Episode')]),
        ]);
        $aired = new VideoTitle($candidate, orderings: $offers, seasons: [
            new VideoSeason(1, episodes: [new VideoEpisode('99', 1, 'Episode')]),
        ]);
        $this->providers->method('usableProviders')->willReturn([VideoProviderId::Tvdb]);
        $requests = [];
        $this->providers->expects(self::exactly(2))->method('identify')
            ->willReturnCallback(static function (VideoProviderId $provider, VideoRequest $request) use (&$requests, $alternate, $aired): VideoTitle {
                $requests[] = [$provider, $request->ordering, $request->externalIds];

                return $request->ordering === 'aired' ? $aired : $alternate;
            });
        $this->apply->expects(self::once())->method('apply')
            ->with(self::anything(), self::anything(), VideoProviderId::Tvdb, new ResolvedVideoMatch($alternate, 'dvd-group', $aired))
            ->willReturn(true);

        $result = $this->matcher(VideoKind::Series, ids: ['tvdb' => '10'], ordering: 'dvd-group')->match($this->claim(VideoKind::Series));

        self::assertSame(VideoMatchOutcomeKind::Matched, $result->kind);
        self::assertSame([
            [VideoProviderId::Tvdb, 'dvd-group', ['tvdb' => '10']],
            [VideoProviderId::Tvdb, 'aired', ['tvdb' => '10']],
        ], $requests);
    }

    public function testNfoIdentifiersAreUsedAtTheFirstOnlineProviderInTheLibraryOrder(): void
    {
        $this->pauses->expects(self::once())->method('find')->with('tvdb')->willReturn(null);
        $nfo = new VideoTitle(new VideoCandidate('local', 'Heat', 1995, ['tmdb' => '1399']));
        $online = new VideoTitle(new VideoCandidate('121361', 'Heat', 1995), orderings: [new VideoOrdering('aired', 'Aired', 'aired')]);
        $calls = [];
        $this->providers->method('usableProviders')->willReturn([VideoProviderId::Tvdb, VideoProviderId::Local]);
        $this->providers->method('identify')->willReturnCallback(static function (VideoProviderId $provider, VideoRequest $request) use ($nfo, $online, &$calls): VideoTitle {
            $calls[] = $provider;
            if ($provider === VideoProviderId::Tvdb) {
                self::assertSame(['tmdb' => '1399'], $request->externalIds);
            }
            return $provider === VideoProviderId::Local ? $nfo : $online;
        });
        $this->providers->expects(self::never())->method('search');
        $this->apply->expects(self::once())->method('apply')->with(self::anything(), self::anything(), VideoProviderId::Tvdb, new ResolvedVideoMatch($online, 'aired'))->willReturn(true);

        $matcher = $this->matcher(VideoKind::Series, sidecars: [new VideoSidecar(VideoSidecarKind::SeriesNfo, '/library/tvshow.nfo')]);
        self::assertSame(VideoMatchOutcomeKind::Matched, $matcher->match($this->claim(VideoKind::Series))->kind);
        self::assertSame([VideoProviderId::Local, VideoProviderId::Tvdb], $calls);
    }

    public function testNfoIdentifiersAreSkippedForOnlineSearchWhenLocalIsDisabled(): void
    {
        $candidate = new VideoCandidate('949', 'Heat', 1995);
        $metadata = new VideoTitle($candidate);
        $this->providers->method('usableProviders')->willReturn([VideoProviderId::Tmdb]);
        $this->providers->expects(self::once())->method('search')->with(VideoProviderId::Tmdb, self::callback(static fn (VideoRequest $request): bool => $request->externalIds === []))->willReturn([$candidate]);
        $this->providers->expects(self::once())->method('identify')->with(VideoProviderId::Tmdb)->willReturn($metadata);
        $this->pauses->expects(self::once())->method('find')->with('tmdb')->willReturn(null);
        $this->apply->expects(self::once())->method('apply')->willReturn(true);

        $matcher = $this->matcher(VideoKind::Movie, sidecars: [new VideoSidecar(VideoSidecarKind::MovieNfo, '/library/movie.nfo')]);

        self::assertSame(VideoMatchOutcomeKind::Matched, $matcher->match($this->claim(VideoKind::Movie))->kind);
    }

    public function testCloseSearchResultsRemainForReview(): void
    {
        $this->pauses->expects(self::once())->method('find')->with('tmdb')->willReturn(null);
        $hits = [new VideoCandidate('1', 'Heat', 1995), new VideoCandidate('2', 'Heat', 1995)];
        $this->providers->method('usableProviders')->willReturn([VideoProviderId::Tmdb]);
        $this->providers->expects(self::once())->method('search')->willReturn($hits);
        $this->providers->method('identify')->willReturnCallback(static fn (VideoProviderId $provider, VideoRequest $request): VideoTitle => new VideoTitle(new VideoCandidate($request->externalIds['tmdb'], 'Heat', 1995)));
        $this->apply->expects(self::never())->method('apply');

        $outcome = $this->matcher(VideoKind::Movie)->match($this->claim(VideoKind::Movie));

        self::assertSame(VideoMatchOutcomeKind::NeedsReview, $outcome->kind);
        self::assertSame('close_runner_up', $outcome->reason);
        self::assertSame(100, $outcome->score);
    }

    public function testAnIngestRequestNeverWalksProvidersForAnAlreadyMatchedTitle(): void
    {
        $this->pauses->expects(self::never())->method('find');
        $this->providers->expects(self::never())->method('usableProviders');
        $this->apply->expects(self::never())->method('apply');

        self::assertSame(VideoMatchOutcomeKind::Gone, $this->matcher(VideoKind::Movie, matched: true)->match($this->claim(VideoKind::Movie))->kind);
    }

    public function testALibraryRematchUsesTheNewProvidersDefaultAndKeepsTheOldOrderingAsItsApplyGuard(): void
    {
        $this->pauses->expects(self::once())->method('find')->with('tvdb')->willReturn(null);
        $metadata = new VideoTitle(new VideoCandidate('200', 'Heat', 1995), orderings: [new VideoOrdering('aired', 'Aired', 'aired')]);
        $this->providers->method('usableProviders')->willReturn([VideoProviderId::Tvdb, VideoProviderId::Tmdb]);
        $this->providers->expects(self::never())->method('search');
        $this->providers->expects(self::once())->method('identify')->with(
            VideoProviderId::Tvdb,
            self::callback(static fn (VideoRequest $request): bool => $request->ordering === 'aired' && $request->externalIds === ['tmdb' => '100']),
        )->willReturn($metadata);
        $this->apply->expects(self::once())->method('apply')->with(
            self::isInstanceOf(VideoMatchState::class),
            self::callback(static fn (VideoMatchTarget $target): bool => $target->request->ordering === 'dvd'),
            VideoProviderId::Tvdb,
            new ResolvedVideoMatch($metadata, 'aired'),
        )->willReturn(true);

        $outcome = $this->matcher(VideoKind::Series, ids: ['tmdb' => '100'], matched: true, ordering: 'dvd')->match($this->claim(VideoKind::Series, 'admin'));

        self::assertSame(VideoMatchOutcomeKind::Matched, $outcome->kind);
    }

    /**
     * @param list<string>|null $order
     * @param array<string, string> $ids
     * @param list<VideoSidecar> $sidecars
     */
    private function matcher(VideoKind $kind, ?array $order = null, array $ids = [], array $sidecars = [], bool $matched = false, string $ordering = 'aired', ?VideoRequest $request = null): AutomaticVideoMatcher
    {
        $default = $kind === VideoKind::Series ? [VideoProviderId::Tvdb, VideoProviderId::Tmdb, VideoProviderId::Local] : [VideoProviderId::Tmdb, VideoProviderId::Local];
        $this->providers->method('providers')->willReturn($default);
        $request ??= new VideoRequest($kind, 'Heat', 1995, $ids, $ordering, libraryId: $this->library, sidecars: $sidecars, titleId: $this->title);
        $this->targets->expects(self::once())->method('load')->willReturn(new VideoMatchTarget($this->title, $request, [], $matched));
        $libraries = $this->createStub(LibraryTypeLookupInterface::class);
        $libraries->method('findType')->willReturn($kind === VideoKind::Series ? LibraryType::TvShow : LibraryType::Movie);
        $orders = $this->createStub(LibraryProviderOrderRepositoryInterface::class);
        if ($order !== null) {
            $orders->method('findByLibrary')->willReturn(LibraryProviderOrder::fromProviders($this->library, $order, $order));
        }
                $metadata = new ApplyVideoMatchHandler(
            $this->createStub(MovieRepositoryInterface::class),
            $this->createStub(TvCatalogRepositoryInterface::class),
            $this->createStub(PersonCreditPortInterface::class),
            $this->createStub(GenrePortInterface::class),
            $this->createStub(VideoArtworkImportInterface::class),
            $this->createStub(PersonProfileSyncRequestInterface::class),
        );

        return new AutomaticVideoMatcher(
            $this->targets, $this->providers, new LibraryProviderOrderService($libraries, $this->providers, $orders),
            $this->pauses, new VideoMatchConfidence(), $this->apply, $this->clock,
            new VideoMatchStructureLookup($this->providers), $metadata,
        );
    }

    private function claim(VideoKind $kind, string $source = 'ingest'): VideoMatchState
    {
        return new VideoMatchState(Uuid::v7(), $kind === VideoKind::Series ? $this->title : null, $kind === VideoKind::Movie ? $this->title : null, 'requested', $source, $this->clock->now(), Uuid::v7(), $this->clock->now()->modify('+15 minutes'));
    }
}
