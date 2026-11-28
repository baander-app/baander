<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Infrastructure\Doctrine\Repository\PeopleReadRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\VideoRepository;
use App\Catalog\Infrastructure\Doctrine\Query\VideoWorkAvailability;
use App\Catalog\Infrastructure\VideoAvailability;
use App\Library\Infrastructure\Doctrine\Repository\LibraryFileIndexRepository;
use App\Library\Infrastructure\Doctrine\Repository\LibraryRepository;
use App\Library\Infrastructure\Filesystem\LibraryMediaFiles;
use App\Library\Infrastructure\Filesystem\MediaFileGuard;
use Symfony\Component\Clock\NativeClock;
use Psr\Log\NullLogger;
use App\Media\Infrastructure\Doctrine\Entity\ImageEntity;
use App\Media\Infrastructure\Doctrine\Repository\ImageRepository;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Domain\ValueObject\MediaReadScope;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

final class PeopleReadRepositoryTest extends TestCase
{
    private Connection $connection;
    private string $schema;
    private PeopleReadRepository $reads;
    private string $library;
    private string $hiddenLibrary;
    private string $person;
    private PublicId $personPublicId;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }
        $this->schema = 'people_reads_' . bin2hex(random_bytes(8));
        $this->connection = ArtistImportIdentityRace::connect('public');
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        foreach ([
            'people', 'images', 'movies', 'tv_series', 'tv_seasons', 'tv_episodes',
            'libraries', 'videos', 'movie_video', 'video_placements', 'video_placement_episode', 'library_file_index',
            'albums', 'songs', 'artists', 'artist_song', 'artist_album',
            'person_artist_links', 'person_id_aliases', 'movie_person_credits',
            'series_person_credits', 'episode_person_credits',
        ] as $table) {
            // Production columns preserve driver return types. Read tests omit unrelated
            // write-side constraints so each fixture can isolate access behavior.
            $this->connection->executeStatement('CREATE TABLE ' . $table . ' AS TABLE public.' . $table . ' WITH NO DATA');
        }
        $this->reads = $this->reader($this->connection);
        $this->library = Uuid::v7()->toString();
        $this->hiddenLibrary = Uuid::v7()->toString();
        foreach ([$this->library, $this->hiddenLibrary] as $library) {
            $this->connection->insert('libraries', ['id' => $library, 'public_id' => (string) new PublicId(), 'path' => '/media/baander.app/' . $library]);
        }
        $this->person = Uuid::v7()->toString();
        $this->personPublicId = new PublicId();
        $this->connection->insert('people', [
            'id' => $this->person, 'public_id' => $this->personPublicId->toString(),
            'name' => 'Shared Person', 'aliases' => '["Stage Name"]', 'profile_overrides' => '{}',
            'birth_date' => '1980-05-15', 'birth_precision' => 3,
            'death_date' => '2020-05-14', 'death_precision' => 3, 'is_deceased' => true,
        ], ['is_deceased' => ParameterType::BOOLEAN]);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->connection->close();
        }
    }

    public function testScopeFiltersBeforeGroupingAndCountsAcrossMusicAndMovies(): void
    {
        $visible = $this->movie('A visible movie', $this->library);
        $hidden = $this->movie('A hidden movie', $this->hiddenLibrary);
        $this->credit('movie', $visible, 'director');
        $this->credit('movie', $visible, 'actor');
        $this->credit('movie', $hidden, 'director');
        $this->music();
        $scope = $this->scope();
        $profile = $this->reads->profile($this->personPublicId, $scope);
        self::assertSame(1, $profile['movieCount']);
        self::assertSame(1, $profile['songCount']);
        self::assertSame(1, $profile['albumCount']);
        self::assertSame(39, $profile['age']);
        self::assertSame(['Stage Name'], $profile['aliases']);

        $works = $this->reads->credits($this->personPublicId, SearchOptions::create(''), $scope);
        self::assertSame(3, $works->getTotal());
        self::assertCount(3, $works->getItems());
        $movies = $this->reads->credits($this->personPublicId, SearchOptions::create(''), $scope, 'movie');
        self::assertSame(1, $movies->getTotal());
        self::assertCount(2, $movies->getItems()[0]['roles']);
        self::assertSame('A visible movie', $movies->getItems()[0]['title']);
        self::assertSame(1, $this->reads->search(SearchOptions::create('Shared'), $scope, 'director')->getTotal());
        self::assertSame(1, $this->reads->search(SearchOptions::create('Stage Name'), $scope)->getTotal());
        self::assertNull($this->reads->profile($this->personPublicId, LibraryReadScope::none()));
        self::assertNull($this->reads->workCredits('movie', $this->moviePublicId($hidden), SearchOptions::create(''), $scope));
    }

    public function testCreditFeedsFilterUnavailableVideoBeforePagingAndKeepMusicAndPublicIds(): void
    {
        $missing = $this->movie('A missing movie', $this->library, available: false);
        $hidden = $this->movie('B hidden movie', $this->hiddenLibrary);
        $mismatched = $this->movie('C replaced movie', $this->library);
        $visible = $this->movie('Z visible movie', $this->library);
        foreach ([$missing, $hidden, $mismatched, $visible] as $movie) {
            $this->credit('movie', $movie, 'actor');
        }
        $this->connection->executeStatement(
            'UPDATE library_file_index SET hash = :hash WHERE path = :path',
            ['hash' => hash('xxh3', 'replacement'), 'path' => '/media/baander.app/' . $this->library . '/' . $mismatched . '.mp4'],
        );
        $series = Uuid::v7()->toString();
        $seriesPublic = new PublicId();
        $episode = Uuid::v7()->toString();
        $episodePublic = new PublicId();
        $this->connection->insert('tv_series', ['id' => $series, 'public_id' => $seriesPublic->toString(), 'library_id' => $this->library, 'name' => 'D visible series']);
        $this->connection->insert('tv_episodes', [
            'id' => $episode, 'public_id' => $episodePublic->toString(), 'series_id' => $series,
            'name' => 'E seasonless episode', 'absolute_number' => 137, 'retired' => false,
        ], ['retired' => ParameterType::BOOLEAN]);
        $this->file('episode', $episode, $this->library);
        $this->credit('series', $series, 'actor');
        $this->credit('episode', $episode, 'actor');
        $this->music();

        $first = $this->reads->credits($this->personPublicId, SearchOptions::create('', 2), $this->scope());
        $second = $this->reads->credits($this->personPublicId, SearchOptions::create('', 2, 2), $this->scope());
        $last = $this->reads->credits($this->personPublicId, SearchOptions::create('', 2, 4), $this->scope());
        self::assertSame(5, $first->getTotal());
        self::assertSame(5, $second->getTotal());
        self::assertSame(5, $last->getTotal());
        self::assertSame(['Album', 'D visible series'], array_column($first->getItems(), 'title'));
        self::assertSame(['E seasonless episode', 'Song'], array_column($second->getItems(), 'title'));
        self::assertSame([$this->moviePublicId($visible)->toString()], array_column($last->getItems(), 'publicId'));
        self::assertSame(['kind', 'publicId', 'title', 'roles'], array_keys($last->getItems()[0]));
        self::assertSame(1, $this->reads->credits($this->personPublicId, SearchOptions::create(''), $this->scope(), 'movie')->getTotal());
        self::assertSame(3, $this->reads->profile($this->personPublicId, $this->scope())['movieCount']);
        $songs = $this->reads->credits($this->personPublicId, SearchOptions::create('', 1), $this->scope(), 'song');
        self::assertSame(1, $songs->getTotal());
        self::assertSame(['Song'], array_column($songs->getItems(), 'title'));
        $pastAlbum = $this->reads->credits($this->personPublicId, SearchOptions::create('', 1, 1), $this->scope(), 'album');
        self::assertSame(1, $pastAlbum->getTotal());
        self::assertSame([], $pastAlbum->getItems());
        self::assertNull($this->reads->workCredits('movie', $this->moviePublicId($missing), SearchOptions::create(''), $this->scope()));
        self::assertNull($this->reads->workCredits('movie', $this->moviePublicId($mismatched), SearchOptions::create(''), $this->scope()));
        self::assertNull($this->reads->workCredits('movie', $this->moviePublicId($hidden), SearchOptions::create(''), $this->scope()));
        self::assertSame(1, $this->reads->workCredits('series', $seriesPublic, SearchOptions::create(''), $this->scope())->getTotal());
        self::assertSame(1, $this->reads->workCredits('episode', $episodePublic, SearchOptions::create(''), $this->scope())->getTotal());
        $this->connection->update('tv_episodes', ['retired' => true], ['id' => $episode], ['retired' => ParameterType::BOOLEAN]);
        self::assertNull($this->reads->workCredits('episode', $episodePublic, SearchOptions::create(''), $this->scope()));
        self::assertSame(3, $this->reads->credits($this->personPublicId, SearchOptions::create(''), $this->scope())->getTotal());
    }

    public function testAnotherLibraryCannotMakeAnUnavailableCreditedMovieVisible(): void
    {
        $movie = $this->movie('Unavailable here', $this->library, available: false);
        $this->credit('movie', $movie, 'actor');
        $video = $this->connection->fetchOne('SELECT video_id FROM movie_video WHERE movie_id = ?', [$movie]);
        $hash = $this->connection->fetchOne('SELECT hash FROM videos WHERE id = ?', [$video]);
        $this->connection->insert('video_placements', [
            'id' => Uuid::v7()->toString(), 'public_id' => (string) new PublicId(), 'movie_id' => $movie,
            'video_id' => $video, 'library_id' => $this->hiddenLibrary, 'relative_path' => 'foreign.mp4', 'raw_parse' => '{}',
        ]);
        $this->connection->insert('library_file_index', [
            'id' => Uuid::v7()->toString(), 'library_id' => $this->hiddenLibrary,
            'path' => '/media/baander.app/' . $this->hiddenLibrary . '/foreign.mp4', 'hash' => $hash, 'available' => true,
        ], ['available' => ParameterType::BOOLEAN]);

        self::assertSame(0, $this->reads->credits($this->personPublicId, SearchOptions::create(''), $this->scope())->getTotal());
        self::assertSame(0, $this->reads->credits($this->personPublicId, SearchOptions::create(''), LibraryReadScope::unrestricted())->getTotal());
        self::assertNull($this->reads->workCredits('movie', $this->moviePublicId($movie), SearchOptions::create(''), $this->scope()));
    }

    public function testAliasesAndArtistLinksUseCurrentScopeAndIdentitySnapshot(): void
    {
        $artist = $this->music();
        $retired = new PublicId();
        $this->connection->insert('person_id_aliases', ['public_id' => $retired->toString(), 'person_id' => $this->person]);
        self::assertSame($this->personPublicId->toString(), $this->reads->profile($retired, $this->scope())['publicId']);
        self::assertSame($this->personPublicId->toString(), $this->reads->artistProfile($artist, $this->scope())['publicId']);
        self::assertNull($this->reads->profile($retired, LibraryReadScope::none()));
        $this->connection->update('artists', ['mbid' => Uuid::v7()->toString()], ['public_id' => $artist->toString()]);
        self::assertNull($this->reads->artistProfile($artist, $this->scope()));
        self::assertNull($this->reads->profile($this->personPublicId, $this->scope()));
    }

    public function testTelevisionDetailsAreVisibleWithoutAnyCreditAndEpisodeInheritsSeriesScope(): void
    {
        $series = Uuid::v7()->toString();
        $seriesPublic = new PublicId();
        $season = Uuid::v7()->toString();
        $episode = Uuid::v7()->toString();
        $episodePublic = new PublicId();
        $this->connection->insert('tv_series', ['id' => $series, 'public_id' => $seriesPublic->toString(), 'library_id' => $this->library, 'name' => 'A series']);
        $this->connection->insert('tv_seasons', ['id' => $season, 'public_id' => (string) new PublicId(), 'series_id' => $series, 'season_number' => 0]);
        $this->connection->insert('tv_episodes', ['id' => $episode, 'public_id' => $episodePublic->toString(), 'series_id' => $series, 'season_id' => $season, 'name' => 'A special', 'episode_number' => 2, 'retired' => false], ['retired' => ParameterType::BOOLEAN]);
        $this->file('episode', $episode, $this->library);
        self::assertSame('A series', $this->reads->seriesDetail($seriesPublic, $this->scope())['name']);
        self::assertSame('A special', $this->reads->episodeDetail($episodePublic, $this->scope())['name']);
        self::assertSame(1, $this->reads->series(SearchOptions::create(''), $this->scope())->getTotal());
        self::assertSame(1, $this->reads->episodes($seriesPublic, SearchOptions::create(''), $this->scope())->getTotal());
        self::assertSame(0, $this->reads->workCredits('episode', $episodePublic, SearchOptions::create(''), $this->scope())->getTotal());
        self::assertNull($this->reads->seriesDetail($seriesPublic, LibraryReadScope::none()));
        self::assertNull($this->reads->episodeDetail($episodePublic, LibraryReadScope::none()));

        $this->credit('episode', $episode, 'actor');
        self::assertSame(1, $this->reads->profile($this->personPublicId, $this->scope())['episodeCount']);
        self::assertSame(0, $this->reads->profile($this->personPublicId, $this->scope())['seriesCount']);
    }

    public function testArtistProfileDoesNotExposeHiddenMusicThroughVisibleMovie(): void
    {
        $artist = $this->music();
        $movie = $this->movie('Visible movie', $this->library);
        $this->credit('movie', $movie, 'director');
        $this->connection->executeStatement('UPDATE albums SET library_id = :library', ['library' => $this->hiddenLibrary]);
        self::assertNotNull($this->reads->profile($this->personPublicId, $this->scope()));
        self::assertNull($this->reads->artistProfile($artist, $this->scope()));
    }

    public function testFiftyWorkPageKeepsDistinctCountsAndStableTiedTitlePagination(): void
    {
        for ($index = 0; $index < 50; ++$index) {
            $movie = $this->movie('Same title', $this->library);
            $this->credit('movie', $movie, 'director');
            $this->credit('movie', $movie, 'actor');
        }
        $first = $this->reads->credits($this->personPublicId, SearchOptions::create('', 25), $this->scope());
        $second = $this->reads->credits($this->personPublicId, SearchOptions::create('', 25, 25), $this->scope());
        self::assertSame(50, $first->getTotal());
        self::assertSame(50, $second->getTotal());
        self::assertCount(25, $first->getItems());
        self::assertCount(25, $second->getItems());
        $publicIds = array_column([...$first->getItems(), ...$second->getItems()], 'publicId');
        self::assertCount(50, array_unique($publicIds));
        self::assertSame($first->getItems(), $this->reads->credits($this->personPublicId, SearchOptions::create('', 25), $this->scope())->getItems());
        self::assertSame(50, $this->reads->profile($this->personPublicId, $this->scope())['movieCount']);

        $logger = new class extends AbstractLogger {
            /** @var list<array{sql: string, params: array<array-key, mixed>}> */
            public array $queries = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                if (isset($context['sql']) && str_starts_with($context['sql'], 'WITH visible_credits')) {
                    $this->queries[] = ['sql' => $context['sql'], 'params' => $context['params'] ?? []];
                }
            }
        };
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($logger)]);
        $connection = DriverManager::getConnection($this->connection->getParams(), $configuration);
        try {
            $connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
            $reads = $this->reader($connection);
            self::assertCount(50, $reads->credits($this->personPublicId, SearchOptions::create(''), $this->scope())->getItems());
            self::assertCount(1, $logger->queries);
            $query = $logger->queries[0];
            $plan = $connection->fetchOne('EXPLAIN (FORMAT JSON) ' . $query['sql'], array_values($query['params']));
            self::assertIsArray(json_decode($plan, true, flags: JSON_THROW_ON_ERROR)[0]['Plan']);
        } finally {
            $connection->close();
        }
    }

    public function testPortraitAccessTracksVisibleWorkAndExplicitPortraitOwnership(): void
    {
        $movie = $this->movie('Visible title', $this->library);
        $this->credit('movie', $movie, 'actor');
        $image = Uuid::v7()->toString();
        $publicId = new PublicId();
        $this->connection->insert('images', ['id' => $image, 'public_id' => $publicId->toString()]);
        $this->connection->update('people', ['image_id' => $image], ['id' => $this->person]);
        self::assertTrue($this->reads->canReadPortrait($publicId, $this->scope()));
        self::assertFalse($this->reads->canReadPortrait($publicId, LibraryReadScope::none()));
        self::assertSame('/api/images/' . $publicId . '/file', $this->reads->profile($this->personPublicId, $this->scope())['profileUrl']);
    }

    public function testManagedPortraitServesOnlyWhileItsPersonHasVisibleCredits(): void
    {
        $movie = $this->movie('Visible title', $this->library);
        $this->credit('movie', $movie, 'actor');
        $manager = ArtistImportIdentityRace::entityManager($this->connection);
        $publicId = new PublicId();
        $image = new ImageEntity('/images/person-portrait.png', 'png', 'image/png', $publicId, 64, 1, 1, 'person');
        $manager->persist($image);
        $manager->flush();
        $this->connection->update('people', ['image_id' => $image->getId()->toString()], ['id' => $this->person]);

        $images = new ImageRepository($manager, $this->reads);
        $actor = Uuid::v7();
        $visible = $images->findVisibleByPublicId($publicId, MediaReadScope::authenticated($actor, $this->scope()));
        self::assertSame('/images/person-portrait.png', $visible?->getPath());
        self::assertNull($images->findVisibleByPublicId($publicId, MediaReadScope::authenticated($actor, LibraryReadScope::none())));
        self::assertNull($images->findVisibleByPublicId($publicId, MediaReadScope::none()));
    }

    private function reader(Connection $connection): PeopleReadRepository
    {
        $manager = ArtistImportIdentityRace::entityManager($connection);
        $clock = new NativeClock();
        $files = new LibraryMediaFiles(
            new LibraryRepository($manager, $clock),
            new LibraryFileIndexRepository($manager),
            new MediaFileGuard(),
            $manager,
            $clock,
            new NullLogger(),
        );

        return new PeopleReadRepository($manager, new VideoWorkAvailability($connection, new VideoAvailability(new VideoRepository($manager), $files)));
    }

    private function file(string $kind, string $owner, string $library, bool $available = true): string
    {
        $video = Uuid::v7()->toString();
        $placement = Uuid::v7()->toString();
        $relative = $owner . '.mp4';
        $path = '/media/baander.app/' . $library . '/' . $relative;
        $hash = hash('xxh3', $video);
        $this->connection->insert('videos', ['id' => $video, 'public_id' => (string) new PublicId(), 'path' => $path, 'hash' => $hash]);
        $this->connection->insert('video_placements', [
            'id' => $placement, 'public_id' => (string) new PublicId(), 'video_id' => $video,
            'library_id' => $library, 'relative_path' => $relative, 'raw_parse' => '{}',
            $kind === 'movie' ? 'movie_id' : 'series_id' => $kind === 'movie' ? $owner : $this->connection->fetchOne('SELECT series_id FROM tv_episodes WHERE id = ?', [$owner]),
        ]);
        if ($kind === 'movie') {
            $this->connection->insert('movie_video', ['id' => Uuid::v7()->toString(), 'movie_id' => $owner, 'video_id' => $video]);
        } else {
            $this->connection->insert('video_placement_episode', ['id' => Uuid::v7()->toString(), 'placement_id' => $placement, 'episode_id' => $owner, 'sort_order' => 0]);
        }
        $this->connection->insert('library_file_index', [
            'id' => Uuid::v7()->toString(), 'library_id' => $library, 'path' => $path, 'hash' => $hash,
            'available' => $available,
        ], ['available' => ParameterType::BOOLEAN]);

        return $video;
    }

    private function scope(): LibraryReadScope
    {
        return LibraryReadScope::restricted([Uuid::fromString($this->library)]);
    }

    private function movie(string $title, string $library, bool $available = true): string
    {
        $id = Uuid::v7()->toString();
        $this->connection->insert('movies', ['id' => $id, 'public_id' => (string) new PublicId(), 'library_id' => $library, 'title' => $title]);
        $this->file('movie', $id, $library, $available);

        return $id;
    }

    private function moviePublicId(string $movie): PublicId
    {
        return PublicId::fromString($this->connection->fetchOne('SELECT public_id FROM movies WHERE id = :id', ['id' => $movie]));
    }

    private function credit(string $kind, string $target, string $role): void
    {
        $this->connection->insert($kind . '_person_credits', [
            'id' => Uuid::v7()->toString(), $kind . '_id' => $target, 'person_id' => $this->person,
            'category' => $role === 'actor' ? 'cast' : 'crew', 'role' => $role, 'job' => ucfirst($role),
        ]);
    }

    private function music(): PublicId
    {
        $artist = Uuid::v7()->toString();
        $publicId = new PublicId();
        $mbid = Uuid::v7()->toString();
        $album = Uuid::v7()->toString();
        $song = Uuid::v7()->toString();
        $this->connection->insert('artists', ['id' => $artist, 'public_id' => $publicId->toString(), 'name' => 'Stage Name', 'type' => 'Person', 'mbid' => $mbid]);
        $this->connection->insert('person_artist_links', ['artist_id' => $artist, 'person_id' => $this->person, 'artist_mbid' => $mbid]);
        $this->connection->insert('albums', ['id' => $album, 'public_id' => (string) new PublicId(), 'library_id' => $this->library, 'title' => 'Album']);
        $this->connection->insert('songs', ['id' => $song, 'public_id' => (string) new PublicId(), 'album_id' => $album, 'title' => 'Song']);
        $this->connection->insert('artist_album', ['id' => Uuid::v7()->toString(), 'artist_id' => $artist, 'album_id' => $album, 'role' => 'primary', 'credited_name' => 'Stage Name']);
        $this->connection->insert('artist_song', ['id' => Uuid::v7()->toString(), 'artist_id' => $artist, 'song_id' => $song, 'role' => 'composer', 'credited_name' => 'Other credited name']);

        return $publicId;
    }
}
