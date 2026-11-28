<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Interface\Controller;

use App\Catalog\Application\Port\MemberVideoCatalogReadInterface;
use App\Catalog\Domain\Model\Movie;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

final class MovieControllerTest extends TestCase
{
    private MemberVideoCatalogReadInterface&MockObject $read;

    protected function setUp(): void
    {
        parent::setUp();
        $this->read = $this->createMock(MemberVideoCatalogReadInterface::class);
        static::getContainer()->set(MemberVideoCatalogReadInterface::class, $this->read);
    }

    public function testAMalformedMovieIdIsNotFoundLikeAnUnknownOne(): void
    {
        $user = $this->createTestUser();
        $unknownId = new PublicId();
        $this->read->expects(self::once())->method('movieDetail')->with($unknownId, self::anything(), $user->getId())->willReturn(null);
        $malformed = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/movies/not-an-id', $user), 404, 'error');
        $unknown = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/movies/' . $unknownId->toString(), $user), 404, 'error');

        self::assertSame($unknown, $malformed);
    }

    public function testMovieDetailKeepsMetadataAndAddsTypedPlayableVersionsAndExtraResume(): void
    {
        $user = $this->createTestUser();
        $movie = Movie::create(Uuid::v7(), 'Arrival', 2016, 'Existing summary');
        $movie->updateMetadata(overview: 'Provider overview', tagline: 'One message', posterUrl: 'https://images.baander.app/poster.jpg', runtime: 116, rating: 7.9, originalLanguage: 'en', tmdbCollectionId: 12, collectionName: 'A collection');
        $movie->updateExternalIds(329865, 'tt2543164');
        $row = $this->row($movie);
        $this->read->expects(self::once())->method('movieDetail')->with($movie->getPublicId(), self::anything(), $user->getId())->willReturn($row);
        $response = $this->authenticatedRequest('GET', '/api/movies/' . $movie->getPublicId()->toString(), $user);
        $data = $this->assertJsonResponse($response, 200)['data'];
        foreach (['title' => 'Arrival', 'year' => 2016, 'summary' => 'Existing summary', 'overview' => 'Provider overview', 'tagline' => 'One message', 'runtime' => 116, 'rating' => 7.9, 'originalLanguage' => 'en', 'tmdbId' => 329865, 'imdbId' => 'tt2543164', 'tmdbCollectionId' => 12, 'collectionName' => 'A collection', 'metadataSource' => 'tmdb'] as $key => $value) {
            self::assertSame($value, $data[$key]);
        }
        self::assertSame($row['libraryPublicId'], $data['libraryPublicId']);
        self::assertSame([$row['videos'][0]['publicId']], $data['videoIds']);
        self::assertSame($row['videos'][0]['publicId'], $data['defaultVideoPublicId']);
        self::assertSame('https://images.baander.app/poster.jpg', $data['posterImage']['url']);
        self::assertSame(25.5, $data['extras'][0]['positionSeconds']);
        self::assertSame($row['extras'][0]['placementPublicId'], $data['extras'][0]['placementPublicId']);
        self::assertFalse($data['watched']);
        self::assertSame(0.25, $data['progress']);
        self::assertArrayNotHasKey('path', $data['videos'][0]);
        self::assertArrayNotHasKey('path', $data['extras'][0]);
        self::assertArrayNotHasKey('id', $data);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testMoviePagesPassPagingAndCurrentMemberToTheAvailableRead(): void
    {
        $user = $this->createTestUser();
        $movie = Movie::create(Uuid::v7(), 'Playable movie');
        $row = $this->row($movie);
        $this->read->expects(self::once())->method('movies')->willReturnCallback(static function (SearchOptions $options, LibraryReadScope $scope, ?Uuid $member) use ($row, $user): SearchResult {
            self::assertSame(1, $options->getLimit());
            self::assertSame(1, $options->getOffset());
            self::assertEquals($user->getId(), $member);

            return SearchResult::create([$row], 2);
        });
        $data = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/movies/?page=2&limit=1', $user), 200);
        self::assertSame(2, $data['meta']['total']);
        self::assertSame($movie->getPublicId()->toString(), $data['data'][0]['publicId']);
        self::assertCount(1, $data['data'][0]['videos']);
    }

    public function testInvalidMovieLimitsFailBeforeQueryingAndAnonymousReadsRequireAuthentication(): void
    {
        $this->read->expects(self::never())->method('movies');
        $user = $this->createTestUser();
        foreach (['limit=0', 'limit=101', 'page=0'] as $query) {
            $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/movies/?' . $query, $user), 400);
        }
        $this->client->request('GET', '/api/movies/');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    /** @return array<string, mixed> */
    private function row(Movie $movie): array
    {
        $video = new PublicId()->toString();
        $version = ['publicId' => $video, 'width' => 1920, 'height' => 1080, 'duration' => 100, 'videoBitrate' => 2000000, 'positionSeconds' => 25.5, 'lastPlayedAt' => '2026-11-28T12:00:00+00:00', 'path' => '/private/video.mkv'];

        return ['movie' => $movie, 'libraryPublicId' => new PublicId()->toString(), 'videos' => [$version], 'extras' => [$version + ['placementPublicId' => new PublicId()->toString(), 'videoPublicId' => $video, 'kind' => 'trailer']], 'metadataSource' => 'tmdb', 'watched' => false, 'progress' => 0.25, 'defaultVideoPublicId' => $video];
    }
}
