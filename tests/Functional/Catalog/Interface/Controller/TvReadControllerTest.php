<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Interface\Controller;

use App\Catalog\Application\Port\MemberVideoCatalogReadInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

final class TvReadControllerTest extends TestCase
{
    private MemberVideoCatalogReadInterface&MockObject $read;
    private PublicId $library;
    private PublicId $series;
    private PublicId $season;
    private PublicId $episode;
    private PublicId $video;
    private PublicId $extra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->read = $this->createMock(MemberVideoCatalogReadInterface::class);
        static::getContainer()->set(MemberVideoCatalogReadInterface::class, $this->read);
        $this->library = new PublicId();
        $this->series = new PublicId();
        $this->season = new PublicId();
        $this->episode = new PublicId();
        $this->video = new PublicId();
        $this->extra = new PublicId();
    }

    public function testSeriesGridPassesPublicLibraryAndPagingFilters(): void
    {
        $this->read->expects(self::once())->method('series')->willReturnCallback(function (SearchOptions $options, LibraryReadScope $scope, ?PublicId $library): SearchResult {
            self::assertEquals($this->library, $library);
            self::assertSame(2, $options->getLimit());
            self::assertSame(2, $options->getOffset());

            return SearchResult::create([$this->seriesRow()], 3);
        });
        $path = '/api/tv/series?library=' . $this->library->toString() . '&page=2&limit=2';
        $data = $this->assertJsonResponse($this->authenticatedRequest('GET', $path, $this->createTestUser()), 200);
        self::assertSame(3, $data['meta']['total']);
        self::assertSame($this->library->toString(), $data['data'][0]['libraryPublicId']);
        self::assertSame('tvdb', $data['data'][0]['metadataSource']);
        self::assertArrayNotHasKey('id', $data['data'][0]);
        self::assertArrayNotHasKey('folderPath', $data['data'][0]);
    }

    public function testSeriesDetailCarriesOwnedSeasonCountsAndResumableExtras(): void
    {
        $user = $this->createTestUser();
        $this->read->expects(self::once())->method('seriesDetail')->with($this->series, self::anything(), $user->getId())->willReturn($this->seriesRow() + [
            'seasons' => [
                ['publicId' => $this->season->toString(), 'number' => 1, 'name' => 'Season 1', 'ownedCount' => 10, 'totalCount' => 12, 'watchedCount' => 4],
                ['publicId' => new PublicId()->toString(), 'number' => 0, 'name' => 'Specials', 'ownedCount' => 1, 'totalCount' => 3, 'watchedCount' => 0],
            ],
            'unseasonedCount' => 1,
            'extras' => [$this->version() + ['placementPublicId' => $this->extra->toString(), 'videoPublicId' => $this->video->toString(), 'kind' => 'trailer']],
        ]);
        $response = $this->authenticatedRequest('GET', '/api/tv/series/' . $this->series->toString(), $user);
        $data = $this->assertJsonResponse($response, 200)['data'];
        self::assertSame([1, 0], array_column($data['seasons'], 'number'));
        self::assertSame(10, $data['seasons'][0]['ownedCount']);
        self::assertSame(12, $data['seasons'][0]['totalCount']);
        self::assertSame(4, $data['seasons'][0]['watchedCount']);
        self::assertSame($this->extra->toString(), $data['extras'][0]['placementPublicId']);
        self::assertSame(25.5, $data['extras'][0]['positionSeconds']);
        self::assertArrayNotHasKey('path', $data['extras'][0]);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testEpisodeStateUsesTheRequestingMemberAndTypedFileVersions(): void
    {
        $first = $this->createTestUser();
        $second = $this->createTestUser();
        $this->read->expects(self::exactly(2))->method('episodeDetail')->willReturnCallback(function (PublicId $id, LibraryReadScope $scope, ?Uuid $member) use ($first, $second): array {
            self::assertEquals($this->episode, $id);
            self::assertNotNull($member);
            self::assertTrue($member->equals($first->getId()) || $member->equals($second->getId()));

            return $this->episodeRow() + ['watched' => $member->equals($first->getId()), 'progress' => $member->equals($first->getId()) ? 0.25 : null];
        });
        $firstData = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/tv/episodes/' . $this->episode->toString(), $first), 200)['data'];
        $secondData = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/tv/episodes/' . $this->episode->toString(), $second), 200)['data'];
        self::assertTrue($firstData['watched']);
        self::assertSame(0.25, $firstData['progress']);
        self::assertFalse($secondData['watched']);
        self::assertNull($secondData['progress']);
        self::assertSame($this->video->toString(), $firstData['defaultVideoPublicId']);
        self::assertSame('2026-11-28T12:00:00+00:00', $firstData['videos'][0]['lastPlayedAt']);
        self::assertArrayNotHasKey('path', $firstData['videos'][0]);
        self::assertArrayNotHasKey('id', $firstData);
    }

    public function testEpisodePagesPassSeasonAndExplicitUnseasonedFilters(): void
    {
        $user = $this->createTestUser();
        $calls = 0;
        $this->read->expects(self::exactly(3))->method('episodes')->willReturnCallback(function (PublicId $series, SearchOptions $options, LibraryReadScope $scope, ?Uuid $member, ?PublicId $season, bool $unseasoned) use (&$calls, $user): SearchResult {
            self::assertEquals($this->series, $series);
            self::assertEquals($user->getId(), $member);
            if ($calls === 0) {
                self::assertEquals($this->season, $season);
                self::assertFalse($unseasoned);
            } elseif ($calls === 1) {
                self::assertNull($season);
                self::assertTrue($unseasoned);
            } else {
                self::assertNull($season);
                self::assertFalse($unseasoned);
            }
            ++$calls;

            return SearchResult::create([$this->episodeRow() + ['watched' => false, 'progress' => null]], 1);
        });
        foreach (['?season=' . $this->season->toString(), '?unseasoned=true', ''] as $query) {
            $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/tv/series/' . $this->series->toString() . '/episodes' . $query, $user), 200);
        }
    }

    public function testInvalidReadFiltersFailBeforeQueries(): void
    {
        $user = $this->createTestUser();
        $this->read->expects(self::never())->method('series');
        $this->read->expects(self::never())->method('episodes');
        foreach ([
            '/api/tv/series?limit=101', '/api/tv/series?limit=0', '/api/tv/series?page=0', '/api/tv/series?library=not-an-id',
            '/api/tv/series/' . $this->series->toString() . '/episodes?limit=101',
            '/api/tv/series/' . $this->series->toString() . '/episodes?season=not-an-id',
            '/api/tv/series/' . $this->series->toString() . '/episodes?season=' . $this->season->toString() . '&unseasoned=true',
        ] as $path) {
            $this->assertJsonResponse($this->authenticatedRequest('GET', $path, $user), 400);
        }
    }

    public function testUnavailableOrDeniedTitlesAreNotFoundAndAnonymousReadsRequireAuthentication(): void
    {
        $this->read->expects(self::once())->method('seriesDetail')->willReturn(null);
        $this->read->expects(self::once())->method('episodeDetail')->willReturn(null);
        $user = $this->createTestUser();
        $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/tv/series/' . $this->series->toString(), $user), 404);
        $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/tv/episodes/' . $this->episode->toString(), $user), 404);
        $this->client->request('GET', '/api/tv/series');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    /** @return array<string, mixed> */
    private function seriesRow(): array
    {
        return ['publicId' => $this->series->toString(), 'name' => 'Orbit', 'overview' => null, 'posterUrl' => null, 'libraryPublicId' => $this->library->toString(), 'metadataSource' => 'tvdb', 'id' => Uuid::v7()->toString(), 'folderPath' => '/private/library/Orbit'];
    }

    /** @return array<string, mixed> */
    private function episodeRow(): array
    {
        return ['publicId' => $this->episode->toString(), 'name' => 'Pilot', 'overview' => null, 'stillUrl' => null, 'airDate' => '2026-01-01', 'seasonNumber' => 1, 'episodeNumber' => 1, 'seriesPublicId' => $this->series->toString(), 'seriesName' => 'Orbit', 'seasonPublicId' => $this->season->toString(), 'absoluteNumber' => 1, 'libraryPublicId' => $this->library->toString(), 'metadataSource' => 'tvdb', 'defaultVideoPublicId' => $this->video->toString(), 'videos' => [$this->version()], 'id' => Uuid::v7()->toString()];
    }

    /** @return array<string, mixed> */
    private function version(): array
    {
        return ['publicId' => $this->video->toString(), 'width' => 1920, 'height' => 1080, 'duration' => 100, 'videoBitrate' => 2000000, 'positionSeconds' => 25.5, 'lastPlayedAt' => '2026-11-28T12:00:00+00:00', 'path' => '/private/video.mkv'];
    }
}
