<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Activity\Application\Command\RecordVideoProgressCommand;
use App\Activity\Application\Command\SetWatchedCommand;
use App\Activity\Application\Command\VideoProgressAction;
use App\Activity\Application\CommandHandler\RecordVideoProgressHandler;
use App\Activity\Application\CommandHandler\SetWatchedHandler;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Catalog\Application\Message\DrainVideoMatchesMessage;
use App\Catalog\Application\MessageHandler\DrainVideoMatchesHandler;
use App\Catalog\Application\Port\MemberVideoCatalogReadInterface;
use App\Catalog\Application\Port\VideoMatchAdminWriteInterface;
use App\Catalog\Application\Port\VideoMatchTargetLoaderInterface;
use App\Catalog\Application\Query\VideoMatch\VideoMatchReviewReadInterface;
use App\Catalog\Application\Service\VideoMatch\LibraryProviderOrderService;
use App\Catalog\Application\Service\VideoMatch\VideoMatchAdminService;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Model\VideoPlacement;
use App\Catalog\Domain\Repository\TvCatalogRepositoryInterface;
use App\Catalog\Domain\Repository\VideoMatchStateRepositoryInterface;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Application\TvScanner;
use App\Metadata\Application\DTO\Video\VideoCandidate;
use App\Metadata\Application\DTO\Video\VideoEpisode;
use App\Metadata\Application\DTO\Video\VideoOrdering;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Metadata\Application\DTO\Video\VideoSeason;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Metadata\Application\Port\VideoMetadataPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Tests\Fixtures\Catalog\TvIdentityRace;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

