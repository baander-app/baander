<?php

declare(strict_types=1);

namespace App\Tests\Functional\Metadata\Application;

use App\Catalog\Application\CommandHandler\VideoMatch\ApplyVideoMatchHandler;
use App\Catalog\Application\Port\DirectorPortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\ReadModel\DirectorReadView;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Metadata\Infrastructure\Api\Tmdb\TmdbAdapter;
use App\Metadata\Infrastructure\Video\TmdbVideoMetadata;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Tests\Fixtures\Metadata\ProviderSwitches;
use App\Tests\Functional\TestCase;
use Baander\HttpClient\PublicDestinationPolicy;
use Baander\HttpClient\PublicHttpClient;
use Doctrine\DBAL\LockMode;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class MovieMetadataSyncTest extends TestCase
{
    public function testMovieMatchPersistsCreditsAndReusesIdentities(): void
    {
        $container = static::getContainer();
        $library = new LibraryEntity('Owned sync', 'director-sync-' . bin2hex(random_bytes(4)), '/media/director-sync', 'movie', 'local');
        $this->entityManager->persist($library);
        $this->entityManager->flush();
        $movie = Movie::create($library->getId(), 'Owned Enriched Movie', 2026);
        $movie->updateExternalIds(tmdbId: 567);
        $movies = $container->get(MoviePortInterface::class);
        $movies->save($movie);
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
                'title' => 'Owned Enriched Movie',
                'overview' => 'Persisted provider overview',
                'credits' => ['crew' => $crew],
            ], JSON_THROW_ON_ERROR));
        });
        $directors = $container->get(DirectorPortInterface::class);
        $provider = new TmdbVideoMetadata(new TmdbAdapter(
            'fixture-token',
            new NullLogger(),
            new JsonEncoder(),
            new PublicHttpClient($http, new PublicDestinationPolicy(static fn (string $host): array => ['1.1.1.1'])),
            ProviderSwitches::allEnabled(),
        ));
        $this->applyMetadata($provider, $movie);
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
        $this->applyMetadata($provider, $movie);
        $this->entityManager->clear();
        self::assertSame($ids, array_map(
            static fn(DirectorReadView $director): string => $director->publicId->toString(),
            $directors->searchVisible(SearchOptions::create('Integration Director'), $scope)->getItems(),
        ));
        $this->applyMetadata($provider, $movie, force: true);
        $this->entityManager->clear();
        self::assertSame(0, $directors->searchVisible(SearchOptions::create('Integration Director'), $scope)->getTotal());
        self::assertSame(3, $calls);
    }

    private function applyMetadata(TmdbVideoMetadata $provider, Movie $movie, bool $force = false): void
    {
        $request = new VideoRequest(
            VideoKind::Movie,
            $movie->getTitle(),
            $movie->getYear(),
            ['tmdb' => '567'],
            libraryId: $movie->getLibraryId(),
            titleId: $movie->getId(),
        );
        $metadata = $provider->identify($request);
        self::assertInstanceOf(VideoTitle::class, $metadata);
        $container = static::getContainer();
        $apply = $container->get(ApplyVideoMatchHandler::class);
        $movies = $container->get(MoviePortInterface::class);
        $transaction = $container->get(TransactionPortInterface::class);
        $prepared = $apply->prepare($movie->getLibraryId(), $metadata);
        $committed = false;
        try {
            $transaction->run(function () use ($apply, $movies, $movie, $prepared, $force): void {
                $this->entityManager->find(MovieEntity::class, $movie->getId(), LockMode::PESSIMISTIC_WRITE);
                $stored = $movies->findByUuid($movie->getId());
                self::assertInstanceOf(Movie::class, $stored);
                $apply->apply($stored, VideoProviderId::Tmdb, $prepared, $force);
            });
            $committed = true;
        } finally {
            $apply->discard($prepared, $committed);
        }
    }
}
