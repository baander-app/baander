<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Activity\Domain\Model\WatchedMark;
use App\Activity\Infrastructure\Doctrine\Repository\ResumePositionRepository;
use App\Activity\Infrastructure\Doctrine\Repository\WatchedMarkRepository;
use App\Activity\Infrastructure\Doctrine\ViewingState;
use App\Activity\Infrastructure\Doctrine\Entity\ResumePositionEntity;
use App\Catalog\Infrastructure\Doctrine\Query\VideoViewingProjection;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20261128122300;
use DoctrineMigrations\Version20261128140000;
use DoctrineMigrations\Version20261128160000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use InvalidArgumentException;

require_once dirname(__DIR__, 2) . '/migrations/Version20261128122300.php';
require_once dirname(__DIR__, 2) . '/migrations/Version20261128140000.php';
require_once dirname(__DIR__, 2) . '/migrations/Version20261128160000.php';

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
        foreach ([Version20261128122300::class, Version20261128140000::class, Version20261128160000::class] as $class) {
            $this->migrate($this->connection, $class);
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

    public function testInclusiveClockWindowKeepsMicrosecondsAndCannotRatchetBackwards(): void
    {
        $user = $this->target('users');
        $video = $this->target('videos');
        self::assertTrue($this->record($user, $video, 300, 20, true));
        self::assertTrue($this->record($user, $video, 200, 17, true, 5));
        self::assertTrue($this->record($user, $video, 100, 15, true, 5));
        $outside = $this->instant(15)->modify('-1 microsecond');
        self::assertFalse($this->connection->transactional(fn (): bool => $this->positions->record($user, $video, 90, $outside, true, 5)));
        self::assertFalse($this->record($user, $video, 80, 14, true, 5));
        self::assertFalse($this->record($user, $video, 70, 20, true, 5));
        self::assertFalse($this->record($user, $video, 60, 19, true));
        $state = $this->viewing->forMember($user, videoIds: [$video])->resumes[$video->toString()];
        self::assertSame(100.0, $state->positionSeconds);
        self::assertEquals($this->instant(20), $state->lastPlayedAt);
        self::assertEquals($this->instant(20), new DateTimeImmutable($this->connection->fetchOne('SELECT reported_at FROM resume_positions')));
    }

    public function testClockWindowAllowsForcedAndCadencedReportsAndDoesNotOverflow(): void
    {
        $user = $this->target('users');
        $video = $this->target('videos');
        self::assertTrue($this->record($user, $video, 100, 20, true));
        self::assertFalse($this->record($user, $video, 129.999, 19, false, 5));
        self::assertTrue($this->record($user, $video, 130, 19, false, 5));
        self::assertTrue($this->record($user, $video, 131, 18, true, 5));
        self::assertTrue($this->connection->transactional(fn (): bool => $this->positions->record(
            $user, $video, 150, new DateTimeImmutable('1900-01-01T00:00:00+00:00'), true, PHP_INT_MAX,
        )));
        self::assertEquals($this->instant(20), $this->viewing->forMember($user, videoIds: [$video])->resumes[$video->toString()]->lastPlayedAt);
    }

    public function testManualBarrierSurvivesRewatchAndRejectsDelayedPreClearCompletion(): void
    {
        $user = $this->target('users');
        $video = $this->target('videos');
        self::assertTrue($this->record($user, $video, 300, 10, true));
        $this->connection->transactional(fn () => $this->positions->clear($user, [$video], $this->instant(15)));
        self::assertFalse($this->record($user, $video, 400, 14, true, 100));
        self::assertTrue($this->record($user, $video, 30, 20, true, 100));
        self::assertFalse($this->record($user, $video, 500, 14, true, 100));
        self::assertFalse($this->record($user, $video, null, 15, true, 100));
        self::assertTrue($this->record($user, $video, 40, 16, true, 100));
        self::assertSame(40.0, $this->viewing->forMember($user, videoIds: [$video])->resumes[$video->toString()]->positionSeconds);
        self::assertEquals($this->instant(20), $this->viewing->forMember($user, videoIds: [$video])->resumes[$video->toString()]->lastPlayedAt);
    }

    public function testToleratedCompletionPinsTheGreatestHighwaterThroughRewatch(): void
    {
        $user = $this->target('users');
        $video = $this->target('videos');
        $episode = $this->target('tv_episodes');
        self::assertTrue($this->record($user, $video, 300, 20, true));
        $this->connection->transactional(function () use ($user, $video, $episode): void {
            self::assertTrue($this->positions->record($user, $video, null, $this->instant(15), true, 5));
            $this->marks->saveMany([WatchedMark::episode($user, $episode, $this->instant(21))]);
        });
        self::assertNull($this->viewing->forMember($user, videoIds: [$video])->resumes[$video->toString()]->positionSeconds);
        self::assertEquals($this->instant(20), new DateTimeImmutable($this->connection->fetchOne('SELECT protected_through_at FROM resume_positions')));
        self::assertFalse($this->record($user, $video, 400, 19, true, 100));
        self::assertTrue($this->record($user, $video, 40, 25, true, 100));
        self::assertFalse($this->record($user, $video, 500, 19, true, 100));
        self::assertFalse($this->record($user, $video, null, 20, true, 100));
        self::assertEquals([$episode], $this->viewing->forMember($user, [$episode])->watchedEpisodeIds);
        self::assertEquals($this->instant(25), $this->viewing->forMember($user, videoIds: [$video])->resumes[$video->toString()]->lastPlayedAt);
    }

    public function testToleratedUpdatesKeepThePreferredFileAndItsLastPlayedInstant(): void
    {
        $user = $this->target('users');
        $first = $this->target('videos');
        $second = $this->target('videos');
        self::assertTrue($this->record($user, $first, 30, 20, true));
        self::assertTrue($this->record($user, $second, 30, 15, true));
        self::assertTrue($this->record($user, $first, 60, 10, true, 15));
        $firstPublicId = new PublicId()->toString();
        $state = $this->viewing->forMember($user, videoIds: [$first, $second]);
        $links = [];
        foreach ([[$first, $firstPublicId], [$second, new PublicId()->toString()]] as [$id, $publicId]) {
            $links[] = [
                'work_id' => Uuid::v7()->toString(), 'library_id' => Uuid::v7()->toString(),
                'video_id' => $id->toString(), 'public_id' => $publicId,
                'placement_public_id' => new PublicId()->toString(), 'extra_kind' => null,
                'width' => null, 'height' => null, 'duration' => 100, 'video_bitrate' => null,
            ];
        }
        $title = new VideoViewingProjection($this->viewing)->title($links, $state, false);
        self::assertSame($firstPublicId, $title['defaultVideoPublicId']);
        self::assertSame(0.6, $title['progress']);
        self::assertEquals($this->instant(20), $state->resumes[$first->toString()]->lastPlayedAt);
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
            $contender->record($user, $video, 600, $this->instant(4), true, 5);
            self::fail('A report must wait for an uncommitted completion of the same file.');
        } catch (DriverException $exception) {
            self::assertSame('55P03', $exception->getSQLState());
        } finally {
            $this->observer->rollBack();
        }
        $this->connection->commit();
        self::assertFalse($this->observer->transactional(fn (): bool => $contender->record($user, $video, 600, $this->instant(4), true, 5)));
        self::assertNull($this->viewing->forMember($user, videoIds: [$video])->resumes[$video->toString()]->positionSeconds);
    }

    public function testConcurrentToleratedReportUsesTheCommittedHighwaterWithoutRegressingIt(): void
    {
        $user = $this->target('users');
        $video = $this->target('videos');
        self::assertTrue($this->record($user, $video, 100, 20, true));
        $this->connection->beginTransaction();
        self::assertTrue($this->positions->record($user, $video, 200, $this->instant(30), true));
        $contender = new ResumePositionRepository($this->observer);
        $this->observer->beginTransaction();
        $this->observer->executeStatement("SET LOCAL lock_timeout = '25ms'");
        try {
            $contender->record($user, $video, 160, $this->instant(25), true, 5);
            self::fail('A tolerated report must serialize with the newer observation.');
        } catch (DriverException $exception) {
            self::assertSame('55P03', $exception->getSQLState());
        } finally {
            $this->observer->rollBack();
        }
        $this->connection->commit();
        self::assertTrue($this->observer->transactional(fn (): bool => $contender->record($user, $video, 160, $this->instant(25), true, 5)));
        self::assertFalse($this->connection->transactional(fn (): bool => $this->positions->record($user, $video, 150, $this->instant(25)->modify('-1 microsecond'), true, 5)));
        $state = $this->viewing->forMember($user, videoIds: [$video])->resumes[$video->toString()];
        self::assertSame(160.0, $state->positionSeconds);
        self::assertEquals($this->instant(30), $state->lastPlayedAt);
        self::assertEquals($this->instant(30), new DateTimeImmutable($this->connection->fetchOne('SELECT reported_at FROM resume_positions')));
    }

    public function testProtectionMigrationPreservesExistingHistoryAndItsStrictBoundary(): void
    {
        $schema = 'progress_upgrade_' . bin2hex(random_bytes(8));
        $legacy = ArtistImportIdentityRace::connect('public');
        $legacy->executeStatement('CREATE SCHEMA ' . $schema);
        try {
            $legacy->executeStatement('SET search_path TO ' . $schema . ', public');
            foreach (['users', 'videos'] as $table) {
                $legacy->executeStatement('CREATE TABLE ' . $table . ' (id UUID PRIMARY KEY)');
            }
            ArtistImportIdentityRace::copyTableWithoutPgroonga($legacy, 'query_change_inbox');
            $this->migrate($legacy, Version20261128140000::class);
            $user = Uuid::v7();
            $legacy->insert('users', ['id' => $user], ['id' => 'uuid']);
            $played = Uuid::v7();
            $cleared = Uuid::v7();
            $unplayed = Uuid::v7();
            foreach ([[$played, 40.0, $this->instant(20)], [$cleared, null, $this->instant(10)], [$unplayed, null, null]] as [$video, $position, $lastPlayed]) {
                $legacy->insert('videos', ['id' => $video], ['id' => 'uuid']);
                $legacy->executeStatement(
                    'INSERT INTO resume_positions (id, user_id, video_id, position_seconds, reported_at, last_played_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [Uuid::v7(), $user, $video, $position, $this->instant(20), $lastPlayed],
                    ['uuid', 'uuid', 'uuid', 'float', 'datetime_immutable', 'datetime_immutable'],
                );
            }
            $historySql = 'SELECT id, user_id, video_id, position_seconds, reported_at, last_played_at FROM resume_positions ORDER BY video_id';
            $history = $legacy->fetchAllAssociative($historySql);
            $legacy->executeStatement('DELETE FROM query_change_inbox');
            $this->migrate($legacy, Version20261128160000::class);
            self::assertSame($history, $legacy->fetchAllAssociative($historySql));
            self::assertSame(0, (int) $legacy->fetchOne('SELECT count(*) FROM query_change_inbox'));
            foreach ($legacy->fetchFirstColumn('SELECT protected_through_at FROM resume_positions') as $boundary) {
                self::assertEquals($this->instant(20), new DateTimeImmutable($boundary));
            }
            $store = new ResumePositionRepository($legacy);
            foreach ([$played, $cleared, $unplayed] as $video) {
                self::assertFalse($legacy->transactional(fn (): bool => $store->record($user, $video, 50, $this->instant(19), true, PHP_INT_MAX)));
            }
            self::assertTrue($legacy->transactional(fn (): bool => $store->record($user, $played, 50, $this->instant(22), true, 5)));
            self::assertTrue($legacy->transactional(fn (): bool => $store->record($user, $played, 60, $this->instant(21), true, 5)));
            self::assertTrue($legacy->transactional(fn (): bool => $store->record($user, $cleared, 30, $this->instant(22), true, 5)));
            self::assertFalse($legacy->transactional(fn (): bool => $store->record($user, $cleared, null, $this->instant(19), true, 5)));
            self::assertNull($legacy->fetchOne('SELECT last_played_at FROM resume_positions WHERE video_id = ?', [$unplayed], ['uuid']));
        } finally {
            while ($legacy->isTransactionActive()) {
                $legacy->rollBack();
            }
            $legacy->executeStatement('DROP SCHEMA ' . $schema . ' CASCADE');
            $legacy->close();
        }
    }

    public function testProtectionIsMappedAsAPrivateNativePrecisionInstant(): void
    {
        $manager = ArtistImportIdentityRace::entityManager($this->connection);
        $metadata = $manager->getClassMetadata(ResumePositionEntity::class);
        self::assertTrue($metadata->isReadOnly);
        self::assertSame('protected_through_at', $metadata->getColumnName('protectedThroughAt'));
        self::assertSame('datetime_immutable', $metadata->getTypeOfField('protectedThroughAt'));
        self::assertSame('timestamp with time zone', $this->connection->fetchOne(
            "SELECT format_type(atttypid, atttypmod) FROM pg_attribute WHERE attrelid = 'resume_positions'::regclass AND attname = 'protected_through_at'",
        ));
    }

    public function testNegativeClockToleranceIsRejected(): void
    {
        $user = $this->target('users');
        $video = $this->target('videos');
        $this->expectException(InvalidArgumentException::class);
        $this->record($user, $video, 50, 20, true, -1);
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

    private function record(Uuid $user, Uuid $video, ?float $position, int $second, bool $force = false, int $clockSkewSeconds = 0): bool
    {
        return $this->connection->transactional(fn (): bool => $this->positions->record($user, $video, $position, $this->instant($second), $force, $clockSkewSeconds));
    }

    /** @param class-string<AbstractMigration> $class */
    private function migrate(Connection $connection, string $class): void
    {
        $migration = new $class($connection, new NullLogger());
        $migration->up(new Schema());
        $connection->transactional(static function (Connection $connection) use ($migration): void {
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        });
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
