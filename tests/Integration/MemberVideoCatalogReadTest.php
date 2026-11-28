<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Activity\Infrastructure\Doctrine\ViewingState;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\TvEpisode;
use App\Catalog\Domain\Model\TvSeason;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\Model\VideoPlacement;
use App\Catalog\Domain\ValueObject\VideoName\ExtraKind;
use App\Catalog\Infrastructure\Doctrine\Query\MemberVideoCatalogReadQuery;
use App\Catalog\Infrastructure\Doctrine\Query\VideoViewingProjection;
use App\Catalog\Infrastructure\Doctrine\Query\VideoWorkAvailability;
use App\Catalog\Infrastructure\Doctrine\Repository\MovieRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\TvCatalogRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\VideoRepository;
use App\Catalog\Infrastructure\VideoAvailability;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Infrastructure\Doctrine\Repository\LibraryFileIndexRepository;
use App\Library\Infrastructure\Doctrine\Repository\LibraryRepository;
use App\Library\Infrastructure\Filesystem\LibraryMediaFiles;
use App\Library\Infrastructure\Filesystem\MediaFileGuard;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Filesystem\Filesystem;

final class MemberVideoCatalogReadTest extends TestCase
{
    private Connection $connection;
    private EntityManager $manager;
    private TvCatalogRepository $catalog;
    private MovieRepository $movies;
    private VideoRepository $videos;
    private LibraryFileIndexRepository $index;
    private MemberVideoCatalogReadQuery $query;
    private string $schema;
    private string $root;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to migrated disposable PostgreSQL.');
        }
        $this->schema = 'member_video_' . bin2hex(random_bytes(8));
        $this->root = sys_get_temp_dir() . '/' . $this->schema;
        $this->connection = ArtistImportIdentityRace::connect('public');
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        foreach ([
            'libraries', 'library_file_index', 'movies', 'movie_video', 'tv_series', 'tv_seasons', 'tv_episodes',
            'videos', 'video_placements', 'video_placement_episode', 'watched_marks', 'resume_positions',
        ] as $table) {
            ArtistImportIdentityRace::copyTableWithoutPgroonga($this->connection, $table);
        }
        $this->manager = ArtistImportIdentityRace::entityManager($this->connection);
        $this->catalog = new TvCatalogRepository($this->manager);
        $this->movies = new MovieRepository($this->manager);
        $this->videos = new VideoRepository($this->manager);
        $this->index = new LibraryFileIndexRepository($this->manager);
        $clock = new NativeClock();
        $files = new LibraryMediaFiles(new LibraryRepository($this->manager, $clock), $this->index, new MediaFileGuard(), $this->manager, $clock, new NullLogger());
        $this->query = new MemberVideoCatalogReadQuery(
            $this->connection,
            new VideoWorkAvailability($this->connection, new VideoAvailability($this->videos, $files)),
            new VideoViewingProjection(new ViewingState($this->manager)),
            $this->movies,
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->connection->close();
        }
        if (isset($this->root)) {
            (new Filesystem())->remove($this->root);
        }
    }

    public function testMoviePagesCountOnlyAvailableWorkAndNeverBorrowAnotherLibraryPlacement(): void
    {
        $this->connection->executeStatement('CREATE INDEX ON movies USING pgroonga (title)');
        $first = $this->library('first', 'movie');
        $second = $this->library('second', 'movie');
        $hidden = $this->movie($first, 'A hidden Galaxy');
        $shown = $this->movie($first, 'B visible Galaxy');
        $shown->match('tmdb', '24', ['tmdb' => '24']);
        $this->movies->saveMatchIdentity($shown);
        $other = $this->movie($second, 'C other Galaxy');
        $extraOnly = $this->movie($first, 'D trailer Galaxy');
        $shared = $this->video('shared');
        $this->place($first, $shared, 'hidden/feature.mp4', $hidden, indexHash: 'replacement');
        $this->place($second, $shared, 'other/feature.mp4', $other, indexHash: 'shared');
        $feature = $this->video('shown');
        $this->place($first, $feature, 'shown/feature.mp4', $shown, indexHash: 'shown');
        $extra = $this->video('extra');
        $extraPlacement = $this->place($first, $extra, 'trailer-only/extra.mp4', $extraOnly, extra: ExtraKind::Trailer, indexHash: 'extra');

        $scope = LibraryReadScope::restricted([$first->getId()]);
        $limit = SearchOptions::create('', limit: 1);
        $page = $this->query->movies($limit, $scope, null);
        self::assertSame(2, $page->getTotal());
        self::assertSame($shown->getPublicId()->toString(), $page->getItems()[0]['movie']->getPublicId()->toString());
        $last = $this->query->movies(SearchOptions::create('', limit: 1, offset: 1), $scope, null);
        self::assertSame($extraOnly->getPublicId()->toString(), $last->getItems()[0]['movie']->getPublicId()->toString());
        self::assertSame($extraPlacement->getPublicId()->toString(), $last->getItems()[0]['extras'][0]['placementPublicId']);
        self::assertNull($this->query->movieDetail($hidden->getPublicId(), LibraryReadScope::unrestricted(), null));
        self::assertNull($this->query->movieDetail($other->getPublicId(), $scope, null));
        self::assertSame(0, $this->query->movies($limit, LibraryReadScope::none(), null)->getTotal());

        $member = Uuid::v7();
        $this->watch($member, $shown);
        $this->resume($member, $feature, 70, '2026-11-26 05:00:01+00');
        $details = $this->query->movieDetail($shown->getPublicId(), $scope, $member);
        self::assertNotNull($details);
        self::assertTrue($details['watched']);
        self::assertSame('tmdb', $details['metadataSource']);
        self::assertSame($feature->getPublicId()->toString(), $details['defaultVideoPublicId']);
        self::assertSame(0.7, $details['progress']);

        $searched = $this->query->movies(SearchOptions::create('Galaxy'), $scope, null);
        self::assertSame(2, $searched->getTotal());
        $movieIds = array_map(static fn (array $row): string => $row['movie']->getPublicId()->toString(), $searched->getItems());
        self::assertContains($shown->getPublicId()->toString(), $movieIds);
        self::assertContains($extraOnly->getPublicId()->toString(), $movieIds);
        self::assertSame(0, $this->query->movies(SearchOptions::create('Saturn'), $scope, null)->getTotal());
    }

    public function testSeriesCountsSeasonlessAndParticularFileStateUseOnlyAvailableOwnedEpisodes(): void
    {
        $library = $this->library('television', 'tv');
        $outside = $this->library('other', 'tv');
        $series = $this->series($library, 'Example');
        $series->match('tvdb', '123', ['tvdb' => '123']);
        $this->catalog->saveSeries($series);
        $this->catalog->flush();
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO tv_series (id, public_id, library_id, folder_path, name, created_at, updated_at)
                SELECT gen_random_uuid(), left(md5(n::text), 21), :library, 'Earlier ' || n, 'Earlier ' || n, now(), now()
                FROM generate_series(1, 105) n
                SQL,
            ['library' => $library->getId()->toString()],
        );
        $this->series($outside, 'Outside the scope');
        $special = $this->season($series, 0);
        $regular = $this->season($series, 1);
        $missingSeason = $this->season($series, 2);
        $episode = $this->episode($series, 'Available', $regular, 1);
        $missing = $this->episode($series, 'Missing', $regular, 2);
        $this->episode($series, 'Provider only', $regular, 3);
        $retired = $this->episode($series, 'Retired', $regular, 4);
        $retired->retire();
        $this->catalog->saveEpisode($retired);
        $this->catalog->flush();
        $bonus = $this->episode($series, 'Special', $special, 1);
        $this->episode($series, 'Missing season', $missingSeason, 1);
        $unseasoned = $this->episode($series, 'Absolute', absolute: 11);
        $dated = $this->episode($series, 'Date', date: new DateTimeImmutable('2026-11-20'));
        $primary = $this->video('primary');
        $secondary = $this->video('alternate');
        $missingCut = $this->video('missing');
        $extra = $this->video('trailer');
        $this->place($library, $primary, 'show/short.mp4', $series, [$episode, $bonus], indexHash: 'primary');
        $this->place($library, $secondary, 'show/long.mp4', $series, [$episode], indexHash: 'alternate');
        $this->place($library, $missingCut, 'show/missing.mp4', $series, [$episode, $missing]);
        $this->place($library, $this->video('seasonless'), 'show/11.mp4', $series, [$unseasoned, $dated], indexHash: 'seasonless');
        $extraPlacement = $this->place($library, $extra, 'show/trailer.mp4', $series, extra: ExtraKind::Trailer, indexHash: 'trailer');

        $member = Uuid::v7();
        $otherMember = Uuid::v7();
        $this->resume($member, $primary, 40, '2026-11-26 05:00:01.100000+00');
        $this->resume($member, $secondary, null, '2026-11-26 05:00:01.200000+00');
        $this->resume($member, $missingCut, 85, '2026-11-26 06:00:00+00');
        $this->resume($member, $extra, 11, '2026-11-26 07:00:00+00');
        $this->resume($otherMember, $primary, 20, '2026-11-25 05:00:00+00');
        $this->watch($member, $episode);
        $this->watch($member, $missing);

        $scope = LibraryReadScope::restricted([$library->getId()]);
        $seriesPage = $this->query->series(SearchOptions::create('', limit: 1), $scope, $library->getPublicId());
        self::assertSame(1, $seriesPage->getTotal());
        self::assertSame($series->getPublicId()->toString(), $seriesPage->getItems()[0]['publicId']);
        self::assertSame(0, $this->query->series(SearchOptions::create(''), $scope, $outside->getPublicId())->getTotal());
        $detail = $this->query->seriesDetail($series->getPublicId(), $scope, $member);
        self::assertNotNull($detail);
        self::assertSame($library->getPublicId()->toString(), $detail['libraryPublicId']);
        self::assertSame('tvdb', $detail['metadataSource']);
        self::assertSame([1, 0], array_column($detail['seasons'], 'number'));
        self::assertSame([1, 1], array_column($detail['seasons'], 'ownedCount'));
        self::assertSame([3, 1], array_column($detail['seasons'], 'totalCount'));
        self::assertSame([1, 0], array_column($detail['seasons'], 'watchedCount'));
        self::assertSame(2, $detail['unseasonedCount']);
        self::assertSame($extraPlacement->getPublicId()->toString(), $detail['extras'][0]['placementPublicId']);
        self::assertSame($extra->getPublicId()->toString(), $detail['extras'][0]['videoPublicId']);
        self::assertSame(11.0, $detail['extras'][0]['positionSeconds']);

        $options = SearchOptions::create('');
        $seasonPage = $this->query->episodes($series->getPublicId(), $options, $scope, $member, $regular->getPublicId());
        self::assertNotNull($seasonPage);
        self::assertSame(1, $seasonPage->getTotal());
        $row = $seasonPage->getItems()[0];
        self::assertSame($episode->getPublicId()->toString(), $row['publicId']);
        self::assertTrue($row['watched']);
        self::assertSame($secondary->getPublicId()->toString(), $row['defaultVideoPublicId']);
        self::assertNull($row['progress']);
        self::assertSame([$primary->getPublicId()->toString(), $secondary->getPublicId()->toString()], array_column($row['videos'], 'publicId'));
        self::assertSame([40.0, null], array_column($row['videos'], 'positionSeconds'));
        $elsewhere = $this->query->episodeDetail($episode->getPublicId(), $scope, $otherMember);
        self::assertNotNull($elsewhere);
        self::assertFalse($elsewhere['watched']);
        self::assertSame(0.2, $elsewhere['progress']);
        self::assertSame($primary->getPublicId()->toString(), $elsewhere['defaultVideoPublicId']);
        self::assertNull($this->query->episodeDetail($episode->getPublicId(), LibraryReadScope::none(), $member));
        self::assertNull($this->query->episodeDetail($missing->getPublicId(), $scope, $member));
        self::assertNull($this->query->episodes($series->getPublicId(), $options, $scope, $member, $missingSeason->getPublicId()));
        self::assertSame(0, $this->query->episodes($series->getPublicId(), SearchOptions::create('Other'), $scope, $member, $regular->getPublicId())?->getTotal());
        $unseasonedPage = $this->query->episodes($series->getPublicId(), $options, $scope, $member, unseasoned: true);
        self::assertNotNull($unseasonedPage);
        self::assertSame(2, $unseasonedPage->getTotal());
        self::assertSame([11, null], array_column($unseasonedPage->getItems(), 'absoluteNumber'));
        self::assertSame([null, '2026-11-20'], array_column($unseasonedPage->getItems(), 'airDate'));
        self::assertSame([null, null], array_column($unseasonedPage->getItems(), 'seasonPublicId'));

        $this->index->upsert($library->getId(), $this->indexPath($library, 'show/missing.mp4'), 'missing', 42, 'mp4', 1);
        $restored = $this->query->episodeDetail($missing->getPublicId(), $scope, $member);
        self::assertNotNull($restored);
        self::assertTrue($restored['watched']);
        self::assertSame(0.85, $restored['progress']);
    }

    private function library(string $name, string $type): LibraryEntity
    {
        $path = $this->root . '/' . $name;
        $actual = $this->root . '/original-' . $name;
        (new Filesystem())->mkdir($actual);
        (new Filesystem())->symlink($actual, $path);
        $library = new LibraryEntity($name, $name, $path, $type, 'local');
        $this->manager->persist($library);
        $this->manager->flush();

        return $library;
    }

    private function movie(LibraryEntity $library, string $title): Movie
    {
        $movie = Movie::create($library->getId(), $title);
        $this->movies->save($movie);

        return $movie;
    }

    private function series(LibraryEntity $library, string $name): TvSeries
    {
        $series = TvSeries::create($library->getId(), $name, $name);
        $this->catalog->saveSeries($series);
        $this->catalog->flush();

        return $series;
    }

    private function season(TvSeries $series, int $number): TvSeason
    {
        $season = TvSeason::create($series->getId(), $number);
        $this->catalog->saveSeason($season);
        $this->catalog->flush();

        return $season;
    }

    private function episode(
        TvSeries $series,
        string $name,
        ?TvSeason $season = null,
        ?int $number = null,
        ?int $absolute = null,
        ?DateTimeImmutable $date = null,
    ): TvEpisode {
        $episode = TvEpisode::create($series->getId(), $season?->getId(), $number, $name, $absolute, $date);
        $this->catalog->saveEpisode($episode);
        $this->catalog->flush();

        return $episode;
    }

    private function video(string $hash): Video
    {
        $video = Video::create('/old/place.mp4', $hash, duration: 100, height: 1080, width: 1920);
        $this->videos->save($video);

        return $video;
    }

    /** @param list<TvEpisode> $episodes */
    private function place(
        LibraryEntity $library,
        Video $video,
        string $path,
        Movie|TvSeries $owner,
        array $episodes = [],
        ?ExtraKind $extra = null,
        ?string $indexHash = null,
    ): VideoPlacement {
        $placement = VideoPlacement::create(
            $library->getId(),
            $video->getId(),
            $path,
            [],
            seriesId: $owner instanceof TvSeries ? $owner->getId() : null,
            movieId: $owner instanceof Movie ? $owner->getId() : null,
            extraKind: $extra,
        );
        if ($episodes !== []) {
            $placement->assignEpisodes(array_map(static fn (TvEpisode $episode): Uuid => $episode->getId(), $episodes));
        }
        $this->catalog->savePlacement($placement);
        $this->catalog->flush();
        if ($indexHash !== null) {
            $this->index->upsert($library->getId(), $this->indexPath($library, $path), $indexHash, 42, 'mp4', 1);
        }

        return $placement;
    }

    private function indexPath(LibraryEntity $library, string $relative): string
    {
        $root = realpath($library->getPath());
        self::assertNotFalse($root);

        return $root . '/' . $relative;
    }

    private function resume(Uuid $member, Video $video, ?float $position, string $played): void
    {
        $this->connection->insert('resume_positions', [
            'id' => Uuid::v7()->toString(),
            'user_id' => $member->toString(),
            'video_id' => $video->getId()->toString(),
            'position_seconds' => $position,
            'reported_at' => $played,
            'last_played_at' => $played,
        ]);
    }

    private function watch(Uuid $member, TvEpisode|Movie $work): void
    {
        $idColumn = $work instanceof TvEpisode ? 'episode_id' : 'movie_id';
        $this->connection->insert('watched_marks', [
            'id' => Uuid::v7()->toString(),
            'user_id' => $member->toString(),
            $idColumn => $work->getId()->toString(),
            'watched_at' => '2026-11-26 05:00:00+00',
        ]);
    }
}
