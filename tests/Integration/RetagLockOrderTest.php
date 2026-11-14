<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\Port\ArtistIdentity;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ImportIdentityLock;
use App\Catalog\Infrastructure\Doctrine\Repository\SongRepository;
use App\Catalog\Infrastructure\SongService;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Pagination\CursorCodec;
use App\Shared\Infrastructure\Pagination\CursorPaginator;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * A retag locks, inside its transaction, every artist and genre its batch may write and then the
 * rows of its retagged songs in ID order: the order every catalog writer takes its locks in, so a
 * retag and a match applied to the same songs never wait on each other in a cycle. Each test lets
 * the retag wait on a lock another session holds until its lock timeout, then checks which locks the
 * retag held while it waited; a transaction keeps its locks after a failed statement until it ends.
 * The songs table copies the migrated one with its unique and B-tree indexes but not its PGroonga
 * indexes, foreign keys or triggers. Advisory locks span the database, so every name is random.
 */
final class RetagLockOrderTest extends TestCase
{
    private const string DISTURBED_ID = '4bb4e4e4-5f66-4509-98af-62dbb90c45c5';

    /** The SQLSTATE of a lock wait that timed out, or of a NOWAIT lock another session holds. */
    private const string LOCK_NOT_AVAILABLE = '55P03';

    private Connection $writer;
    private Connection $holder;
    private Connection $observer;
    private SongService $songs;
    private string $schema;
    private string $tag;
    private int $writerPid;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }

        $this->tag = random_bytes(4) |> bin2hex(...);
        $this->schema = 'retag_lock_' . (random_bytes(8) |> bin2hex(...));
        $this->observer = ArtistImportIdentityRace::connect('public');
        self::assertStringStartsWith('18.', (string) $this->observer->fetchOne('SHOW server_version'));
        $this->observer->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->observer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        ArtistImportIdentityRace::copyTableWithoutPgroonga($this->observer, 'songs');

        $this->holder = ArtistImportIdentityRace::connect($this->schema);
        $this->writer = ArtistImportIdentityRace::connect($this->schema);
        $this->writerPid = (int) $this->writer->fetchOne('SELECT pg_backend_pid()');
        $this->songs = new SongService(
            new SongRepository(ArtistImportIdentityRace::entityManager($this->writer), new CursorPaginator(), new CursorCodec(new JsonEncoder())),
            $this->writer,
        );
    }

    protected function tearDown(): void
    {
        foreach (['writer', 'holder'] as $session) {
            if (isset($this->{$session})) {
                while ($this->{$session}->isTransactionActive()) {
                    $this->{$session}->rollBack();
                }
                $this->{$session}->close();
            }
        }
        if (isset($this->observer)) {
            $this->observer->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->observer->close();
        }
    }

    public function testTheRetagTakesItsArtistAndGenreLocksBeforeItLocksASongRow(): void
    {
        [$first, $second] = $this->storeSongs();
        $genre = 'Metal ' . $this->tag;
        $this->holder->beginTransaction();
        ImportIdentityLock::acquire($this->holder, [ImportIdentityLock::genreSlug($genre) |> ImportIdentityLock::genreKey(...)]);

        $this->retagUntilItsLockWaitTimesOut([new ArtistIdentity('Disturbed ' . $this->tag)], [$genre], [$second, $first]);

        self::assertFalse($this->isRowLocked($first), 'The retag waited for the genre before it locked a song.');
        self::assertFalse($this->isRowLocked($second), 'Nor the song whose ID sorts last.');
        self::assertSame(1, $this->advisoryLocksHeldByTheRetag(), 'It held the artist lock, whose key sorts before the genre key.');
    }

    public function testTheRetagLocksItsSongRowsInIdOrderAfterEveryArtistAndGenre(): void
    {
        [$first, $second] = $this->storeSongs();
        $this->holder->beginTransaction();
        $this->holder->executeQuery('SELECT id FROM songs WHERE id = ? FOR NO KEY UPDATE', [$second])->free();

        // The songs are passed in the reverse of their ID order.
        $this->retagUntilItsLockWaitTimesOut([new ArtistIdentity('Disturbed ' . $this->tag, [self::DISTURBED_ID])], ['Metal ' . $this->tag], [$second, $first]);

        self::assertTrue($this->isRowLocked($first), 'The retag locked the song whose ID sorts first before it waited for the other.');
        self::assertSame(3, $this->advisoryLocksHeldByTheRetag(), 'It took the artist name, artist ID and genre locks before any song row.');
    }

    /**
     * Runs the retag's locks in a transaction that stays open after its lock wait times out.
     *
     * @param list<ArtistIdentity> $artists
     * @param list<string>         $genres
     * @param list<string>         $songIds
     */
    private function retagUntilItsLockWaitTimesOut(array $artists, array $genres, array $songIds): void
    {
        $this->writer->executeStatement("SET lock_timeout = '500ms'");
        $this->writer->beginTransaction();
        try {
            $this->songs->lockForRetag($artists, $genres, array_map(static fn (string $id): Uuid => new Uuid($id), $songIds));
        } catch (DriverException $e) {
            self::assertSame(self::LOCK_NOT_AVAILABLE, $e->getSQLState(), $e->getMessage());

            return;
        }
        self::fail('The retag did not wait on the lock the other session holds.');
    }

    /** Whether another session holds a lock on the song's row that a retag's lock conflicts with. */
    private function isRowLocked(string $songId): bool
    {
        try {
            $this->observer->executeQuery('SELECT id FROM songs WHERE id = ? FOR NO KEY UPDATE NOWAIT', [$songId])->free();
        } catch (DriverException $e) {
            self::assertSame(self::LOCK_NOT_AVAILABLE, $e->getSQLState(), $e->getMessage());

            return true;
        }

        return false;
    }

    private function advisoryLocksHeldByTheRetag(): int
    {
        return (int) $this->observer->fetchOne(
            "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND granted AND pid = ?",
            [$this->writerPid],
        );
    }

    /**
     * Stores two songs, the one whose ID sorts last first, so a scan of the table meets them against
     * their ID order. With fresh statistics and index scans off, the retag's lock reads the songs in
     * that heap order and locks them in ID order only by sorting them.
     *
     * @return array{string, string} the IDs of the songs, in the order PostgreSQL sorts them
     */
    private function storeSongs(): array
    {
        $ids = [(new Uuid())->toString(), (new Uuid())->toString()];
        rsort($ids, SORT_STRING);
        foreach ($ids as $id) {
            $this->observer->executeStatement(
                "INSERT INTO songs (id, public_id, album_id, title, path, size, mime_type, created_at, updated_at) VALUES (:id, :publicId, :albumId, 'Stricken', :path, 1000000, 'audio/flac', now(), now())",
                ['id' => $id, 'publicId' => (new PublicId())->toString(), 'albumId' => (new Uuid())->toString(), 'path' => '/music/' . $id . '.flac'],
            );
        }
        $this->observer->executeStatement('ANALYZE songs');
        $this->writer->executeStatement('SET enable_indexscan = off');
        $this->writer->executeStatement('SET enable_indexonlyscan = off');
        $sorted = array_map(strval(...), $this->observer->fetchFirstColumn('SELECT id::text FROM songs ORDER BY id'));
        self::assertSame(array_reverse($sorted), array_map(strval(...), $this->observer->fetchFirstColumn('SELECT id::text FROM songs')), 'The table holds the songs against their ID order.');

        return [$sorted[0], $sorted[1]];
    }
}
