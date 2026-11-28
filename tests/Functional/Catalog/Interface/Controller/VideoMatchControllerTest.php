<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Interface\Controller;

use App\Catalog\Application\Port\VideoMatchAdminWriteInterface;
use App\Catalog\Application\Port\VideoMatchTargetLoaderInterface;
use App\Catalog\Application\Query\VideoMatch\VideoMatchLibraryPreview;
use App\Catalog\Application\Query\VideoMatch\VideoMatchReview;
use App\Catalog\Application\Query\VideoMatch\VideoMatchReviewPage;
use App\Catalog\Application\Query\VideoMatch\VideoMatchReviewReadInterface;
use App\Catalog\Application\Query\VideoMatch\VideoMatchTargetIdentity;
use App\Catalog\Application\Service\VideoMatch\VideoMatchAdminService;
use App\Catalog\Application\Service\VideoMatch\VideoMatchTarget;
use App\Catalog\Domain\Model\TvEpisode;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Model\VideoPlacement;
use App\Catalog\Domain\Model\VideoProviderPause;
use App\Catalog\Domain\Repository\MovieRepositoryInterface;
use App\Catalog\Domain\Repository\TvCatalogRepositoryInterface;
use App\Catalog\Domain\ValueObject\VideoName\ExtraKind;
use App\Library\Application\Port\LibraryTypeLookupInterface;
use App\Library\Domain\ValueObject\LibraryType;
use App\Metadata\Application\DTO\Video\VideoCandidate;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoOrdering;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoProviderProblem;
use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Metadata\Application\Port\VideoMetadataPortInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Port\JobCancellationCheckpointInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class VideoMatchControllerTest extends TestCase
{
    private VideoMatchReviewReadInterface&MockObject $read;
    private VideoMetadataPortInterface&Stub $providers;
    private VideoMatchAdminWriteInterface&MockObject $write;
    private TvSeries $series;
    private VideoMatchTargetIdentity $identity;
    private string $publicId;
    private string $libraryPublicId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->read = $this->createMock(VideoMatchReviewReadInterface::class);
        $this->providers = $this->createStub(VideoMetadataPortInterface::class);
        $this->write = $this->createMock(VideoMatchAdminWriteInterface::class);
        $this->series = TvSeries::create(Uuid::v7(), 'Orbit', 'Orbit', 2024);
        $this->series->match('tvdb', '42', ['tvdb' => '42'], true);
        $this->publicId = $this->series->getPublicId()->toString();
        $this->libraryPublicId = new PublicId()->toString();
        $this->identity = new VideoMatchTargetIdentity($this->series->getId(), VideoKind::Series, $this->publicId, $this->series->getLibraryId(), true, 'tvdb', '42', 'aired');
        $catalog = $this->createStub(TvCatalogRepositoryInterface::class);
        $catalog->method('findSeriesByUuid')->willReturn($this->series);
        $targets = $this->createStub(VideoMatchTargetLoaderInterface::class);
        $targets->method('forTitle')->willReturn(new VideoMatchTarget($this->series->getId(), new VideoRequest(VideoKind::Series, 'Orbit', 2024), [], true));
        $libraries = $this->createStub(LibraryTypeLookupInterface::class);
        $libraries->method('resolveId')->willReturn($this->series->getLibraryId());
        $libraries->method('findType')->willReturn(LibraryType::TvShow);
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        static::getContainer()->set(VideoMatchAdminService::class, new VideoMatchAdminService(
            $this->read,
            $this->createStub(MovieRepositoryInterface::class),
            $catalog,
            $targets,
            $this->providers,
            $this->write,
            $libraries,
            $bus,
            $this->createStub(JobCancellationCheckpointInterface::class),
        ));
    }

    public function testEveryMatchRouteRequiresFirstPartyAdministratorAuthority(): void
    {
        $member = $this->createTestUser();
        $admin = $this->createAdminUser();
        $this->read->expects(self::never())->method('page');
        $this->write->expects(self::never())->method('rematch');
        $this->write->expects(self::never())->method('requestLibraryTitle');
        $this->write->expects(self::never())->method('setOrdering');
        $this->write->expects(self::never())->method('assignFile');
        foreach ([
            ['GET', '/reviews', []],
            ['GET', '/series/' . $this->publicId . '/candidates', []],
            ['POST', '/series/' . $this->publicId . '/apply', ['provider' => 'tvdb', 'candidateId' => '42']],
            ['GET', '/libraries/' . $this->libraryPublicId . '/rematch-preview', []],
            ['POST', '/libraries/' . $this->libraryPublicId . '/rematch', ['confirmed' => true, 'force' => true]],
            ['PUT', '/series/' . $this->publicId . '/ordering', ['ordering' => 'dvd']],
            ['PUT', '/files/' . $this->publicId . '/assignment', ['extraKind' => 'trailer']],
        ] as [$method, $path, $body]) {
            $url = '/api/admin/video-matches' . $path;
            self::assertSame(401, $this->anonymousRequest($method, $url, $body)->getStatusCode(), $url);
            self::assertSame(403, $this->authenticatedRequest($method, $url, $member, $body)->getStatusCode(), $url);
            self::assertSame(403, $this->delegatedRequest($method, $url, $admin, ['profile', 'library'], $body)->getStatusCode(), $url);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidLimits(): iterable
    {
        foreach (['0', '-1', '101', '1.5', 'no', '', '1e2'] as $value) {
            yield 'limit ' . $value => [$value];
        }
        yield 'array' => ['1&limit[]=2'];
    }

    #[DataProvider('invalidLimits')]
    public function testInvalidReviewLimitsAreRejectedBeforeTheRead(string $limit): void
    {
        $this->write->expects(self::never())->method('rematch');
        $this->read->expects(self::never())->method('page');
        $response = $this->authenticatedRequest('GET', '/api/admin/video-matches/reviews?limit=' . $limit, $this->createAdminUser());
        self::assertSame(400, $response->getStatusCode());
    }

    public function testReviewPageAndPausesHaveTheSamePublicDataInHttpAndConsole(): void
    {
        $this->write->expects(self::never())->method('rematch');
        $checked = new DateTimeImmutable('2026-11-28T10:00:00+00:00');
        $fileId = new PublicId()->toString();
        $this->read->expects(self::exactly(2))->method('page')->willReturnCallback(function (?string $cursor, int $limit, ?string $kind, ?Uuid $library, ?string $reason) use ($checked, $fileId): VideoMatchReviewPage {
            self::assertSame('after-first-page', $cursor);
            self::assertSame(2, $limit);
            self::assertSame('file', $kind);
            self::assertInstanceOf(Uuid::class, $library);
            self::assertTrue($this->series->getLibraryId()->equals($library));
            self::assertSame('episode_not_found', $reason);
            return new VideoMatchReviewPage([
                new VideoMatchReview('file', $fileId, 'Orbit', 'Orbit/Unknown.mkv', $this->libraryPublicId, 'Television', 'episode_not_found', $checked, null, 'series', $this->publicId),
            ], 'after-second-page', true, 2, [new VideoProviderPause('tvdb', 'login_failed', 2, $checked->modify('+5 minutes'))]);
        });
        $path = '/api/admin/video-matches/reviews?limit=2&cursor=after-first-page&kind=file&library=' . $this->libraryPublicId . '&reason=episode_not_found';
        $data = $this->assertJsonResponse($this->authenticatedRequest('GET', $path, $this->createAdminUser()), 200)['data'];
        self::assertSame($fileId, $data['items'][0]['publicId']);
        self::assertSame('series', $data['items'][0]['ownerKind']);
        self::assertSame($this->publicId, $data['items'][0]['ownerPublicId']);
        self::assertSame('Orbit/Unknown.mkv', $data['items'][0]['path']);
        self::assertSame([['provider' => 'tvdb', 'retryAt' => '2026-11-28T10:05:00+00:00', 'failureKind' => 'login_failed']], $data['pausedProviders']);
        self::assertSame('after-second-page', $data['nextCursor']);
        self::assertSame($data, $this->console('reviews', ['--limit' => '2', '--cursor' => 'after-first-page', '--kind' => 'file', '--library' => $this->libraryPublicId, '--reason' => 'episode_not_found']));
    }

    public function testMalformedCursorAndKindHaveTheSharedInvalidInputOutcome(): void
    {
        $this->write->expects(self::never())->method('rematch');
        $this->read->expects(self::exactly(2))->method('page')
            ->with('broken', 50, null, null, null)
            ->willThrowException(new InvalidInputException('Invalid review cursor.'));
        $admin = $this->createAdminUser();
        $invalidCursor = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/admin/video-matches/reviews?cursor=broken', $admin), 422);
        self::assertSame('Invalid review cursor.', $invalidCursor['error']['message']);
        $invalidKind = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/admin/video-matches/reviews?kind=episode', $admin), 422);
        self::assertSame('Unknown video review kind.', $invalidKind['error']['message']);
        $tester = $this->command('reviews');
        self::assertSame(Command::INVALID, $tester->execute(['--cursor' => 'broken', '--json' => true], ['capture_stderr_separately' => true]));
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('Invalid review cursor.', $tester->getErrorOutput());
        $invalidKindCommand = $this->command('reviews');
        self::assertSame(Command::INVALID, $invalidKindCommand->execute(['--kind' => 'episode', '--json' => true], ['capture_stderr_separately' => true]));
        self::assertSame('', $invalidKindCommand->getDisplay());
        self::assertStringContainsString('Unknown video review kind.', $invalidKindCommand->getErrorOutput());
    }

    public function testCandidateSearchUsesAllUsableProvidersAndConsolePreservesFailures(): void
    {
        $this->write->expects(self::never())->method('rematch');
        $this->read->expects(self::exactly(2))->method('findTarget')->with('tv', $this->series->getPublicId())->willReturn($this->identity);
        $this->providers->method('usableProviders')->willReturn([VideoProviderId::Tvdb, VideoProviderId::Tmdb]);
        $this->providers->method('search')->willReturnCallback(static function (VideoProviderId $provider, VideoRequest $request): array|VideoProviderProblem {
            self::assertSame('Orbit again', $request->title);
            return $provider === VideoProviderId::Tvdb ? [new VideoCandidate('42', 'Orbit', 2024, ['imdb' => 'tt12345'])] : VideoProviderProblem::Unavailable;
        });
        $path = '/api/admin/video-matches/series/' . $this->publicId . '/candidates?q=Orbit%20again';
        $data = $this->assertJsonResponse($this->authenticatedRequest('GET', $path, $this->createAdminUser()), 200)['data'];
        self::assertSame(['tvdb', 'tmdb'], array_column($data['providers'], 'provider'));
        self::assertSame('unavailable', $data['providers'][1]['problem']);
        self::assertSame($data, $this->console('search', ['kind' => 'series', 'public-id' => $this->publicId, '--query' => 'Orbit again']));
    }

    public function testCandidateExternalIdsAreJsonObjectsInHttpAndConsole(): void
    {
        $this->expectNoVideoWrites();
        $this->read->expects(self::exactly(2))->method('findTarget')->with('tv', $this->series->getPublicId())->willReturn($this->identity);
        $this->providers->method('usableProviders')->willReturn([VideoProviderId::Local, VideoProviderId::Tmdb]);
        $this->providers->method('identify')->willReturn(new VideoTitle(new VideoCandidate('local-orbit', 'Orbit')));
        $this->providers->method('search')->willReturn([new VideoCandidate('42', 'Orbit', externalIds: ['imdb' => 'tt12345', 'tmdb' => '42'])]);

        $path = '/api/admin/video-matches/series/' . $this->publicId . '/candidates';
        $response = $this->authenticatedRequest('GET', $path, $this->createAdminUser());
        self::assertSame(200, $response->getStatusCode());
        $body = $response->getContent();
        self::assertIsString($body);

        $console = $this->command('search');
        self::assertSame(Command::SUCCESS, $console->execute([
            'kind' => 'series',
            'public-id' => $this->publicId,
            '--json' => true,
        ], ['capture_stderr_separately' => true]), $console->getErrorOutput());

        $httpData = json_decode($body, associative: false, flags: JSON_THROW_ON_ERROR)->data;
        $cliData = json_decode($console->getDisplay(), associative: false, flags: JSON_THROW_ON_ERROR);
        foreach ([$httpData, $cliData] as $data) {
            $localIds = $data->providers[0]->candidates[0]->externalIds;
            $remoteIds = $data->providers[1]->candidates[0]->externalIds;
            self::assertInstanceOf(\stdClass::class, $localIds);
            self::assertSame([], get_object_vars($localIds));
            self::assertInstanceOf(\stdClass::class, $remoteIds);
            self::assertSame(['imdb' => 'tt12345', 'tmdb' => '42'], get_object_vars($remoteIds));
        }
    }

    public function testExplicitRematchAndOfferedOrderingUseTheSameWriteInHttpAndConsole(): void
    {
        $this->read->expects(self::exactly(5))->method('findTarget')->with('tv', $this->series->getPublicId())->willReturn($this->identity);
        $this->providers->method('usableProviders')->willReturn([VideoProviderId::Tvdb]);
        $this->providers->method('identify')->willReturn(new VideoTitle(new VideoCandidate('42', 'Orbit'), orderings: [new VideoOrdering('aired', 'Aired', 'aired'), new VideoOrdering('regional', 'Regional', 'regional')]));
        $this->write->expects(self::exactly(2))->method('rematch')->with($this->identity, self::anything(), VideoProviderId::Tvdb, self::anything(), null)->willReturn(true);
        $this->write->expects(self::exactly(2))->method('setOrdering')->with($this->identity, self::anything(), 'regional', VideoProviderId::Tvdb, self::anything(), null)->willReturn(true);
        $admin = $this->createAdminUser();
        $data = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/admin/video-matches/series/' . $this->publicId . '/apply', $admin, ['provider' => 'tvdb', 'candidateId' => '42']), 200)['data'];
        self::assertSame('aired', $data['ordering']);
        self::assertSame($data, $this->console('apply', ['kind' => 'series', 'public-id' => $this->publicId, 'provider' => 'tvdb', 'candidate-id' => '42', '--force' => true]));
        $data = $this->assertJsonResponse($this->authenticatedRequest('PUT', '/api/admin/video-matches/series/' . $this->publicId . '/ordering', $admin, ['ordering' => 'regional']), 200)['data'];
        self::assertSame($data, $this->console('order', ['public-id' => $this->publicId, 'ordering' => 'regional', '--force' => true]));
        $this->assertJsonResponse($this->authenticatedRequest('PUT', '/api/admin/video-matches/series/' . $this->publicId . '/ordering', $admin, ['ordering' => 'absolute']), 422);
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/admin/video-matches/series/' . $this->publicId . '/apply', $admin, ['provider' => 'unknown', 'candidateId' => '42']), 422);
    }

    public function testUnavailableOrderingProviderKeepsCanonicalEpisodeAndFileChoicesWithHttpConsoleParity(): void
    {
        $this->expectNoVideoWrites();
        $episode = new PublicId()->toString();
        $file = new PublicId()->toString();
        $this->read->expects(self::exactly(2))->method('findTarget')->with('tv', $this->series->getPublicId())->willReturn($this->identity);
        $this->providers->method('identify')->willReturn(VideoProviderProblem::Unavailable);
        $this->read->expects(self::exactly(2))->method('episodeChoices')->with($this->identity, 2, 2)->willReturn([
            'items' => [['publicId' => $episode, 'seasonNumber' => 1, 'episodeNumber' => 3, 'title' => 'Third episode', 'absoluteNumber' => 3, 'airDate' => '2024-01-03']],
            'total' => 5,
        ]);
        $this->read->expects(self::exactly(2))->method('placementChoices')->with($this->identity, 2, 2)->willReturn([
            'items' => [['publicId' => $file, 'path' => 'Orbit/Third.mkv', 'assigned' => true, 'episodePublicIds' => [$episode], 'extraKind' => null]],
            'total' => 4,
        ]);
        $path = '/api/admin/video-matches/series/' . $this->publicId . '/candidates?view=choices&page=2&limit=2';
        $data = $this->assertJsonResponse($this->authenticatedRequest('GET', $path, $this->createAdminUser()), 200)['data'];
        self::assertSame('unavailable', $data['orderingProblem']);
        self::assertSame('aired', $data['currentOrdering']);
        self::assertSame('tvdb', $data['provider']);
        self::assertSame([], $data['orderings']);
        self::assertSame($episode, $data['episodes']['items'][0]['publicId']);
        self::assertSame([$episode], $data['files']['items'][0]['episodePublicIds']);
        self::assertSame($file, $data['files']['items'][0]['publicId']);
        self::assertTrue($data['episodes']['hasNextPage']);
        self::assertFalse($data['files']['hasNextPage']);
        self::assertSame($data, $this->console('search', ['kind' => 'series', 'public-id' => $this->publicId, '--view' => 'choices', '--page' => '2', '--limit' => '2']));
    }

    public function testOrderingChoicesKeepNativeIdsAndLabelsFromTheMatchedProvider(): void
    {
        $this->expectNoVideoWrites();
        $this->read->expects(self::exactly(2))->method('findTarget')->with('tv', $this->series->getPublicId())->willReturn($this->identity);
        $this->read->expects(self::never())->method('episodeChoices');
        $this->read->expects(self::never())->method('placementChoices');
        $this->providers->method('identify')->willReturnCallback(static function (VideoProviderId $provider, VideoRequest $request): VideoTitle {
            self::assertSame(VideoProviderId::Tvdb, $provider);
            self::assertSame(['tvdb' => '42'], $request->externalIds);
            self::assertSame('aired', $request->ordering);
            return new VideoTitle(new VideoCandidate('42', 'Orbit'), orderings: [new VideoOrdering('aired', 'Original broadcast', 'aired'), new VideoOrdering('group-dvd-1', 'DVD release', 'dvd'), new VideoOrdering('group-dvd-2', 'Collector release', 'dvd')]);
        });
        $path = '/api/admin/video-matches/series/' . $this->publicId . '/candidates?view=choices&collection=orderings';
        $data = $this->assertJsonResponse($this->authenticatedRequest('GET', $path, $this->createAdminUser()), 200)['data'];
        self::assertSame(['aired', 'group-dvd-1', 'group-dvd-2'], array_column($data['orderings'], 'id'));
        self::assertSame(['Original broadcast', 'DVD release', 'Collector release'], array_column($data['orderings'], 'label'));
        self::assertNull($data['episodes']);
        self::assertNull($data['files']);
        self::assertNull($data['orderingProblem']);
        self::assertSame($data, $this->console('search', ['kind' => 'series', 'public-id' => $this->publicId, '--view' => 'choices', '--collection' => 'orderings']));
    }

    public function testFileChoicePaginationNeverFetchesProviderStructure(): void
    {
        $this->expectNoVideoWrites();
        $this->read->expects(self::exactly(2))->method('findTarget')->with('tv', $this->series->getPublicId())->willReturn($this->identity);
        $this->read->expects(self::never())->method('episodeChoices');
        $this->providers->method('identify')->willReturnCallback(static fn () => self::fail('Browsing files must not request provider metadata.'));
        $this->providers->method('search')->willReturnCallback(static fn () => self::fail('Browsing files must not search providers.'));
        $this->read->expects(self::exactly(2))->method('placementChoices')->with($this->identity, 3, 1)->willReturn(['items' => [], 'total' => 2]);
        $path = '/api/admin/video-matches/series/' . $this->publicId . '/candidates?view=choices&collection=files&page=3&limit=1';
        $data = $this->assertJsonResponse($this->authenticatedRequest('GET', $path, $this->createAdminUser()), 200)['data'];
        self::assertSame(['items' => [], 'total' => 2, 'page' => 3, 'perPage' => 1, 'hasNextPage' => false], $data['files']);
        self::assertSame($data, $this->console('search', ['kind' => 'series', 'public-id' => $this->publicId, '--view' => 'choices', '--collection' => 'files', '--page' => '3', '--limit' => '1']));
    }

    public function testMissingChoiceTargetHasHttpConsoleParityWithoutProviderCalls(): void
    {
        $this->expectNoVideoWrites();
        $missing = new PublicId();
        $this->read->expects(self::exactly(2))->method('findTarget')->with('tv', $missing)->willReturn(null);
        $this->read->expects(self::never())->method('episodeChoices');
        $this->read->expects(self::never())->method('placementChoices');
        $this->providers->method('identify')->willReturnCallback(static fn () => self::fail('A missing target must not load provider metadata.'));
        $path = '/api/admin/video-matches/series/' . $missing->toString() . '/candidates?view=choices';
        $this->assertJsonResponse($this->authenticatedRequest('GET', $path, $this->createAdminUser()), 404);
        $tester = $this->command('search');
        self::assertSame(Command::FAILURE, $tester->execute(['kind' => 'series', 'public-id' => $missing->toString(), '--view' => 'choices', '--json' => true], ['capture_stderr_separately' => true]));
        self::assertSame('', $tester->getDisplay());
    }

    public function testWrongProviderEntryIsRefusedWhileLocalChoicesStayAvailable(): void
    {
        $this->expectNoVideoWrites();
        $this->read->expects(self::exactly(2))->method('findTarget')->with('tv', $this->series->getPublicId())->willReturn($this->identity);
        $this->providers->method('identify')->willReturn(new VideoTitle(new VideoCandidate('999', 'Wrong show'), orderings: [new VideoOrdering('wrong-group', 'Wrong order', 'dvd')]));
        $this->read->method('episodeChoices')->willReturn(['items' => [], 'total' => 0]);
        $this->read->method('placementChoices')->willReturn(['items' => [], 'total' => 0]);
        $path = '/api/admin/video-matches/series/' . $this->publicId . '/candidates?view=choices';
        $data = $this->assertJsonResponse($this->authenticatedRequest('GET', $path, $this->createAdminUser()), 200)['data'];
        self::assertSame('refused', $data['orderingProblem']);
        self::assertSame([], $data['orderings']);
        self::assertSame(0, $data['episodes']['total']);
        self::assertSame(0, $data['files']['total']);
        self::assertSame($data, $this->console('search', ['kind' => 'series', 'public-id' => $this->publicId, '--view' => 'choices']));
    }

    public function testUnmatchedTitleReturnsLocalChoicesWithoutIdentifyingAProvider(): void
    {
        $this->expectNoVideoWrites();
        $identity = new VideoMatchTargetIdentity($this->series->getId(), VideoKind::Series, $this->publicId, $this->series->getLibraryId(), false, null, null, 'aired');
        $this->read->expects(self::exactly(2))->method('findTarget')->with('tv', $this->series->getPublicId())->willReturn($identity);
        $this->read->expects(self::exactly(2))->method('episodeChoices')->with($identity, 1, 50)->willReturn(['items' => [], 'total' => 0]);
        $this->read->expects(self::exactly(2))->method('placementChoices')->with($identity, 1, 50)->willReturn(['items' => [], 'total' => 0]);
        $this->providers->method('identify')->willReturnCallback(static fn () => self::fail('An unmatched title must not identify a provider.'));
        $path = '/api/admin/video-matches/series/' . $this->publicId . '/candidates?view=choices';
        $data = $this->assertJsonResponse($this->authenticatedRequest('GET', $path, $this->createAdminUser()), 200)['data'];
        self::assertNull($data['provider']);
        self::assertSame('unmatched', $data['orderingProblem']);
        self::assertSame([], $data['orderings']);
        self::assertSame($data, $this->console('search', ['kind' => 'series', 'public-id' => $this->publicId, '--view' => 'choices']));
    }

    public function testInvalidChoiceOptionsHaveHttpConsoleParityBeforeReading(): void
    {
        $this->expectNoVideoWrites();
        $this->read->expects(self::never())->method('findTarget');
        $admin = $this->createAdminUser();
        foreach ([['view', 'unknown'], ['collection', 'unknown'], ['page', '0'], ['limit', '101']] as [$option, $value]) {
            $query = ['view' => 'choices', $option => $value];
            $path = '/api/admin/video-matches/series/' . $this->publicId . '/candidates?' . http_build_query($query);
            $this->assertJsonResponse($this->authenticatedRequest('GET', $path, $admin), 422);
            $tester = $this->command('search');
            self::assertSame(Command::INVALID, $tester->execute(['kind' => 'series', 'public-id' => $this->publicId, '--view' => 'choices', '--' . $option => $value, '--json' => true], ['capture_stderr_separately' => true]));
            self::assertSame('', $tester->getDisplay());
        }
    }

    public function testPublicIdAndRequestValidationCannotReachTheWriter(): void
    {
        $this->read->expects(self::never())->method('findTarget');
        $this->write->expects(self::never())->method('rematch');
        $this->write->expects(self::never())->method('setOrdering');
        $this->write->expects(self::never())->method('assignFile');
        $admin = $this->createAdminUser();
        foreach ([
            ['GET', '/series/not-a-public-id/candidates', []],
            ['GET', '/series/not-a-public-id/candidates?view=choices', []],
            ['GET', '/episode/' . $this->publicId . '/candidates?view=choices', []],
            ['POST', '/series/' . $this->publicId . '/apply', ['provider' => 'tvdb', 'candidateId' => '']],
            ['PUT', '/series/' . $this->publicId . '/ordering', ['ordering' => '']],
            ['PUT', '/files/' . $this->publicId . '/assignment', ['episodePublicIds' => 'one']],
            ['POST', '/libraries/' . $this->libraryPublicId . '/rematch', ['confirmed' => 'wrong']],
        ] as [$method, $path, $payload]) {
            $this->assertJsonResponse($this->authenticatedRequest($method, '/api/admin/video-matches' . $path, $admin, $payload), 422);
        }
    }

    public function testEpisodeAndExtraAssignmentsHaveIdenticalHttpAndConsolePublicIds(): void
    {
        $episode = TvEpisode::create($this->series->getId(), Uuid::v7(), 1, 'Pilot');
        $placement = VideoPlacement::create($this->series->getLibraryId(), Uuid::v7(), 'Orbit/Unknown.mkv', [], seriesId: $this->series->getId());
        $id = $placement->getPublicId()->toString();
        $episodeId = $episode->getPublicId()->toString();
        $this->read->expects(self::exactly(4))->method('findPlacement')->with($placement->getPublicId())->willReturn($placement);
        $this->read->expects(self::exactly(2))->method('findEpisode')->with($episode->getPublicId())->willReturn($episode);
        $this->write->expects(self::exactly(4))->method('assignFile')->willReturnCallback(static function (VideoPlacement $actual, array $episodes, ?ExtraKind $extra) use ($placement, $episode): bool {
            self::assertSame($placement, $actual);
            if ($extra === null) {
                self::assertEquals([$episode->getId()], $episodes);
            } else {
                self::assertSame(ExtraKind::Trailer, $extra);
                self::assertSame([], $episodes);
            }
            return true;
        });
        $admin = $this->createAdminUser();
        $path = '/api/admin/video-matches/files/' . $id . '/assignment';
        $data = $this->assertJsonResponse($this->authenticatedRequest('PUT', $path, $admin, ['episodePublicIds' => [$episodeId]]), 200)['data'];
        self::assertSame(['publicId' => $id, 'episodes' => [$episodeId], 'extraKind' => null], $data);
        self::assertSame($data, $this->console('assign', ['public-id' => $id, '--episodes' => $episodeId, '--force' => true]));
        $data = $this->assertJsonResponse($this->authenticatedRequest('PUT', $path, $admin, ['extraKind' => 'trailer']), 200)['data'];
        self::assertSame(['publicId' => $id, 'episodes' => [], 'extraKind' => 'trailer'], $data);
        self::assertSame($data, $this->console('assign', ['public-id' => $id, '--extra-kind' => 'trailer', '--force' => true]));
        $this->assertJsonResponse($this->authenticatedRequest('PUT', $path, $admin, ['episodePublicIds' => [$episodeId], 'extraKind' => 'trailer']), 422);
        $this->assertJsonResponse($this->authenticatedRequest('PUT', $path, $admin, ['episodePublicIds' => [123]]), 422);
        $invalidList = $this->assertJsonResponse($this->authenticatedRequest('PUT', $path, $admin, ['episodePublicIds' => ['first' => $episodeId]]), 422);
        self::assertSame('Episodes must be a list of public IDs.', $invalidList['error']['message']);
        $this->assertJsonResponse($this->authenticatedRequest('PUT', $path, $admin, []), 400);
    }

    public function testLibraryPreviewAndConfirmedForceHaveHttpAndConsoleParity(): void
    {
        $this->read->method('previewLibrary')->willReturn(new VideoMatchLibraryPreview(3, 3, 12, $this->libraryPublicId));
        $titles = [$this->identity];
        for ($index = 0; $index < 2; ++$index) {
            $titles[] = new VideoMatchTargetIdentity(Uuid::v7(), VideoKind::Series, new PublicId()->toString(), $this->series->getLibraryId(), true, 'tvdb', (string) ($index + 43), 'aired');
        }
        $this->read->expects(self::exactly(4))->method('targetsByLibrary')->willReturn($titles);
        $forces = [];
        $this->write->expects(self::exactly(12))->method('requestLibraryTitle')->willReturnCallback(static function (VideoMatchTargetIdentity $target, bool $force) use (&$forces): bool {
            $forces[] = $force;
            return !$target->handMatched || $force;
        });
        $admin = $this->createAdminUser();
        $path = '/api/admin/video-matches/libraries/' . $this->libraryPublicId;
        $preview = $this->assertJsonResponse($this->authenticatedRequest('GET', $path . '/rematch-preview', $admin), 200)['data'];
        self::assertSame(12, $preview['handAssignedFiles']);
        self::assertSame($preview, $this->console('rematch', ['library' => $this->libraryPublicId]));
        $unconfirmed = $this->assertJsonResponse($this->authenticatedRequest('POST', $path . '/rematch', $admin, ['force' => true]), 200)['data'];
        self::assertSame(0, $unconfirmed['queued']);
        self::assertSame([], $forces);
        foreach ([false, true] as $force) {
            $data = $this->assertJsonResponse($this->authenticatedRequest('POST', $path . '/rematch', $admin, ['confirmed' => true, 'force' => $force]), 200)['data'];
            self::assertSame($force ? 3 : 0, $data['queued']);
            self::assertSame($force ? 0 : 3, $data['skipped']);
            $options = ['library' => $this->libraryPublicId, '--apply' => true, '--force' => true];
            if ($force) {
                $options['--include-hand-matched'] = true;
            }
            self::assertSame($data, $this->console('rematch', $options));
        }
        self::assertSame([false, false, false, false, false, false, true, true, true, true, true, true], $forces);
    }

    public function testLibraryApplyRequiresConfirmationEvenWithJson(): void
    {
        $this->read->expects(self::once())->method('previewLibrary')->willReturn(new VideoMatchLibraryPreview(10, 3, 12, $this->libraryPublicId));
        $this->write->expects(self::never())->method('requestLibraryTitle');
        $tester = $this->command('rematch');
        self::assertSame(Command::INVALID, $tester->execute(['library' => $this->libraryPublicId, '--apply' => true, '--json' => true], ['interactive' => false, 'capture_stderr_separately' => true]));
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('3 manually matched titles and 12 manually assigned files', $tester->getErrorOutput());
        self::assertStringContainsString('--force', $tester->getErrorOutput());
    }

    private function expectNoVideoWrites(): void
    {
        $this->write->expects(self::never())->method('rematch');
        $this->write->expects(self::never())->method('setOrdering');
        $this->write->expects(self::never())->method('assignFile');
        $this->write->expects(self::never())->method('requestLibraryTitle');
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function console(string $action, array $input): array
    {
        $tester = $this->command($action);
        self::assertSame(Command::SUCCESS, $tester->execute($input + ['--json' => true], ['capture_stderr_separately' => true]), $tester->getErrorOutput());
        return json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function command(string $action): CommandTester
    {
        $application = new Application($this->client->getKernel());
        return new CommandTester($application->find('app:video:match:' . $action));
    }
}
