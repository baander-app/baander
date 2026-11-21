<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Application;

use App\Catalog\Application\Port\PersonCreditPortInterface;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Domain\Model\Movie;
use App\Metadata\Application\Message\SyncMovieMessage;
use App\Metadata\Application\MessageHandler\SyncMovieHandler;
use App\Metadata\Application\MovieMetadataEnricher;
use App\Metadata\Infrastructure\Api\Tmdb\TmdbAdapter;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use Baander\HttpClient\PublicDestinationPolicy;
use Baander\HttpClient\PublicHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class MovieMetadataEnricherTest extends TestCase
{
    public function testExistingIdentityUsesDirectLookupAndPreservesAllDirectorInputs(): void
    {
        $movie = Movie::create(Uuid::v7(), 'Film', 2026);
        $movie->updateExternalIds(tmdbId: 12);
        $movies = $this->createMock(MoviePortInterface::class);
        $movies->expects(self::never())->method('save');
        $movies->expects(self::once())->method('saveMetadata')->with($movie);
        $people = $this->createMock(PersonCreditPortInterface::class);
        $people->expects(self::once())->method('syncMovieCredits')->willReturnCallback(
            static function (Uuid $id, ?array $cast, ?array $credits, bool $force) use ($movie): bool {
                self::assertTrue($movie->getId()->equals($id));
                self::assertTrue($force);
                self::assertNull($cast);
                self::assertSame(['41', '42'], array_column($credits, 'tmdbId'));
                self::assertSame('https://image.tmdb.org/t/p/w185/one.jpg', $credits[0]->profileUrl);

                return true;
            },
        );
        $response = ['id' => 12, 'credits' => ['crew' => [
            ['id' => 41, 'name' => 'One', 'job' => 'Director', 'profile_path' => '/one.jpg'],
            ['id' => 42, 'name' => 'Two', 'job' => 'Director'],
        ]]];
        $http = new MockHttpClient(static function (string $method, string $url) use ($response): MockResponse {
            self::assertStringContainsString('/movie/12?', $url);
            self::assertStringContainsString('append_to_response=credits', $url);

            return new MockResponse(json_encode($response, JSON_THROW_ON_ERROR));
        });

        $result = $this->enricher($http, $movies, $people)->enrich($movie, forceUpdate: true);

        self::assertTrue($result->isSuccess());
        self::assertContains('credits', $result->getUpdatedFields());
    }

    public function testAbsentCreditsPreserveStoredSetAndSuccessfulEmptyCanBeApplied(): void
    {
        foreach ([false, true] as $available) {
            $movie = Movie::create(Uuid::v7(), 'Film', 2026);
            $movie->updateExternalIds(tmdbId: 12);
            $movies = $this->createMock(MoviePortInterface::class);
            $movies->expects(self::once())->method('saveMetadata');
            $people = $this->createMock(PersonCreditPortInterface::class);
            $people->expects($available ? self::once() : self::never())->method('syncMovieCredits')
                ->with($movie->getId(), null, [], true)->willReturn(true);
            $data = ['id' => 12];
            if ($available) {
                $data['credits'] = ['crew' => []];
            }
            $http = new MockHttpClient(new MockResponse(json_encode($data, JSON_THROW_ON_ERROR)));
            self::assertTrue($this->enricher($http, $movies, $people)->enrich($movie, true)->isSuccess());
        }
    }

    public function testConflictingTitleMatchWritesNeitherLocalMovie(): void
    {
        $movie = Movie::create(Uuid::v7(), 'Film', 2026);
        $other = Movie::create(Uuid::v7(), 'Other Film', 2026);
        $movies = $this->createMock(MoviePortInterface::class);
        $movies->expects(self::once())->method('findByTmdbId')->with(12)->willReturn($other);
        $movies->expects(self::never())->method('save');
        $movies->expects(self::never())->method('saveMetadata');
        $people = $this->createMock(PersonCreditPortInterface::class);
        $people->expects(self::never())->method('syncMovieCredits');
        $http = new MockHttpClient([
            new MockResponse('{"results":[{"id":12,"title":"Film","vote_count":30}]}'),
            new MockResponse('{"id":12,"overview":"Provider metadata","credits":{"crew":[]}}'),
        ]);

        $result = $this->enricher($http, $movies, $people)->enrich($movie, true);

        self::assertFalse($result->isSuccess());
        self::assertFalse($result->isFailure());
        self::assertNull($movie->getTmdbId());
        self::assertNull($other->getOverview());
    }

    public function testMovieConsumerAcknowledgesMissingMovieAndNoMatchButRetriesProviderFailure(): void
    {
        $movie = Movie::create(Uuid::v7(), 'Film', 2026);
        $movie->updateExternalIds(tmdbId: 12);
        foreach ([404, 503] as $status) {
            $movies = $this->createMock(MoviePortInterface::class);
            $movies->method('findByUuid')->willReturn($movie);
            $movies->expects(self::never())->method('save');
            $movies->expects(self::never())->method('saveMetadata');
            $people = $this->createMock(PersonCreditPortInterface::class);
            $people->expects(self::never())->method('syncMovieCredits');
            $http = new MockHttpClient(new MockResponse('{}', ['http_code' => $status]));
            $handler = new SyncMovieHandler($movies, $this->enricher($http, $movies, $people));
            try {
                $handler(new SyncMovieMessage($movie->getId(), true));
                self::assertSame(404, $status);
            } catch (ServiceUnavailableException) {
                self::assertSame(503, $status);
            }
        }
        $movies = $this->createStub(MoviePortInterface::class);
        $people = $this->createStub(PersonCreditPortInterface::class);
        $http = new MockHttpClient(static function (): never {
            self::fail('A deleted movie must not call TMDB.');
        });
        (new SyncMovieHandler($movies, $this->enricher($http, $movies, $people)))(new SyncMovieMessage(Uuid::v7()));
    }

    public function testCastAndCrewAvailabilityReachTheWriteTransactionIndependently(): void
    {
        $movie = Movie::create(Uuid::v7(), 'Film', 2026);
        $movie->updateExternalIds(tmdbId: 12);
        $movies = $this->createMock(MoviePortInterface::class);
        $movies->expects(self::once())->method('saveMetadata');
        $people = $this->createMock(PersonCreditPortInterface::class);
        $people->expects(self::once())->method('syncMovieCredits')->with($movie->getId(), [], null, true)->willReturn(true);
        $http = new MockHttpClient(new MockResponse(json_encode([
            'id' => 12,
            'credits' => ['cast' => [], 'crew' => [['id' => 41, 'job' => 'Director']]],
        ], JSON_THROW_ON_ERROR)));

        self::assertTrue($this->enricher($http, $movies, $people)->enrich($movie, true)->isSuccess());
    }

    public function testLookupCannotRedirectTheAcceptedMovieIdentity(): void
    {
        $movie = Movie::create(Uuid::v7(), 'Film', 2026);
        $movie->updateExternalIds(tmdbId: 12);
        $movies = $this->createMock(MoviePortInterface::class);
        $movies->expects(self::never())->method('saveMetadata');
        $people = $this->createMock(PersonCreditPortInterface::class);
        $people->expects(self::never())->method('syncMovieCredits');
        $http = new MockHttpClient(new MockResponse('{"id":13,"credits":{"cast":[],"crew":[]}}'));

        self::assertFalse($this->enricher($http, $movies, $people)->enrich($movie, true)->isSuccess());
        self::assertSame(12, $movie->getTmdbId());
    }

    private function enricher(MockHttpClient $http, MoviePortInterface $movies, PersonCreditPortInterface $people): MovieMetadataEnricher
    {
        $logger = new NullLogger();
        $adapter = new TmdbAdapter('fixture-token', $logger, new JsonEncoder(), new PublicHttpClient(
            $http,
            new PublicDestinationPolicy(static fn(string $host): array => ['1.1.1.1']),
        ));
        $transaction = new class implements TransactionPortInterface {
            public function run(callable $operation): mixed
            {
                return $operation();
            }
        };

        return new MovieMetadataEnricher($adapter, $movies, $this->createStub(GenrePortInterface::class), $logger, $people, $transaction);
    }
}
