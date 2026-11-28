<?php

declare(strict_types=1);

namespace App\Tests\Functional\Activity;

use App\Auth\Domain\Model\User;
use App\Catalog\Domain\Model\TvEpisode;
use App\Catalog\Domain\Model\TvSeason;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Model\VideoPlacement;
use App\Catalog\Domain\ValueObject\VideoName\ExtraKind;
use App\Catalog\Infrastructure\Doctrine\Entity\TvEpisodeEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\TvSeasonEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\TvSeriesEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoPlacementEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoPlacementEpisodeEntity;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryFileIndexEntity;
use App\Party\Infrastructure\Doctrine\Entity\SyncedPartySessionEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use DateTimeImmutable;
use Symfony\Component\Filesystem\Filesystem;

final class WatchedControllerTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rootPath = sys_get_temp_dir() . '/baander-watched-' . bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->rootPath);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->rootPath);
        parent::tearDown();
    }

    public function testSpanCompletionRewatchAndManualUnmarkStayMemberSpecific(): void
    {
        $member = $this->createTestUser();
        $other = $this->createTestUser();
        [$library, $video, $placement, $episodes] = $this->catalog();
        $this->grant($member, $library);
        $this->grant($other, $library);
        $titleId = $episodes[0]->publicId->toString();
        self::assertSame(['recorded' => true], $this->report($member, $video, $titleId, 90.0, 0)['data']);
        self::assertSame(0, $this->markCount($member));
        self::assertSame(90.0, $this->position($member, $video));
        $this->report($member, $video, $titleId, 90.001, 1);
        self::assertSame(2, $this->markCount($member));
        self::assertNull($this->position($member, $video));
        self::assertSame(['recorded' => false], $this->report($member, $video, $titleId, 100.0, 0)['data']);
        $this->report($member, $video, $titleId, 40.0, 2, 'pause');
        self::assertSame(40.0, $this->position($member, $video));
        self::assertSame(2, $this->markCount($member));
        $this->report($other, $video, $titleId, 100.0, 0);
        $response = $this->authenticatedRequest('PUT', '/api/activity/watched/episode/' . $episodes[1]->publicId, $member, ['watched' => false]);
        self::assertSame(['watched' => false, 'titles' => 1], $this->assertJsonResponse($response, 200)['data']);
        self::assertSame(1, $this->markCount($member));
        self::assertSame(2, $this->markCount($other));
        self::assertNull($this->position($member, $video));
        self::assertSame(['recorded' => false], $this->report($member, $video, $titleId, 70.0, 3, 'pause')['data']);
        self::assertNull($this->position($member, $video));
    }

    public function testFileVersionsKeepSeparatePositionsAndAnUpgradeKeepsWatchedMarks(): void
    {
        $member = $this->createTestUser();
        [$library, $oldVideo, $oldPlacement, $episodes, $season, $series] = $this->catalog();
        $this->grant($member, $library);
        [$replacement] = $this->file($library, $series, array_slice($episodes, 0, 2), 'replacement.mp4');
        $this->entityManager->flush();
        $title = $episodes[0]->publicId->toString();
        $this->report($member, $oldVideo, $title, 30.0, 0);
        self::assertSame(30.0, $this->position($member, $oldVideo));
        self::assertNull($this->position($member, $replacement));
        $this->report($member, $replacement, $title, 50.0, 1);
        self::assertSame(30.0, $this->position($member, $oldVideo));
        self::assertSame(50.0, $this->position($member, $replacement));
        $this->report($member, $oldVideo, $title, 100.0, 2);
        self::assertSame(2, $this->markCount($member));
        self::assertSame(50.0, $this->position($member, $replacement));
        $this->entityManager->remove($oldPlacement);
        $this->entityManager->flush();
        [$upgrade] = $this->file($library, $series, array_slice($episodes, 0, 2), 'upgrade.mp4');
        $this->entityManager->flush();
        self::assertSame(2, $this->markCount($member));
        self::assertNull($this->position($member, $upgrade));
    }

    public function testManualSeasonMarkIncludesOnlyOwnedAvailableEpisodesAndDoesNotMarkFutureOnes(): void
    {
        $member = $this->createTestUser();
        [$library, $video, $placement, $episodes, $season, $series] = $this->catalog();
        $this->grant($member, $library);
        $response = $this->authenticatedRequest('PUT', '/api/activity/watched/season/' . $season->publicId, $member, ['watched' => true]);
        self::assertSame(['watched' => true, 'titles' => 8], $this->assertJsonResponse($response, 200)['data']);
        self::assertSame(8, $this->markCount($member));
        $new = TvEpisode::create($series->id, $season->id, 11, 'New arrival');
        $this->entityManager->persist(new TvEpisodeEntity($series, $season, $new->getState()));
        $this->entityManager->flush();
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT count(*) FROM watched_marks WHERE user_id = :user AND episode_id = :episode',
            ['user' => $member->getId()->toString(), 'episode' => $new->getId()->toString()],
        ));
    }

    public function testExtrasResumeThroughPauseAndFinishWithoutWatchedMarks(): void
    {
        $member = $this->createTestUser();
        [$library, $video, $placement] = $this->catalog(extra: true);
        $this->grant($member, $library);
        $title = $placement->publicId->toString();
        $this->report($member, $video, $title, 30.0, 0, kind: 'extra');
        self::assertSame(30.0, $this->position($member, $video));
        $this->report($member, $video, $title, 40.0, 1, 'pause', 'extra');
        self::assertSame(40.0, $this->position($member, $video));
        $this->report($member, $video, $title, 80.0, 2, kind: 'extra');
        self::assertSame(80.0, $this->position($member, $video));
        $this->report($member, $video, $title, 100.0, 3, 'end', 'extra');
        self::assertNull($this->position($member, $video));
        self::assertSame(0, $this->markCount($member));
    }

    public function testPartyReportsAreIgnoredWhileManualMarksRemainExplicit(): void
    {
        $member = $this->createTestUser();
        [$library, $video, $placement, $episodes] = $this->catalog();
        $this->grant($member, $library);
        $party = new SyncedPartySessionEntity(new PublicId(), $member->getId(), $video->getId(), null);
        $this->entityManager->persist($party);
        $this->entityManager->flush();
        self::assertSame(['recorded' => false], $this->report($member, $video, $episodes[0]->publicId->toString(), 100.0, 0)['data']);
        self::assertNull($this->position($member, $video));
        self::assertSame(0, $this->markCount($member));
        $response = $this->authenticatedRequest('PUT', '/api/activity/watched/episode/' . $episodes[0]->publicId, $member, ['watched' => true]);
        $this->assertJsonResponse($response, 200);
        self::assertSame(1, $this->markCount($member));
    }

    public function testAuthenticationScopeAndProgressInputAreValidated(): void
    {
        $member = $this->createTestUser();
        [$library, $video, $placement, $episodes] = $this->catalog();
        $uri = '/api/activity/videos/' . $video->getPublicId() . '/progress';
        $body = ['titleKind' => 'episode', 'titleId' => $episodes[0]->publicId->toString(), 'positionSeconds' => 40.0, 'action' => 'pause', 'reportedAt' => '2020-01-01T00:00:00Z'];
        $this->assertJsonResponse($this->anonymousRequest('POST', $uri, $body), 401);
        $this->assertJsonResponse($this->authenticatedRequest('POST', $uri, $member, $body), 404);
        $this->assertJsonResponse($this->authenticatedRequest('PUT', '/api/activity/watched/episode/' . $episodes[0]->publicId, $member, ['watched' => true]), 404);
        $this->grant($member, $library);
        foreach ([['positionSeconds' => -1], ['action' => 'unknown'], ['reportedAt' => '2026-02-30T00:00:00Z'], ['reportedAt' => '2026-01-01T00:00:00'], ['titleKind' => 'season']] as $invalid) {
            $invalidBody = array_replace($body, $invalid);
            $this->assertJsonResponse($this->authenticatedRequest('POST', $uri, $member, $invalidBody), 422);
        }
        $missingBody = array_replace($body, ['titleId' => (new PublicId())->toString()]);
        $this->assertJsonResponse($this->authenticatedRequest('POST', $uri, $member, $missingBody), 404);
        self::assertSame(0, $this->markCount($member));
        self::assertNull($this->position($member, $video));
    }

    /** @return array{LibraryEntity, VideoEntity, VideoPlacementEntity, list<TvEpisodeEntity>, TvSeasonEntity, TvSeriesEntity} */
    private function catalog(bool $extra = false): array
    {
        $library = new LibraryEntity('Watched test', 'watched-' . bin2hex(random_bytes(8)), $this->rootPath, 'tv_show', 'local');
        $seriesModel = TvSeries::create($library->getId(), 'Show', 'Show');
        $series = new TvSeriesEntity($library, $seriesModel->getState());
        $seasonModel = TvSeason::create($series->id, 1);
        $season = new TvSeasonEntity($series, $seasonModel->getState());
        foreach ([$library, $series, $season] as $entity) {
            $this->entityManager->persist($entity);
        }
        $episodes = [];
        for ($number = 1; $number <= 10; ++$number) {
            $episode = TvEpisode::create($series->id, $season->id, $number, 'Episode ' . $number);
            $episodes[] = $entity = new TvEpisodeEntity($series, $season, $episode->getState());
            $this->entityManager->persist($entity);
        }
        [$video, $placement] = $this->file($library, $series, $extra ? [] : array_slice($episodes, 0, 2), 'span.mp4');
        if (!$extra) {
            foreach (array_slice($episodes, 2, 6) as $episode) {
                $this->file($library, $series, [$episode], 'episode-' . $episode->episodeNumber . '.mp4');
            }
        }
        $this->entityManager->flush();

        return [$library, $video, $placement, $episodes, $season, $series];
    }

    /**
     * @param list<TvEpisodeEntity> $episodes
     * @return array{VideoEntity, VideoPlacementEntity}
     */
    private function file(LibraryEntity $library, TvSeriesEntity $series, array $episodes, string $relativePath): array
    {
        $path = $this->rootPath . '/' . $relativePath;
        $contents = random_bytes(32) |> bin2hex(...);
        file_put_contents($path, $contents);
        $hash = hash_file('sha256', $path);
        self::assertIsString($hash);
        $video = new VideoEntity(new PublicId(), $path, $hash);
        $video->setDuration(100);
        $placement = VideoPlacement::create($library->getId(), $video->getId(), $relativePath, [], seriesId: $series->id);
        if ($episodes === []) {
            $placement->assignExtra(ExtraKind::Trailer);
        } else {
            $placement->assignEpisodes(array_map(static fn (TvEpisodeEntity $episode): Uuid => $episode->id, $episodes));
        }
        $entity = new VideoPlacementEntity($library, $video, $placement->getState());
        $entity->series = $series;
        foreach ($episodes as $ordinal => $episode) {
            $entity->episodes->add(new VideoPlacementEpisodeEntity($entity, $episode, $ordinal));
        }
        $index = new LibraryFileIndexEntity(Uuid::v7(), $library->getId(), $path, $hash, (int) filesize($path), 'mp4', (int) filemtime($path), new DateTimeImmutable());
        foreach ([$video, $entity, $index] as $persisted) {
            $this->entityManager->persist($persisted);
        }

        return [$video, $entity];
    }

    private function grant(User $user, LibraryEntity $library): void
    {
        static::getContainer()->get(LibraryAccessPortInterface::class)->grant($user->getId(), $library->getId());
    }

    /** @return array<array-key, mixed> */
    private function report(User $user, VideoEntity $video, string $titleId, float $position, int $second, string $action = 'progress', string $kind = 'episode'): array
    {
        return $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/activity/videos/' . $video->getPublicId() . '/progress', $user, [
            'titleKind' => $kind,
            'titleId' => $titleId,
            'positionSeconds' => $position,
            'action' => $action,
            'reportedAt' => sprintf('2020-01-01T00:00:%02dZ', $second),
        ]), 200);
    }

    private function markCount(User $member): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM watched_marks WHERE user_id = :user', ['user' => $member->getId()->toString()]);
    }

    private function position(User $member, VideoEntity $video): ?float
    {
        $value = $this->entityManager->getConnection()->fetchOne('SELECT position_seconds FROM resume_positions WHERE user_id = :user AND video_id = :video', ['user' => $member->getId()->toString(), 'video' => $video->getId()->toString()]);

        return $value === false || $value === null ? null : (float) $value;
    }
}
