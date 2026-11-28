<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Activity\Domain\Model\WatchedMark;
use App\Activity\Infrastructure\Doctrine\Repository\ResumePositionRepository;
use App\Activity\Infrastructure\Doctrine\Repository\WatchedMarkRepository;
use App\Activity\Infrastructure\Doctrine\ViewingState;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261128122300;
use DoctrineMigrations\Version20261128140000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2) . '/migrations/Version20261128122300.php';
require_once dirname(__DIR__, 2) . '/migrations/Version20261128140000.php';

final class VideoProgressUpsertTest extends TestCase
{
    private Connection $connection;
    private Connection $observer;
    private ResumePositionRepository $positions;
    private WatchedMarkRepository $marks;
    private ViewingState $viewing;
    private string $schema;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to migrated disposable PostgreSQL.');
        }
        $this->schema = 'video_progress_' . bin2hex(random_bytes(8));
        $this->connection = ArtistImportIdentityRace::connect('public');
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        foreach (['users', 'videos', 'tv_episodes', 'movies'] as $table) {
            $this->connection->executeStatement('CREATE TABLE ' . $table . ' (id UUID PRIMARY KEY)');
        }
        ArtistImportIdentityRace::copyTableWithoutPgroonga($this->connection, 'query_change_inbox');
        foreach ([Version20261128122300::class, Version20261128140000::class] as $class) {
            $migration = new $class($this->connection, new NullLogger());
            $migration->up(new Schema());
            $this->connection->transactional(static function (Connection $connection) use ($migration): void {
                foreach ($migration->getSql() as $query) {
                    $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
                }
            });
        }
        $this->observer = ArtistImportIdentityRace::connect($this->schema);
        $this->positions = new ResumePositionRepository($this->connection);
        $manager = ArtistImportIdentityRace::entityManager($this->connection);
        $this->marks = new WatchedMarkRepository($this->connection, $manager);
        $this->viewing = new ViewingState($manager);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            while ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            if (isset($this->observer)) {
                $this->observer->close();
            }
            $this->connection->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->connection->close();
        }
    }

    public function testPeriodicCadenceAndForcedActionsKeepTheReportedTimeMonotonic(): void
    {
        $user = $this->target('users');
        $video = $this->target('videos');
        self::assertFalse($this->record($user, $video, 29.9, 1));
        self::assertSame([], $this->viewing->forMember($user, videoIds: [$video])->resumes);
        self::assertTrue($this->record($user, $video, 30, 2));
        self::assertFalse($this->record($user, $video, 59.9, 3));
        self::assertTrue($this->record($user, $video, 60, 4));
        self::assertFalse($this->record($user, $video, 300, 3, true));
        self::assertFalse($this->record($user, $video, 400, 4, true));
        self::assertTrue($this->record($user, $video, 61, 5, true));
        self::assertTrue($this->record($user, $video, 3, 6));
        self::assertSame(3.0, $this->viewing->forMember($user, videoIds: [$video])->resumes[$video->toString()]->positionSeconds);
    }

    public function testCompletionTombstoneFencesLateReportsAndAllowsARewatch(): void
    {
        $user = $this->target('users');
        $video = $this->target('videos');
        $episode = $this->target('tv_episodes');
        self::assertTrue($this->record($user, $video, 400, 1));
        $this->connection->transactional(function () use ($user, $video, $episode): void {
            self::assertTrue($this->positions->record($user, $video, null, $this->instant(3), true));
            $this->marks->saveMany([WatchedMark::episode($user, $episode, $this->instant(3))]);
        });
        self::assertFalse($this->record($user, $video, 500, 2, true));
        $state = $this->viewing->forMember($user, [$episode], videoIds: [$video]);
        self::assertNull($state->resumes[$video->toString()]->positionSeconds);
        self::assertEquals($this->instant(3), $state->resumes[$video->toString()]->lastPlayedAt);
        self::assertEquals([$episode], $state->watchedEpisodeIds);
        self::assertTrue($this->record($user, $video, 400, 4));
        self::assertEquals([$episode], $this->viewing->forMember($user, [$episode])->watchedEpisodeIds);
    }

    public function testManualClearRetainsHighwaterAndDoesNotPretendAnUnplayedFileWasPlayed(): void
    {
        $user = $this->target('users');
        $video = $this->target('videos');
        $unplayed = $this->target('videos');
        self::assertTrue($this->record($user, $video, 400, 5));
        $this->connection->transactional(fn () => $this->positions->clear($user, [$video, $unplayed, $video], $this->instant(3)));
        self::assertFalse($this->record($user, $video, 500, 4, true));
        $state = $this->viewing->forMember($user, videoIds: [$video, $unplayed]);
        self::assertNull($state->resumes[$video->toString()]->positionSeconds);
        self::assertEquals($this->instant(5), $state->resumes[$video->toString()]->lastPlayedAt);
        self::assertNull($state->resumes[$unplayed->toString()]->lastPlayedAt);
        $this->connection->transactional(fn () => $this->positions->clear($user, [$video], $this->instant(7)));
        self::assertFalse($this->record($user, $video, 600, 6, true));
        self::assertTrue($this->record($user, $video, 2, 8, true));
    }

    public function testBatchStateKeepsMembersVersionsAndSharedFileMarksSeparate(): void
    {
        $user = $this->target('users');
        $other = $this->target('users');
        $first = $this->target('tv_episodes');
        $second = $this->target('tv_episodes');
        $movie = $this->target('movies');
        $video = $this->target('videos');
        $version = $this->target('videos');
        $this->connection->transactional(function () use ($user, $other, $first, $second, $movie): void {
            $this->marks->saveMany([
                WatchedMark::episode($user, $first, $this->instant(5)),
                WatchedMark::episode($user, $second),
                WatchedMark::movie($user, $movie),
                WatchedMark::episode($other, $second),
                WatchedMark::episode($user, $first, $this->instant(1)),
            ]);
            $this->marks->deleteForMemberTargets($user, [$second], []);
        });
        self::assertTrue($this->record($user, $video, 1.25, 1, true));
        self::assertTrue($this->record($user, $version, 600, 2));
        self::assertTrue($this->record($other, $video, 800, 3));
        $state = $this->viewing->forMember($user, [$first, $second, $first], [$movie], [$video, $version]);
        self::assertEquals([$first], $state->watchedEpisodeIds);
        self::assertEquals([$movie], $state->watchedMovieIds);
        self::assertSame(1.25, $state->resumes[$video->toString()]->positionSeconds);
        self::assertSame(600.0, $state->resumes[$version->toString()]->positionSeconds);
        self::assertEquals([$second], $this->viewing->forMember($other, [$first, $second])->watchedEpisodeIds);
        self::assertEquals($this->instant(5), new DateTimeImmutable($this->connection->fetchOne('SELECT watched_at FROM watched_marks WHERE user_id = ? AND episode_id = ?', [$user, $first], ['uuid', 'uuid'])));
        self::assertSame([], $this->viewing->forMember($user)->resumes);
    }

    public function testProgressAndCaptureCommitOrRollbackTogether(): void
    {
        $user = $this->target('users');
        $video = $this->target('videos');
        $this->connection->beginTransaction();
        self::assertTrue($this->positions->record($user, $video, 30, $this->instant(1), false));
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM resume_positions'));
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM query_change_inbox'));
        $this->connection->rollBack();
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM resume_positions'));
        self::assertTrue($this->record($user, $video, 30, 1));
        self::assertSame([
            ['family' => 'activity', 'scope_kind' => 'user', 'scope_id' => $user->toString()],
            ['family' => 'catalog', 'scope_kind' => 'user', 'scope_id' => $user->toString()],
        ], $this->observer->fetchAllAssociative('SELECT DISTINCT family, scope_kind, scope_id FROM query_change_inbox ORDER BY family'));
        $this->connection->transactional(fn () => $this->positions->clear($user, [$video], $this->instant(2)));
        self::assertNull($this->viewing->forMember($user, videoIds: [$video])->resumes[$video->toString()]->positionSeconds);
        $this->connection->executeStatement('DELETE FROM videos WHERE id = ?', [$video], ['uuid']);
        self::assertSame([], $this->viewing->forMember($user, videoIds: [$video])->resumes);
    }

    public function testConcurrentOldReportWaitsForTheCompletionFence(): void
    {
        $user = $this->target('users');
        $video = $this->target('videos');
        self::assertTrue($this->record($user, $video, 500, 1));
        $this->connection->beginTransaction();
        self::assertTrue($this->positions->record($user, $video, null, $this->instant(5), true));
        $contender = new ResumePositionRepository($this->observer);
        $this->observer->beginTransaction();
        $this->observer->executeStatement("SET LOCAL lock_timeout = '25ms'");
        try {
            $contender->record($user, $video, 600, $this->instant(4), true);
            self::fail('A report must wait for an uncommitted completion of the same file.');
        } catch (DriverException $exception) {
            self::assertSame('55P03', $exception->getSQLState());
        } finally {
            $this->observer->rollBack();
        }
        $this->connection->commit();
        self::assertFalse($this->observer->transactional(fn (): bool => $contender->record($user, $video, 600, $this->instant(4), true)));
        self::assertNull($this->viewing->forMember($user, videoIds: [$video])->resumes[$video->toString()]->positionSeconds);
    }

    public function testDatabaseRejectsNonfiniteAndNegativePositions(): void
    {
        $user = $this->target('users');
        $video = $this->target('videos');
        foreach (['-1', 'NaN', 'Infinity', '-Infinity'] as $position) {
            $this->connection->beginTransaction();
            try {
                $this->connection->executeStatement(
                    'INSERT INTO resume_positions (id, user_id, video_id, position_seconds, reported_at) VALUES (?, ?, ?, CAST(? AS DOUBLE PRECISION), ?)',
                    [Uuid::v7(), $user, $video, $position, $this->instant(1)],
                    ['uuid', 'uuid', 'uuid', 'string', 'datetime_immutable'],
                );
                self::fail('The database must reject position ' . $position);
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            } finally {
                $this->connection->rollBack();
            }
        }
    }

    private function record(Uuid $user, Uuid $video, ?float $position, int $second, bool $force = false): bool
    {
        return $this->connection->transactional(fn (): bool => $this->positions->record($user, $video, $position, $this->instant($second), $force));
    }

    private function instant(int $second): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('2026-11-28T14:00:%02d.123456+05:30', $second));
    }

    private function target(string $table): Uuid
    {
        $id = Uuid::v7();
        $this->connection->insert($table, ['id' => $id], ['id' => 'uuid']);

        return $id;
    }
}
