<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Metadata;

use App\Catalog\Infrastructure\Doctrine\Repository\PersonCreditRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\PersonIdentityRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\GenreMovieRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\GenreRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\MovieRepository;
use App\Catalog\Infrastructure\GenreService;
use App\Catalog\Infrastructure\MovieService;
use App\Metadata\Application\EnrichmentResult;
use App\Metadata\Application\MovieMetadataEnricher;
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
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class MovieMetadataRace
{
    /** @param list<string> $names */
    public static function enrich(EntityManager $manager, Uuid $movieId, array $names, ?\Closure $duringLookup = null): EnrichmentResult
    {
        $movies = new MovieRepository($manager);
        $movie = $movies->findByUuid($movieId) ?? throw new \LogicException('Missing fixture movie.');
        $genres = array_map(static fn(int $index, string $name): array => ['id' => $index + 1, 'name' => $name], array_keys($names), $names);
        $http = new MockHttpClient(static function (string $method, string $url) use ($genres, $duringLookup): MockResponse {
            if (str_contains($url, '/genre/movie/list')) {
                return new MockResponse(json_encode(['genres' => $genres], JSON_THROW_ON_ERROR));
            }
            $duringLookup?->__invoke();

            return new MockResponse(json_encode([
                'id' => 12,
                'overview' => 'Provider overview',
                'genre_ids' => array_column($genres, 'id'),
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
        ));

        $transaction = new DoctrineTransaction($registry, new Swoole());

        return new MovieMetadataEnricher(
            $adapter,
            new MovieService($movies),
            new GenreService(new GenreRepository($manager), new GenreMovieRepository($manager)),
            $logger,
            new PersonCreditRepository($manager->getConnection(), new PersonIdentityRepository($manager->getConnection(), $transaction, $manager)),
            $transaction,
        )->enrich($movie, true);
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
