<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\CommandHandler\VideoMatch;

use App\Catalog\Application\CommandHandler\VideoMatch\ApplyVideoMatchHandler;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\PersonCreditPortInterface;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\TvEpisode;
use App\Catalog\Domain\Model\TvSeason;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Repository\MovieRepositoryInterface;
use App\Catalog\Domain\Repository\TvCatalogRepositoryInterface;
use App\Media\Application\DTO\PreparedVideoArtwork;
use App\Media\Application\Port\VideoArtworkImportInterface;
use App\Media\Domain\Model\Image;
use App\Metadata\Application\DTO\Video\VideoArtwork;
use App\Metadata\Application\DTO\Video\VideoCandidate;
use App\Metadata\Application\DTO\Video\VideoCredit;
use App\Metadata\Application\DTO\Video\VideoEpisode;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoSeason;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Metadata\Application\Port\PersonProfileSyncRequestInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ApplyVideoMatchHandlerTest extends TestCase
{
    public function testAutomaticMovieApplyFillsEmptyFieldsAndKeepsTheManualTitleAndExistingValues(): void
    {
        $movie = Movie::create(Uuid::v7(), 'Scanned movie', 1995);
        $movie->edit(title: 'My movie');
        $movie->updateMetadata(runtime: 100);
        $video = Uuid::v7()->toString();
        $movie->getState()->videoIds = [$video];
        $metadata = new VideoTitle(new VideoCandidate('12', 'Provider title', 1996), overview: 'Provider plot', runtime: 200);
        $handler = $this->handler();
        $prepared = $handler->prepare($movie->getLibraryId(), $metadata);
        $handler->apply($movie, VideoProviderId::Tmdb, $prepared);

        self::assertSame('My movie', $movie->getTitle());
        self::assertSame(1995, $movie->getYear());
        self::assertSame('Provider plot', $movie->getOverview());
        self::assertSame(100, $movie->getRuntime());
        self::assertSame([$video], $movie->getVideoIds());
    }

    public function testForcedMovieApplyReplacesUnlockedValues(): void
    {
        $movie = Movie::create(Uuid::v7(), 'Scanned movie', 1995);
        $movie->edit(title: 'My movie');
        $movie->updateMetadata(overview: 'Earlier plot', runtime: 100);
        $metadata = new VideoTitle(new VideoCandidate('12', 'Provider title', 1996), overview: 'Current plot', runtime: 200);
        $handler = $this->handler();
        $handler->apply($movie, VideoProviderId::Tmdb, $handler->prepare($movie->getLibraryId(), $metadata), force: true);

        self::assertSame('My movie', $movie->getTitle());
        self::assertSame(1996, $movie->getYear());
        self::assertSame('Current plot', $movie->getOverview());
        self::assertSame(200, $movie->getRuntime());
    }

    public function testTvMatchReplacesPlaceholdersAndRefreshesOnlyProviderOwnedFields(): void
    {
        $series = TvSeries::create(Uuid::v7(), 'Folder', 'Folder');
        $season = TvSeason::create($series->getId(), 1);
        $episode = TvEpisode::create($series->getId(), $season->getId(), 1, 'Episode 1');
        $episode->match('101');
        $catalog = $this->createStub(TvCatalogRepositoryInterface::class);
        $catalog->method('seasonsBySeries')->willReturn([$season]);
        $catalog->method('episodesBySeries')->willReturn([$episode]);
        $handler = $this->handler(catalog: $catalog);
        $first = new VideoTitle(new VideoCandidate('10', 'Matched series'), overview: 'First plot', seasons: [new VideoSeason(1, 'Season name', episodes: [new VideoEpisode('101', 1, 'Pilot', overview: 'First episode plot')])]);
        $handler->apply($series, VideoProviderId::Tvdb, $handler->prepare($series->getLibraryId(), $first));
        self::assertSame('Matched series', $series->getName());
        self::assertSame('Pilot', $episode->getName());
        $series->getState()->overview = 'Editorial plot';
        $episode->getState()->name = 'My pilot';
        $episode->lockField('name');
        $next = new VideoTitle(new VideoCandidate('10', 'Refreshed series'), overview: 'Refreshed plot', seasons: [new VideoSeason(1, 'Updated season', episodes: [new VideoEpisode('101', 1, 'Updated pilot', overview: 'Updated episode plot')])]);
        $handler->apply($series, VideoProviderId::Tvdb, $handler->prepare($series->getLibraryId(), $next));

        self::assertSame('Refreshed series', $series->getName());
        self::assertSame('Editorial plot', $series->getState()->overview);
        self::assertSame('Updated season', $season->getState()->name);
        self::assertSame('My pilot', $episode->getName());
        self::assertSame('Updated episode plot', $episode->getState()->overview);
    }

    public function testMetadataKeepsCanonicalStructureAndFirstMatchStillReplacesTheEpisodePlaceholder(): void
    {
        $series = TvSeries::create(Uuid::v7(), 'Show', 'Show');
        $season = TvSeason::create($series->getId(), 1);
        $episode = TvEpisode::create($series->getId(), $season->getId(), 1, 'Episode 1', absoluteNumber: 2);
        $episode->match('101');
        $episode->applyStructure(new DateTimeImmutable('2026-01-02'), null);
        $catalog = $this->createStub(TvCatalogRepositoryInterface::class);
        $catalog->method('seasonsBySeries')->willReturn([$season]);
        $catalog->method('episodesBySeries')->willReturn([$episode]);
        $handler = $this->handler(catalog: $catalog);
        $metadata = new VideoTitle(new VideoCandidate('10', 'Show'), seasons: [new VideoSeason(1, episodes: [new VideoEpisode('101', 1, 'Pilot', absoluteNumber: 2, airDate: '2026-01-03')])]);
        $handler->apply($series, VideoProviderId::Tmdb, $handler->prepare($series->getLibraryId(), $metadata));

        self::assertSame('Pilot', $episode->getName());
        self::assertNull($episode->getAbsoluteNumber());
        self::assertSame('2026-01-02', $episode->getAirDate()?->format('Y-m-d'));
        $episode->lockField('airDate');
        $episode->applyStructure(new DateTimeImmutable('2026-01-04'), 3);
        self::assertSame('2026-01-02', $episode->getAirDate()?->format('Y-m-d'));
        self::assertSame(3, $episode->getAbsoluteNumber());
    }

    public function testAllCreditsAreCollectedBeforeOneBatchAndProfilesWaitForCommit(): void
    {
        $series = TvSeries::create(Uuid::v7(), 'Show', 'Show');
        $season = TvSeason::create($series->getId(), 1);
        $episode = TvEpisode::create($series->getId(), $season->getId(), 1, 'Episode 1');
        $episode->match('101');
        $catalog = $this->createStub(TvCatalogRepositoryInterface::class);
        $catalog->method('seasonsBySeries')->willReturn([$season]);
        $catalog->method('episodesBySeries')->willReturn([$episode]);
        $people = $this->createMock(PersonCreditPortInterface::class);
        $people->expects(self::once())->method('syncCredits')->willReturnCallback(static function (array $targets): bool {
            self::assertSame(['series', 'episode'], array_column($targets, 'kind'));
            self::assertCount(1, $targets[0]->cast);
            self::assertSame(['imdb_name' => 'nm0000123'], $targets[0]->cast[0]->identifiers);
            self::assertSame('local', $targets[0]->cast[0]->source);

            return true;
        });
        $profiles = $this->createMock(PersonProfileSyncRequestInterface::class);
        $requests = 0;
        $profiles->expects(self::once())->method('request')->willReturnCallback(static function () use (&$requests): void {
            ++$requests;
        });
        $cast = [new VideoCredit([], 'Unidentified actor', 'cast', 'actor', 'Actor'), new VideoCredit(['imdb_name' => 'nm0000123'], 'Actor', 'cast', 'actor', 'Actor')];
        $metadata = new VideoTitle(new VideoCandidate('local-id', 'Show'), cast: $cast, seasons: [new VideoSeason(1, episodes: [new VideoEpisode('101', 1, 'Pilot', cast: $cast)])]);
        $handler = $this->handler(catalog: $catalog, people: $people, profiles: $profiles);
        $prepared = $handler->prepare($series->getLibraryId(), $metadata);
        $handler->apply($series, VideoProviderId::Local, $prepared);
        self::assertSame(0, $requests);
        $handler->discard($prepared, committed: true);
        self::assertSame(1, $requests);
    }

    public function testAFieldThatKeepsItsExistingPosterDiscardsTheUnusedPreparedImage(): void
    {
        $movie = Movie::create(Uuid::v7(), 'Movie');
        $movie->updateMetadata(posterUrl: 'https://images.baander.app/old.jpg');
        $artwork = $this->preparedArtwork($movie->getLibraryId());
        $importer = $this->createMock(VideoArtworkImportInterface::class);
        $importer->method('prepare')->willReturn($artwork);
        $importer->expects(self::never())->method('persist');
        $importer->expects(self::once())->method('discard')->with($artwork);
        $handler = $this->handler(artwork: $importer);
        $metadata = new VideoTitle(new VideoCandidate('local-id', 'Movie'), poster: new VideoArtwork('/media/poster.png', $movie->getLibraryId()));
        $prepared = $handler->prepare($movie->getLibraryId(), $metadata);
        $handler->apply($movie, VideoProviderId::Local, $prepared);
        $handler->discard($prepared, committed: true);

        self::assertSame('https://images.baander.app/old.jpg', $movie->getPosterUrl());
    }

    public function testRollbackDiscardsPreparedArtworkAndDoesNotQueueProfiles(): void
    {
        $movie = Movie::create(Uuid::v7(), 'Movie');
        $artwork = $this->preparedArtwork($movie->getLibraryId());
        $importer = $this->createMock(VideoArtworkImportInterface::class);
        $importer->method('prepare')->willReturn($artwork);
        $importer->expects(self::once())->method('persist')->with($artwork);
        $importer->expects(self::once())->method('discard')->with($artwork);
        $profiles = $this->createMock(PersonProfileSyncRequestInterface::class);
        $profiles->expects(self::never())->method('request');
        $people = $this->createStub(PersonCreditPortInterface::class);
        $people->method('syncCredits')->willReturn(true);
        $handler = $this->handler(artwork: $importer, profiles: $profiles, people: $people);
        $metadata = new VideoTitle(new VideoCandidate('local-id', 'Movie'), poster: new VideoArtwork('/media/poster.png', $movie->getLibraryId()));
        $prepared = $handler->prepare($movie->getLibraryId(), $metadata);
        $handler->apply($movie, VideoProviderId::Local, $prepared);
        $handler->discard($prepared);

        self::assertSame($artwork->url, $movie->getPosterUrl());
    }

    public function testPreparingArtworkFromAnotherLibraryIsRefusedBeforeImport(): void
    {
        $importer = $this->createMock(VideoArtworkImportInterface::class);
        $importer->expects(self::never())->method('prepare');
        $metadata = new VideoTitle(new VideoCandidate('local-id', 'Movie'), poster: new VideoArtwork('/media/poster.png', Uuid::v7()));
        $this->expectException(InvalidInputException::class);
        $this->handler(artwork: $importer)->prepare(Uuid::v7(), $metadata);
    }

    public function testPreparationFailureDiscardsTheFilesAlreadyPrepared(): void
    {
        $library = Uuid::v7();
        $first = $this->preparedArtwork($library);
        $importer = $this->createMock(VideoArtworkImportInterface::class);
        $importer->method('prepare')->willReturnOnConsecutiveCalls($first, self::throwException(new ServiceUnavailableException('Artwork disappeared.')));
        $importer->expects(self::once())->method('discard')->with($first);
        $metadata = new VideoTitle(new VideoCandidate('local-id', 'Movie'), poster: new VideoArtwork('/media/poster.png', $library), backdrop: new VideoArtwork('/media/backdrop.png', $library));
        $this->expectException(ServiceUnavailableException::class);
        $this->handler(artwork: $importer)->prepare($library, $metadata);
    }

    private function handler(
        ?TvCatalogRepositoryInterface $catalog = null,
        ?PersonCreditPortInterface $people = null,
        ?VideoArtworkImportInterface $artwork = null,
        ?PersonProfileSyncRequestInterface $profiles = null,
    ): ApplyVideoMatchHandler {
        return new ApplyVideoMatchHandler(
            $this->createStub(MovieRepositoryInterface::class),
            $catalog ?? $this->createStub(TvCatalogRepositoryInterface::class),
            $people ?? $this->createStub(PersonCreditPortInterface::class),
            $this->createStub(GenrePortInterface::class),
            $artwork ?? $this->createStub(VideoArtworkImportInterface::class),
            $profiles ?? $this->createStub(PersonProfileSyncRequestInterface::class),
        );
    }

    private function preparedArtwork(Uuid $library): PreparedVideoArtwork
    {
        $image = Image::create('images/video/fixture.png', 'png', 'image/png', 68, 1, 1, 'video', libraryId: $library);

        return new PreparedVideoArtwork($image, '/api/images/' . $image->getPublicId()->toString() . '/file');
    }
}
