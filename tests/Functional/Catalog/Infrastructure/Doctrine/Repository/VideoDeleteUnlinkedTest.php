<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Application\Command\Movie\DeleteMovieCommand;
use App\Catalog\Application\CommandHandler\Movie\DeleteMovieHandler;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\TvEpisode;
use App\Catalog\Domain\Model\TvSeason;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\Model\VideoPlacement;
use App\Catalog\Domain\Repository\TvCatalogRepositoryInterface;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Catalog\Domain\ValueObject\VideoName\ExtraKind;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Tests\Functional\TestCase;

final class VideoDeleteUnlinkedTest extends TestCase
{
    public function testDeletingAMovieRemovesItsExtraAndKeepsAVideoSharedWithAnEpisode(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $movieLibrary = new LibraryEntity('Films', 'films-' . $suffix, '/media/films', 'movie', 'local');
        $tvLibrary = new LibraryEntity('TV', 'tv-' . $suffix, '/media/tv', 'tv_show', 'local');
        $this->entityManager->persist($movieLibrary);
        $this->entityManager->persist($tvLibrary);
        $this->entityManager->flush();

        $container = self::getContainer();
        $videos = $container->get(VideoRepositoryInterface::class);
        $feature = Video::create('/media/films/Film/film.mkv', hash('sha256', 'feature-' . $suffix));
        $extra = Video::create('/media/films/Film/trailer.mkv', hash('sha256', 'extra-' . $suffix));
        $videos->save($feature);
        $videos->save($extra);

        $movies = $container->get(MoviePortInterface::class);
        $movie = Movie::create($movieLibrary->getId(), 'Film');
        $movie->addVideo($feature->getId());
        $movies->save($movie);
        $catalog = $container->get(TvCatalogRepositoryInterface::class);
        $catalog->savePlacement(VideoPlacement::create($movieLibrary->getId(), $feature->getId(), 'Film/film.mkv', [], movieId: $movie->getId()));
        $catalog->savePlacement(VideoPlacement::create($movieLibrary->getId(), $extra->getId(), 'Film/trailer.mkv', [], movieId: $movie->getId(), extraKind: ExtraKind::Trailer));

        $series = TvSeries::create($tvLibrary->getId(), 'Series', 'Series');
        $season = TvSeason::create($series->getId(), 1);
        $episode = TvEpisode::create($series->getId(), $season->getId(), 1, 'Pilot');
        $catalog->saveSeries($series);
        $catalog->saveSeason($season);
        $catalog->saveEpisode($episode);
        $placement = VideoPlacement::create($tvLibrary->getId(), $feature->getId(), 'Series/S01E01.mkv', [], seriesId: $series->getId());
        $placement->assignEpisodes([$episode->getId()]);
        $catalog->savePlacement($placement);
        $catalog->flush();

        $handler = new DeleteMovieHandler($movies, $videos, $container->get(TransactionPortInterface::class));
        $result = $handler(new DeleteMovieCommand($movie->getPublicId()->toString()));
        self::assertSame(['movies' => 1, 'videos' => 1], $result->deleted);
        // A later write in the same worker also flushes its managed associations.
        $movies->save(Movie::create($movieLibrary->getId(), 'Another film'));
        $this->entityManager->clear();

        self::assertNull($movies->findByPublicId($movie->getPublicId()));
        self::assertNull($videos->findByUuid($extra->getId()));
        self::assertNotNull($videos->findByUuid($feature->getId()));
        $remaining = $catalog->findPlacement($tvLibrary->getId(), $feature->getId());
        self::assertNotNull($remaining);
        self::assertEquals([$episode->getId()], $remaining->getEpisodeIds());
    }
}
