<?php

declare(strict_types=1);

namespace App\Tests\Functional\Metadata\Application;

use App\Tests\Fixtures\Metadata\ProviderSwitches;

use App\Catalog\Application\Port\DirectorPortInterface;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Application\Port\PersonCreditPortInterface;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\ReadModel\DirectorReadView;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Metadata\Application\Message\SyncMovieMessage;
use App\Metadata\Application\MessageHandler\SyncMovieHandler;
use App\Metadata\Application\MovieMetadataEnricher;
use App\Metadata\Infrastructure\Api\Tmdb\TmdbAdapter;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Tests\Functional\TestCase;
use Baander\HttpClient\PublicDestinationPolicy;
use Baander\HttpClient\PublicHttpClient;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class MovieMetadataSyncTest extends TestCase
{
    public function testMovieConsumerPersistsCreditsAndReusesIdentities(): void
    {
        $container = static::getContainer();
        $library = new LibraryEntity('Owned sync', 'director-sync-' . bin2hex(random_bytes(4)), '/media/director-sync', 'movie', 'local');
        $this->entityManager->persist($library);
        $this->entityManager->flush();
        $movie = Movie::create($library->getId(), 'Owned Enriched Movie', 2026);
        $movie->updateExternalIds(tmdbId: 567);
        $movies = $container->get(MoviePortInterface::class);
        $movies->save($movie);
        $message = new SyncMovieMessage($movie->getId());
        $calls = 0;
        $http = new MockHttpClient(static function (string $method, string $url) use (&$calls): MockResponse {
            self::assertStringContainsString('/movie/567?', $url);
            ++$calls;
            $crew = $calls < 3 ? [
                ['id' => 2_000_000_081, 'name' => 'Integration Director One', 'job' => 'Director'],
                ['id' => 2_000_000_082, 'name' => 'Integration Director Two', 'job' => 'Director'],
            ] : [];

            return new MockResponse(json_encode([
                'id' => 567,
                'overview' => 'Persisted provider overview',
                'credits' => ['crew' => $crew],
            ], JSON_THROW_ON_ERROR));
        });
        $directors = $container->get(DirectorPortInterface::class);
        $enricher = new MovieMetadataEnricher(
            new TmdbAdapter('fixture-token', new NullLogger(), new JsonEncoder(), new PublicHttpClient(
                $http,
                new PublicDestinationPolicy(static fn(string $host): array => ['1.1.1.1']),
            ), ProviderSwitches::allEnabled()),
            $movies,
            $container->get(GenrePortInterface::class),
            new NullLogger(),
            $container->get(PersonCreditPortInterface::class),
            $container->get(TransactionPortInterface::class),
        );
        $consumer = new SyncMovieHandler($movies, $enricher);
        $consumer($message);
        $this->entityManager->clear();
        $scope = LibraryReadScope::restricted([$library->getId()]);
        $result = $directors->searchVisible(SearchOptions::create('Integration Director'), $scope);
        self::assertSame(2, $result->getTotal());
        $ids = array_map(static fn(DirectorReadView $director): string => $director->publicId->toString(), $result->getItems());
        self::assertSame('Persisted provider overview', $movies->findByUuid($movie->getId())->getOverview());
        foreach ($result->getItems() as $director) {
            self::assertSame(1, $director->movieCount);
            self::assertSame(1, $directors->searchMoviesVisible($director->publicId, SearchOptions::create(''), $scope)->getTotal());
        }
        $consumer($message);
        $this->entityManager->clear();
        self::assertSame($ids, array_map(
            static fn(DirectorReadView $director): string => $director->publicId->toString(),
            $directors->searchVisible(SearchOptions::create('Integration Director'), $scope)->getItems(),
        ));
        $consumer(new SyncMovieMessage($movie->getId(), true));
        $this->entityManager->clear();
        self::assertSame(0, $directors->searchVisible(SearchOptions::create('Integration Director'), $scope)->getTotal());
        self::assertSame(3, $calls);
    }
}
