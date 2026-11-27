<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Domain\Repository\TvCatalogRepositoryInterface;
use App\Catalog\Domain\ValueObject\VideoName\ExtraKind;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\TvIdentityRace;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[SkipDatabaseRollback]
final class TvLibraryIngestTest extends TestCase
{
    private TvIdentityRace $fixture;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }
        $this->fixture = new TvIdentityRace();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->close();
        }
        parent::tearDown();
    }

    public function testASeasonAndSpecialsImportIntoOneSeries(): void
    {
        $episode = $this->fixture->video('Show (2011)/Season 01/Show.S01E02.mp4');
        $special = $this->fixture->video('Show (2011)/Specials/Show.S00E01.mp4');
        $this->fixture->ingest($episode);
        $this->fixture->ingest($special);

        self::assertSame([['name' => 'Show', 'year' => 2011]], $this->fixture->connection->fetchAllAssociative('SELECT name, year FROM tv_series'));
        self::assertSame([0, 1], $this->fixture->connection->fetchFirstColumn('SELECT season_number FROM tv_seasons ORDER BY season_number'));
        self::assertSame(2, $this->rowCount('tv_episodes'));
        self::assertSame(2, $this->rowCount('video_placements'));
    }

    public function testATwoEpisodeSpanLinksOneVideoToBothEpisodes(): void
    {
        $this->fixture->ingest($this->fixture->video('Show/Season 02/Show.S02E01-E02.mp4'));

        self::assertSame(1, $this->rowCount('videos'));
        self::assertSame(1, $this->rowCount('video_placements'));
        self::assertSame([1, 2], $this->fixture->connection->fetchFirstColumn('SELECT episode_number FROM tv_episodes ORDER BY episode_number'));
        self::assertSame(2, $this->rowCount('video_placement_episode'));
    }

    public function testTwoFilesParsedWithTheSameEpisodeNumberBecomeVersions(): void
    {
        $first = $this->fixture->video('Show/Season 01/Show.S01E05.mp4');
        $second = $this->fixture->video('Show/Season 01/Show.S01E05.1080p.mp4');
        $this->fixture->ingest($first, $second);

        self::assertSame(1, $this->rowCount('tv_episodes'));
        self::assertSame(2, $this->rowCount('videos'));
        self::assertSame(2, $this->rowCount('video_placements'));
        self::assertSame(1, (int) $this->fixture->connection->fetchOne('SELECT count(DISTINCT episode_id) FROM video_placement_episode'));
    }

    public function testANumberedUnmatchedFileIsPlayableAndAnUnnumberedFileNeedsReview(): void
    {
        $numbered = $this->fixture->video('Show/Season 01/Show.S01E03.mp4');
        $unnumbered = $this->fixture->video('Show/Season 01/finale.mp4');
        $this->fixture->ingest($numbered, $unnumbered);

        self::assertSame(1, $this->rowCount('tv_episodes'));
        self::assertSame([3], $this->fixture->connection->fetchFirstColumn('SELECT episode_number FROM tv_episodes'));
        self::assertSame(1, $this->rowCount('video_placement_episode'));
        $row = $this->placement('Show/Season 01/finale.mp4');
        self::assertNotNull($row['review_reason']);
        self::assertSame(0, (int) $this->fixture->connection->fetchOne(
            'SELECT count(*) FROM video_placement_episode WHERE placement_id = ?',
            [$row['id']],
        ));
    }

    public function testAbsoluteAndDateNumberedFilesRemainVisibleBeforeAMatch(): void
    {
        $absolute = $this->fixture->video('Anime/Anime - 137.mp4');
        $daily = $this->fixture->video('Daily Show/Daily Show 2024-03-14.mp4');
        $this->fixture->ingest($absolute);
        $this->fixture->ingest($daily);

        self::assertSame(2, $this->rowCount('tv_series'));
        self::assertSame(2, $this->rowCount('tv_episodes'));
        self::assertSame(2, $this->rowCount('video_placement_episode'));
        self::assertSame(137, (int) $this->fixture->connection->fetchOne('SELECT absolute_number FROM tv_episodes WHERE absolute_number IS NOT NULL'));
        self::assertSame('2024-03-14', $this->fixture->connection->fetchOne("SELECT air_date::text FROM tv_episodes WHERE air_date IS NOT NULL"));
    }

    public function testExtrasAttachToTheSeriesAndSamplesCreateNoCatalogRows(): void
    {
        $extra = $this->fixture->video('Show/Extras/Interview.mp4');
        $sample = $this->fixture->video('Show/Season 01/Show.S01E01.sample.mp4');
        $this->fixture->ingest($extra);
        $this->fixture->ingest($sample);

        self::assertSame(1, $this->rowCount('tv_series'));
        self::assertSame(1, $this->rowCount('videos'));
        self::assertSame(0, $this->rowCount('tv_episodes'));
        self::assertSame(0, $this->rowCount('video_placement_episode'));
        self::assertSame(ExtraKind::Other->value, $this->placement('Show/Extras/Interview.mp4')['extra_kind']);
    }

    public function testARenamedEpisodeKeepsItsPlacementAndEpisodePublicIds(): void
    {
        $original = $this->fixture->video('Show/Season 01/Show.S01E05.mp4');
        $this->fixture->ingest($original);
        $placement = $this->placement($original->relativePath);
        $episodeId = $this->fixture->connection->fetchOne('SELECT public_id FROM tv_episodes');
        $renamed = 'Show/Season 01/Show.S01E05.Remastered.mp4';
        rename($original->absolutePath, $this->fixture->root . '/' . $renamed);

        $this->fixture->ingest($this->fixture->observe($renamed));

        self::assertSame(1, $this->rowCount('video_placements'));
        self::assertSame($placement['public_id'], $this->placement($renamed)['public_id']);
        self::assertSame($episodeId, $this->fixture->connection->fetchOne('SELECT public_id FROM tv_episodes'));
        self::assertFalse($this->fixture->connection->fetchOne(
            'SELECT available FROM library_file_index WHERE path = ?',
            [$original->absolutePath],
        ));
    }

    public function testAFolderRenameKeepsTheMatchAndNewFilesJoinTheSameSeries(): void
    {
        $original = $this->fixture->video('Show/Season 01/Show.S01E05.mp4');
        $this->fixture->ingest($original);
        $repository = $this->repository();
        $series = $repository->findSeriesByFolder($this->fixture->library->getId(), 'Show');
        self::assertNotNull($series);
        $series->match('tvdb', '121361', ['tvdb' => '121361'], true);
        $series->setOrdering('dvd');
        $repository->saveSeries($series);
        $repository->flush();
        (new Filesystem())->rename($this->fixture->root . '/Show', $this->fixture->root . '/Show (2011)');
        $renamed = $this->fixture->observe('Show (2011)/Season 01/Show.S01E05.mp4');
        $this->fixture->ingest($renamed);
        $this->fixture->ingest($this->fixture->video('Show (2011)/Season 01/Show.S01E07.mp4'));

        self::assertSame(1, $this->rowCount('tv_series'));
        $stored = $repository->findSeriesByFolder($this->fixture->library->getId(), 'Show (2011)');
        self::assertNotNull($stored);
        self::assertTrue($stored->getPublicId()->equals($series->getPublicId()));
        self::assertSame('tvdb', $stored->getMatchedProvider());
        self::assertSame('121361', $stored->getProviderId());
        self::assertSame('dvd', $stored->getOrdering());
        self::assertSame(2, $this->rowCount('tv_episodes'));
    }

    public function testAReplacementAtTheSamePathKeepsAnAdminEpisodeAssignment(): void
    {
        $original = $this->fixture->video('Show/Season 01/Show.S01E05.mp4');
        $this->fixture->ingest($original);
        $placement = $this->placement($original->relativePath);
        $repository = $this->repository();
        $stored = $repository->findPlacement($this->fixture->library->getId(), Uuid::fromString($placement['video_id']));
        self::assertNotNull($stored);
        $stored->assignEpisodes($stored->getEpisodeIds(), true);
        $repository->savePlacement($stored);
        $repository->flush();

        $replacement = $this->fixture->video($original->relativePath, 'blue');
        self::assertNotSame($original->hash, $replacement->hash);
        $this->fixture->ingest($replacement);

        $newPlacement = $this->fixture->connection->fetchAssociative(
            'SELECT p.id, p.assigned FROM video_placements p JOIN videos v ON v.id = p.video_id WHERE v.hash = ?',
            [$replacement->hash],
        );
        self::assertIsArray($newPlacement);
        self::assertTrue($newPlacement['assigned']);
        self::assertSame(
            $stored->getEpisodeIds()[0]->toString(),
            $this->fixture->connection->fetchOne('SELECT episode_id FROM video_placement_episode WHERE placement_id = ?', [$newPlacement['id']]),
        );
        self::assertSame(1, $this->rowCount('tv_episodes'));
    }

    public function testADateAndNumberCollisionNeedsReviewInsteadOfCreatingAnotherVersion(): void
    {
        $numbered = $this->fixture->video('Daily Show/Season 01/Daily Show.S01E05.mp4');
        $this->fixture->ingest($numbered);
        $this->fixture->connection->executeStatement("UPDATE tv_episodes SET air_date = '2024-03-14'");
        $this->fixture->manager->clear();
        $daily = $this->fixture->video('Daily Show/Daily Show 2024-03-14.mp4');
        $this->fixture->ingest($daily);

        self::assertSame(1, $this->rowCount('tv_episodes'));
        self::assertSame('episode_collision', $this->placement($daily->relativePath)['review_reason']);
    }

    public function testAnAbsentFileKeepsItsExistingPlacementAndEpisode(): void
    {
        $file = $this->fixture->video('Show/Season 01/Show.S01E05.mp4');
        $this->fixture->ingest($file);
        $placement = $this->placement($file->relativePath);
        unlink($file->absolutePath);
        $this->fixture->connection->executeStatement('UPDATE library_file_index SET available = false WHERE path = ?', [$file->absolutePath]);

        $this->fixture->ingest($file);

        self::assertSame(1, $this->rowCount('tv_episodes'));
        self::assertSame(1, $this->rowCount('video_placements'));
        self::assertSame($placement['public_id'], $this->placement($file->relativePath)['public_id']);
    }

    public function testASecondAvailablePathForOneHashKeepsItsPlacementAndRecordsTheDuplicate(): void
    {
        $file = $this->fixture->video('Show/Season 01/Show.S01E05.mp4');
        $this->fixture->ingest($file);
        $placement = $this->placement($file->relativePath);
        $duplicatePath = 'Show/Season 01/Show.S01E05.copy.mp4';
        self::assertTrue(copy($file->absolutePath, $this->fixture->root . '/' . $duplicatePath));
        $duplicate = $this->fixture->observe($duplicatePath);

        $this->fixture->ingest($duplicate);
        $this->fixture->ingest($duplicate);

        self::assertSame(1, $this->rowCount('videos'));
        self::assertSame(1, $this->rowCount('video_placements'));
        $stored = $this->placement($file->relativePath);
        self::assertSame($placement['public_id'], $stored['public_id']);
        self::assertSame('duplicate_file', $stored['review_reason']);
        $raw = json_decode($stored['raw_parse'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([$duplicatePath], $raw['duplicatePaths']);
    }

    private function rowCount(string $table): int
    {
        return (int) $this->fixture->connection->fetchOne('SELECT count(*) FROM ' . $table);
    }

    /** @return array<string, mixed> */
    private function placement(string $relativePath): array
    {
        $row = $this->fixture->connection->fetchAssociative('SELECT * FROM video_placements WHERE relative_path = ?', [$relativePath]);
        self::assertIsArray($row);

        return $row;
    }

    private function repository(): TvCatalogRepositoryInterface
    {
        return $this->fixture->kernel->getContainer()->get('test.service_container')->get(TvCatalogRepositoryInterface::class);
    }
}
