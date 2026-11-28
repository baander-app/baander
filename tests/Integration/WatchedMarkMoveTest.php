<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Activity\Application\DTO\WatchedMarkEpisodeMerge;
use App\Activity\Application\DTO\WatchedMarkVideoMove;
use App\Activity\Domain\Model\WatchedMark;
use App\Activity\Infrastructure\Doctrine\Repository\WatchedMarkRepository;
use App\Activity\Infrastructure\Doctrine\WatchedMarkMove;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261128122300;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2) . '/migrations/Version20261128122300.php';

final class WatchedMarkMoveTest extends TestCase
{
    private Connection $connection;
    private Connection $observer;
    private WatchedMarkRepository $marks;
    private WatchedMarkMove $mover;
    private string $schema;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to migrated disposable PostgreSQL.');
        }
        $this->schema = 'watched_move_' . bin2hex(random_bytes(8));
        $this->connection = ArtistImportIdentityRace::connect('public');
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        foreach (['users', 'tv_episodes', 'movies'] as $table) {
            $this->connection->executeStatement('CREATE TABLE ' . $table . ' (id UUID PRIMARY KEY)');
        }
        ArtistImportIdentityRace::copyTableWithoutPgroonga($this->connection, 'query_change_inbox');
        $migration = new Version20261128122300($this->connection, new NullLogger());
        $migration->up(new Schema());
        $this->connection->transactional(static function (Connection $connection) use ($migration): void {
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        });
        $this->observer = ArtistImportIdentityRace::connect($this->schema);
        $this->marks = new WatchedMarkRepository($this->connection);
        $this->mover = new WatchedMarkMove($this->connection, $this->marks);
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

    public function testSimultaneousChainAndCycleRemainIdempotentAcrossCommittedRetries(): void
    {
        $alice = $this->target('users');
        $bob = $this->target('users');
        $first = $this->target('tv_episodes');
        $second = $this->target('tv_episodes');
        $third = $this->target('tv_episodes');
        $this->marks->save(WatchedMark::episode($alice, $first));
        $this->marks->save(WatchedMark::episode($bob, $second));
        $chain = [
            new WatchedMarkVideoMove(Uuid::v7(), [$first], [$second]),
            new WatchedMarkVideoMove(Uuid::v7(), [$second], [$third]),
        ];
        $operation = Uuid::v7();
        $this->move($operation, $chain);
        $expected = $this->rows();
        self::assertSame($this->sorted([$alice . ':' . $second, $bob . ':' . $third]), $expected);
        $this->move($operation, $chain);
        self::assertSame($expected, $this->rows());

        $cycle = [
            new WatchedMarkVideoMove(Uuid::v7(), [$second], [$third]),
            new WatchedMarkVideoMove(Uuid::v7(), [$third], [$second]),
        ];
        $operation = Uuid::v7();
        $this->move($operation, $cycle);
        $expected = $this->rows();
        self::assertSame($this->sorted([$alice . ':' . $third, $bob . ':' . $second]), $expected);
        $this->move($operation, $cycle);
        self::assertSame($expected, $this->rows());
    }

    public function testRetainedUnavailableVersionAndMultiEpisodeCopyPreserveMarks(): void
    {
        $member = $this->target('users');
        $first = $this->target('tv_episodes');
        $second = $this->target('tv_episodes');
        $third = $this->target('tv_episodes');
        $this->marks->save(WatchedMark::episode($member, $first));
        $this->move(Uuid::v7(), [new WatchedMarkVideoMove(Uuid::v7(), [$first], [$second, $third])], [$first]);
        self::assertSame($this->sorted([$member . ':' . $first, $member . ':' . $second, $member . ':' . $third]), $this->rows());
    }

    public function testMergeWithoutFilesCombinesMarksAndKeepsTheLaterInstant(): void
    {
        $member = $this->target('users');
        $first = $this->target('tv_episodes');
        $second = $this->target('tv_episodes');
        $older = new DateTimeImmutable('2026-11-27T22:00:00.123456-03:00');
        $later = new DateTimeImmutable('2026-11-28T03:00:00.654321+00:00');
        $this->marks->save(WatchedMark::episode($member, $first, $later));
        $this->marks->save(WatchedMark::episode($member, $second, $older));
        $operation = Uuid::v7();
        $this->connection->transactional(fn () => $this->mover->move($operation, [], [], [new WatchedMarkEpisodeMerge($first, $second)]));
        self::assertSame([$member . ':' . $second], $this->rows());
        self::assertEquals($later, $this->marks->findByEpisodeIds([$second])[0]->getWatchedAt());
    }

    public function testMoveAndReceiptAreInvisibleUntilCommitAndRollbackTogether(): void
    {
        $member = $this->target('users');
        $first = $this->target('tv_episodes');
        $second = $this->target('tv_episodes');
        $this->marks->save(WatchedMark::episode($member, $first));
        $operation = Uuid::v7();
        $videos = [new WatchedMarkVideoMove(Uuid::v7(), [$first], [$second])];
        $this->connection->beginTransaction();
        $this->mover->move($operation, $videos, []);
        self::assertSame($first->toString(), $this->observer->fetchOne('SELECT episode_id FROM watched_marks'));
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM watched_mark_moves'));
        $this->connection->rollBack();
        self::assertSame([$member . ':' . $first], $this->rows());
        $this->move($operation, $videos);
        self::assertSame([$member . ':' . $second], $this->rows());
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM watched_mark_moves'));
    }

    public function testMovieMarksRoundTripAndDoNotParticipateInEpisodeMoves(): void
    {
        $member = $this->target('users');
        $movie = $this->target('movies');
        $instant = new DateTimeImmutable('2026-11-28T12:00:00.123456+05:30');
        $this->marks->save(WatchedMark::movie($member, $movie, $instant));
        $this->move(Uuid::v7(), []);
        $mark = $this->marks->findForMovie($member, $movie);
        self::assertNotNull($mark);
        self::assertEquals($instant, $mark->getWatchedAt());
        self::assertSame($movie->toString(), $mark->getMovieId()?->toString());
        self::assertNull($mark->getEpisodeId());
    }

    public function testMarkCaptureIsScopedToTheMember(): void
    {
        $member = $this->target('users');
        $episode = $this->target('tv_episodes');
        $this->marks->save(WatchedMark::episode($member, $episode));

        self::assertSame([
            ['family' => 'activity', 'scope_kind' => 'user', 'scope_id' => $member->toString()],
            ['family' => 'catalog', 'scope_kind' => 'user', 'scope_id' => $member->toString()],
        ], $this->connection->fetchAllAssociative('SELECT DISTINCT family, scope_kind, scope_id FROM query_change_inbox ORDER BY family'));
    }

    public function testDatabaseRejectsAnUnknownMemberAndTwoTargets(): void
    {
        $member = $this->target('users');
        $episode = $this->target('tv_episodes');
        $movie = $this->target('movies');
        foreach ([
            [Uuid::v7(), $episode, null, '23503'],
            [$member, $episode, $movie, '23514'],
            [$member, null, null, '23514'],
        ] as [$user, $episodeId, $movieId, $expectedState]) {
            try {
                $this->connection->executeStatement(
                    'INSERT INTO watched_marks (id, user_id, episode_id, movie_id, watched_at) VALUES (?, ?, ?, ?, clock_timestamp())',
                    [Uuid::v7(), $user, $episodeId, $movieId],
                    ['uuid', 'uuid', 'uuid', 'uuid'],
                );
                self::fail('The database accepted an invalid watched target.');
            } catch (DriverException $error) {
                self::assertSame($expectedState, $error->getSQLState());
            }
        }
    }

    public function testCallWithoutPlacementTransactionIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->mover->move(Uuid::v7(), [], []);
    }

    /**
     * @param list<WatchedMarkVideoMove> $videos
     * @param list<Uuid> $retained
     */
    private function move(Uuid $operation, array $videos, array $retained = []): void
    {
        $this->connection->transactional(fn () => $this->mover->move($operation, $videos, $retained));
    }

    private function target(string $table): Uuid
    {
        $id = Uuid::v7();
        $this->connection->insert($table, ['id' => $id], ['id' => 'uuid']);

        return $id;
    }

    /** @return list<string> */
    private function rows(): array
    {
        return $this->connection->fetchFirstColumn("SELECT user_id::text || ':' || episode_id::text FROM watched_marks WHERE episode_id IS NOT NULL ORDER BY user_id, episode_id");
    }

    /**
     * @param list<string> $rows
     * @return list<string>
     */
    private function sorted(array $rows): array
    {
        sort($rows, SORT_STRING);

        return $rows;
    }
}