/** @phpstan-import-type EpisodeRow from MemberVideoCatalogReadInterface */
#[SkipDatabaseRollback]
final class VideoLibraryAcceptanceTest extends TestCase
{
    private TvIdentityRace $fixture;
    private ContainerInterface $services;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }
        $this->fixture = new TvIdentityRace();
        $services = $this->fixture->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $services);
        $this->services = $services;
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            new Filesystem()->remove($this->fixture->root . '-absent');
            $this->fixture->close();
        }
        parent::tearDown();
    }

    public function testChangingOrderingKeepsWatchedFilesAndTheUnfinishedFilesResume(): void
    {
        $this->addEpisodes('Anime', 1, 6);
        $series = $this->series('Anime');
        $aired = self::metadata('Anime', '100', 6);
        $this->match($series, $aired);
        $member = $this->member();
        $before = $this->episodes($series, $member);
        self::assertSame(range(1, 6), array_column($before, 'episodeNumber'));

        $mark = $this->service(SetWatchedHandler::class);
        foreach (array_slice($before, 0, 5) as $episode) {
            self::assertSame(1, $mark(new SetWatchedCommand($member, 'episode', new PublicId($episode['publicId']), true))->titles);
        }
        $this->pause($member, $before[5]);
        $before = $this->episodes($series, $member);
        self::assertSame([true, true, true, true, true, false], array_column($before, 'watched'));
        self::assertSame(0.2, $before[5]['videos'][0]['positionSeconds']);

        $absolute = self::metadata('Anime', '100', 6, firstNumber: 11);
        $fresh = $this->series('Anime');
        $identity = $this->service(VideoMatchReviewReadInterface::class)->findTarget('tv', $fresh->getPublicId());
        self::assertNotNull($identity);
        $target = $this->service(VideoMatchTargetLoaderInterface::class)->forTitle($fresh);
        self::assertTrue($this->service(VideoMatchAdminWriteInterface::class)
            ->setOrdering($identity, $target, 'absolute', VideoProviderId::Tvdb, $absolute));
        self::assertSame('absolute', $this->series('Anime')->getOrdering());

        $after = $this->episodes($series, $member);
        self::assertSame(range(11, 16), array_column($after, 'episodeNumber'));
        self::assertSame(range(1, 6), array_column($after, 'absoluteNumber'));
        self::assertSame(array_column($before, 'defaultVideoPublicId'), array_column($after, 'defaultVideoPublicId'));
        self::assertSame([true, true, true, true, true, false], array_column($after, 'watched'));
        self::assertSame(0.2, $after[5]['videos'][0]['positionSeconds']);
    }

    public function testOrderChangeOnlyAffectsNewTitlesUntilTheLibraryIsRematched(): void
    {
        $providers = $this->createMock(VideoMetadataPortInterface::class);
        $providers->method('providers')->willReturn([VideoProviderId::Tvdb, VideoProviderId::Tmdb, VideoProviderId::Local]);
        $providers->method('usableProviders')->willReturn([VideoProviderId::Tvdb, VideoProviderId::Tmdb]);
        $providers->expects(self::never())->method('search');
        $providers->method('identify')->willReturnCallback(static function (VideoProviderId $provider, VideoRequest $request): ?VideoTitle {
            $tmdb = $request->externalIds['tmdb'] ?? null;
            if ($tmdb === null) {
                return null;
            }
            $tvdb = match ($tmdb) {
                '101' => '901',
                '103' => '903',
                default => null,
            };
            $id = $provider === VideoProviderId::Tvdb ? $tvdb : $tmdb;

            return $id === null ? null : self::metadata($request->title, $id, 1, externalIds: ['tmdb' => $tmdb]);
        });
        $this->services->set(VideoMetadataPortInterface::class, $providers);
        $orders = $this->service(LibraryProviderOrderService::class);
        $library = $this->fixture->library->getPublicId()->toString();
        $orders->setByIdentifier($library, ['tmdb', 'tvdb', 'local']);
        $this->addEpisodes('Existing {tmdb-101}', 1, 1);
        $this->addEpisodes('No counterpart {tmdb-102}', 1, 1);
        $this->drain(2);
        $this->assertMatch('Existing {tmdb-101}', 'tmdb', '101');
        $this->assertMatch('No counterpart {tmdb-102}', 'tmdb', '102');

        $orders->setByIdentifier($library, ['tvdb', 'tmdb', 'local']);
        $this->addEpisodes('New {tmdb-103}', 1, 1);
        $this->drain(1);
        $this->assertMatch('Existing {tmdb-101}', 'tmdb', '101');
        $this->assertMatch('New {tmdb-103}', 'tvdb', '903');

        $result = $this->service(VideoMatchAdminService::class)->rematchLibrary($library, confirmed: true);
        self::assertSame(3, $result['queued']);
        $this->drain(3);
        $this->assertMatch('Existing {tmdb-101}', 'tvdb', '901');
        $this->assertMatch('No counterpart {tmdb-102}', 'tmdb', '102');
        $this->assertMatch('New {tmdb-103}', 'tvdb', '903');
    }

    public function testFilesAddedAfterASeasonMarkStartUnwatched(): void
    {
        $this->addEpisodes('Airing', 1, 8);
        $series = $this->series('Airing');
        $this->match($series, self::metadata('Airing', '200', 10));
        $member = $this->member();
        $season = $this->catalog()->findSeason($series->getId(), 1);
        self::assertNotNull($season);
        $detail = $this->reads()->seriesDetail($series->getPublicId(), $this->scope(), $member);
        self::assertNotNull($detail);
        self::assertSame(8, $detail['seasons'][0]['ownedCount']);
        self::assertSame(10, $detail['seasons'][0]['totalCount']);
        $result = ($this->service(SetWatchedHandler::class))(
            new SetWatchedCommand($member, 'season', $season->getPublicId(), true),
        );
        self::assertSame(8, $result->titles);

        $this->addEpisodes('Airing', 9, 10);
        $episodes = $this->episodes($series, $member);
        self::assertSame(range(1, 10), array_column($episodes, 'episodeNumber'));
        self::assertSame([...array_fill(0, 8, true), false, false], array_column($episodes, 'watched'));
    }

    public function testScanRestoresTheMatchedSeriesWithAssignmentsAndMemberStateAfterTheRootReturns(): void
    {
        $this->addEpisodes('Returning', 2, 2);
        $this->fixture->ingest($this->fixture->video('Returning/Season 01/finale.mp4'));
        $series = $this->series('Returning');
        $this->match($series, self::metadata('Returning', '300', 2));
        $member = $this->member();
        $placement = array_find(
            $this->catalog()->placementsBySeries($series->getId()),
            static fn (VideoPlacement $file): bool => $file->getRelativePath() === 'Returning/Season 01/finale.mp4',
        );
        self::assertNotNull($placement);
        $assignedEpisode = $this->catalog()->findEpisodeByNumber($series->getId(), 1, 1);
        self::assertNotNull($assignedEpisode);
        $this->service(VideoMatchAdminService::class)
            ->assignFile($placement->getPublicId()->toString(), [$assignedEpisode->getPublicId()->toString()]);
        $episodes = $this->episodes($series, $member);
        self::assertSame([1, 2], array_column($episodes, 'episodeNumber'));
        ($this->service(SetWatchedHandler::class))(
            new SetWatchedCommand($member, 'episode', new PublicId($episodes[0]['publicId']), true),
        );
        $this->pause($member, $episodes[1]);
        $before = $this->episodes($series, $member);
        self::assertSame([true, false], array_column($before, 'watched'));
        self::assertSame(0.2, $before[1]['videos'][0]['positionSeconds']);

        $root = $this->fixture->root;
        new Filesystem()->rename($root, $root . '-absent');
        $this->scan(0);
        self::assertSame(0, $this->reads()->series(SearchOptions::create(''), $this->scope())->getTotal());
        self::assertNull($this->reads()->seriesDetail($series->getPublicId(), $this->scope(), $member));

        new Filesystem()->rename($root . '-absent', $root);
        $this->scan(2);
        self::assertSame(1, $this->reads()->series(SearchOptions::create(''), $this->scope())->getTotal());
        $this->assertMatch('Returning', 'tvdb', '300');
        self::assertSame($before, $this->episodes($series, $member));
        $restored = $this->catalog()->findPlacement($this->fixture->library->getId(), $placement->getVideoId());
        self::assertNotNull($restored);
        self::assertSame($placement->getPublicId()->toString(), $restored->getPublicId()->toString());
        self::assertTrue($restored->isAssigned());
        self::assertEquals([$assignedEpisode->getId()], $restored->getEpisodeIds());
    }

    private function addEpisodes(string $folder, int $from, int $through): void
    {
        for ($number = $from; $number <= $through; ++$number) {
            $file = $this->fixture->video(sprintf('%s/Season 01/Show.S01E%02d.mp4', $folder, $number));
            $this->fixture->ingest($file);
        }
    }

    private function scan(int $expectedFiles): void
    {
        $library = $this->fixture->library;
        $result = $this->service(TvScanner::class)->scan($library, rescan: true);
        self::assertSame($expectedFiles, $result->filesDiscovered);
        $handler = $this->service(FilesDiscoveredHandler::class);
        foreach ($result->directories as $directory => $files) {
            $handler(new FilesDiscovered($library->getId(), $library->getType()->value, $directory, $files));
        }
    }

    /** @param array<string, string> $externalIds */
    private static function metadata(string $title, string $id, int $count, int $firstNumber = 1, array $externalIds = []): VideoTitle
    {
        $episodes = [];
        for ($index = 1; $index <= $count; ++$index) {
            $episodes[] = new VideoEpisode($id . '-' . $index, $firstNumber + $index - 1, 'Episode ' . $index, absoluteNumber: $index);
        }

        return new VideoTitle(
            new VideoCandidate($id, $title, externalIds: $externalIds),
            orderings: [new VideoOrdering('aired', 'Aired', 'aired'), new VideoOrdering('absolute', 'Absolute', 'absolute')],
            seasons: [new VideoSeason(1, episodes: $episodes)],
        );
    }

    private function match(TvSeries $series, VideoTitle $metadata): void
    {
        $identity = $this->service(VideoMatchReviewReadInterface::class)->findTarget('tv', $series->getPublicId());
        self::assertNotNull($identity);
        $target = $this->service(VideoMatchTargetLoaderInterface::class)->forTitle($series);
        self::assertTrue($this->service(VideoMatchAdminWriteInterface::class)->rematch($identity, $target, VideoProviderId::Tvdb, $metadata));
    }

    private function drain(int $titles): void
    {
        $drain = $this->service(DrainVideoMatchesHandler::class);
        for ($index = 0; $index < $titles; ++$index) {
            $drain(new DrainVideoMatchesMessage());
        }
    }

    private function assertMatch(string $folder, string $provider, string $id): void
    {
        $series = $this->series($folder);
        self::assertSame($provider, $series->getMatchedProvider());
        self::assertSame($id, $series->getProviderId());
        $state = $this->service(VideoMatchStateRepositoryInterface::class)->findBySeries($series->getId());
        self::assertSame('matched', $state?->status);
    }

    private function member(): Uuid
    {
        $member = new UserEntity(new PublicId(), 'Viewer', 'video-acceptance@baander.app', 'test-only');
        $this->fixture->manager->persist($member);
        $this->fixture->manager->flush();
        $this->service(LibraryAccessPortInterface::class)->grant($member->getId(), $this->fixture->library->getId());

        return $member->getId();
    }

    /** @param EpisodeRow $episode */
    private function pause(Uuid $member, array $episode): void
    {
        $video = $episode['defaultVideoPublicId'];
        self::assertNotNull($video);
        $result = ($this->service(RecordVideoProgressHandler::class))(new RecordVideoProgressCommand(
            $member,
            new PublicId($video),
            'episode',
            new PublicId($episode['publicId']),
            0.2,
            VideoProgressAction::Pause,
            new DateTimeImmutable(),
        ));
        self::assertTrue($result->recorded);
    }

    /** @return list<EpisodeRow> */
    private function episodes(TvSeries $series, Uuid $member): array
    {
        $page = $this->reads()->episodes($series->getPublicId(), SearchOptions::create(''), $this->scope(), $member);
        self::assertNotNull($page);

        /** @var list<EpisodeRow> $episodes */
        $episodes = $page->getItems();

        return $episodes;
    }

    private function series(string $folder): TvSeries
    {
        $series = $this->catalog()->findSeriesByFolder($this->fixture->library->getId(), $folder);
        self::assertNotNull($series);

        return $series;
    }

    private function scope(): LibraryReadScope
    {
        return LibraryReadScope::restricted([$this->fixture->library->getId()]);
    }

    /**
     * @template Service of object
     * @param class-string<Service> $class
     * @return Service
     */
    private function service(string $class): object
    {
        $service = $this->services->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    private function reads(): MemberVideoCatalogReadInterface
    {
        return $this->service(MemberVideoCatalogReadInterface::class);
    }

    private function catalog(): TvCatalogRepositoryInterface
    {
        return $this->service(TvCatalogRepositoryInterface::class);
    }
}
