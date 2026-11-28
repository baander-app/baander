<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Metadata;

use App\Activity\Infrastructure\Doctrine\Repository\WatchedMarkRepository;
use App\Activity\Infrastructure\Doctrine\WatchedMarkMove;
use App\Catalog\Application\CommandHandler\VideoMatch\ApplyVideoMatchHandler;
use App\Catalog\Application\Dto\ResolvedVideoMatch;
use App\Catalog\Application\Service\VideoMatch\EpisodePlacer;
use App\Catalog\Application\Service\VideoMatch\VideoMatchTarget;
use App\Catalog\Infrastructure\Doctrine\Repository\PersonCreditRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\PersonIdentityRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\GenreMovieRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\GenreRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\MovieRepository;
use App\Catalog\Infrastructure\GenreService;
use App\Catalog\Infrastructure\Doctrine\Repository\TvCatalogRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\VideoMatchStateRepository;
use App\Catalog\Infrastructure\VideoMatch\VideoMatchApply;
use App\Catalog\Infrastructure\VideoPlacementService;
use App\Media\Application\DTO\PreparedVideoArtwork;
use App\Media\Application\Port\VideoArtworkImportInterface;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Metadata\Application\Service\PersonProfileSyncRequester;
use App\Metadata\Infrastructure\Video\TmdbVideoMetadata;
use App\Metadata\Infrastructure\Api\Tmdb\TmdbAdapter;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\DoctrineTransaction;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use Baander\HttpClient\PublicDestinationPolicy;
use Baander\HttpClient\PublicHttpClient;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\AbstractManagerRegistry;
use Psr\Log\NullLogger;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class MovieMetadataRace
{
    /** @param list<string> $names */
    public static function apply(EntityManager $manager, Uuid $movieId, array $names, ?\Closure $duringLookup = null): bool
    {
        $movies = new MovieRepository($manager);
        $movie = $movies->findByUuid($movieId) ?? throw new \LogicException('Missing fixture movie.');
        $genres = array_map(static fn(int $index, string $name): array => ['id' => $index + 1, 'name' => $name], array_keys($names), $names);
        $http = new MockHttpClient(static function (string $method, string $url) use ($genres, $duringLookup): MockResponse {
            if ($method !== 'GET' || parse_url($url, PHP_URL_PATH) !== '/3/movie/12') {
                throw new \LogicException('Unexpected provider request from the movie metadata fixture.');
            }
            $duringLookup?->__invoke();

            return new MockResponse(json_encode([
                'id' => 12,
                'title' => 'Provider title',
                'overview' => 'Provider overview',
                'genres' => $genres,
                'credits' => ['crew' => [['id' => 2_000_000_099, 'name' => 'Provider Director', 'job' => 'Director']]],
            ], JSON_THROW_ON_ERROR));
        });
        $registry = new class ($manager) extends AbstractManagerRegistry {
            public function __construct(private EntityManager $manager)
            {
                parent::__construct('movie_metadata_fixture', ['default' => 'connection'], ['default' => 'manager'], 'default', 'default');
            }

            protected function getService(string $name): object
            {
                return $name === 'connection' ? $this->manager->getConnection() : $this->manager;
            }

            protected function resetService(string $name): void
            {
                $this->manager = ArtistImportIdentityRace::entityManager($this->manager->getConnection());
            }
        };
        $logger = new NullLogger();
        $adapter = new TmdbAdapter('fixture-token', $logger, new JsonEncoder(), new PublicHttpClient(
            $http,
            new PublicDestinationPolicy(static fn(string $host): array => ['1.1.1.1']),
        ), ProviderSwitches::allEnabled());

        $request = new VideoRequest(
            VideoKind::Movie,
            $movie->getTitle(),
            $movie->getYear(),
            ['tmdb' => '12'],
            libraryId: $movie->getLibraryId(),
            titleId: $movieId,
        );
        $provider = new TmdbVideoMetadata($adapter);
        $connection = $manager->getConnection();
        $clock = new NativeClock();
        $states = new VideoMatchStateRepository($connection);
        $states->requestMovie($movieId, 'admin', $clock->now());
        $claim = $states->claimOldestDueRequest($clock->now(), Uuid::v7(), 60)
            ?? throw new \LogicException('The fixture movie has no due match.');
        $metadata = $provider->identify($request);
        if (!$metadata instanceof VideoTitle) {
            throw new \LogicException('The fixture provider did not return a movie.');
        }

        $transaction = new DoctrineTransaction($registry, new Swoole());
        $catalog = new TvCatalogRepository($manager);
        $people = new PersonIdentityRepository($connection, $transaction, $manager);
        $applier = new ApplyVideoMatchHandler(
            $movies,
            $catalog,
            new PersonCreditRepository($connection, $people),
            new GenreService(new GenreRepository($manager), new GenreMovieRepository($manager)),
            self::unusedArtworkImporter(),
            new PersonProfileSyncRequester(new MessageBus(), $logger),
        );
        $placements = new VideoPlacementService(
            $catalog,
            new EpisodePlacer(),
            new WatchedMarkMove($connection, new WatchedMarkRepository($connection)),
            $transaction,
            $movies,
        );
        $apply = new VideoMatchApply(
            $transaction,
            $states,
            $movies,
            $catalog,
            $clock,
            $placements,
            $applier,
        );
        $target = new VideoMatchTarget($movieId, $request, [], $movie->getMatchedProvider() !== null);

        $resolved = new ResolvedVideoMatch($metadata, $request->ordering);
        $prepared = $applier->prepare($movie->getLibraryId(), $metadata);
        $committed = false;
        try {
            $committed = $apply->apply($claim, $target, VideoProviderId::Tmdb, $resolved, $prepared);

            return $committed;
        } finally {
            $applier->discard($prepared, $committed);
        }
    }

    private static function unusedArtworkImporter(): VideoArtworkImportInterface
    {
        return new class implements VideoArtworkImportInterface {
            public function prepare(Uuid $libraryId, string $absolutePath): PreparedVideoArtwork
            {
                throw new \LogicException('The fixture provider requests no local artwork.');
            }

            public function persist(PreparedVideoArtwork $artwork): void
            {
                throw new \LogicException('The fixture provider requests no local artwork.');
            }

            public function discard(PreparedVideoArtwork $artwork): void
            {
                throw new \LogicException('The fixture provider requests no local artwork.');
            }
        };
    }

    /**
     * @param list<string> $names
     * @return array{process: resource, output: resource, application: string}
     */
    public static function start(string $schema, Uuid $movieId, array $names): array
    {
        $application = 'movie_metadata_' . bin2hex(random_bytes(6));
        $output = tmpfile();
        if ($output === false) {
            throw new \RuntimeException('Cannot create the contender output.');
        }
        $process = proc_open([
            PHP_BINARY,
            __DIR__ . '/movie-metadata.php',
            $schema,
            $application,
            $movieId->toString(),
            json_encode($names, JSON_THROW_ON_ERROR),
        ], [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output], $pipes);
        if (!is_resource($process)) {
            fclose($output);
            throw new \RuntimeException('Cannot start the contender.');
        }

        return ['process' => $process, 'output' => $output, 'application' => $application];
    }
}
