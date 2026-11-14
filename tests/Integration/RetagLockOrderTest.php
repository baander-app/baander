<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ImportIdentityLock;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\RetagLockRace;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\TestCase;

/**
 * A retag locks, inside its transaction, every artist and genre its batch may write and then the
 * rows of its retagged songs in ID order: the order every catalog writer takes its locks in, so a
 * retag and a match applied to the same songs never wait on each other in a cycle. Each test makes
 * a retag in a child process wait on a lock the test session holds, checks which locks the retag
 * holds while it waits, then releases its lock and lets the retag commit. The songs table copies
 * the migrated one with its unique and B-tree indexes but not its PGroonga indexes, foreign keys
 * or triggers. Advisory locks span the database, so every name is random.
 */
final class RetagLockOrderTest extends TestCase
{
    private const string DISTURBED_ID = '4bb4e4e4-5f66-4509-98af-62dbb90c45c5';

    /** The SQLSTATE of a NOWAIT lock another session holds. */
    private const string LOCK_NOT_AVAILABLE = '55P03';

    private Connection $holder;
    private Connection $observer;
    private string $schema;
    private string $tag;
    /** @var array{process: resource, output: resource, application: string}|null */
    private ?array $retag = null;

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
    }

    protected function tearDown(): void
    {
        if (isset($this->holder)) {
            while ($this->holder->isTransactionActive()) {
                $this->holder->rollBack();
            }
            $this->holder->close();
        }
        if ($this->retag !== null) {
            // The test failed while the retag ran; its own failure is the one to report.
            try {
                ArtistImportIdentityRace::finish($this->retag);
            } catch (\RuntimeException|\JsonException) {
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

        $waiting = $this->startRetag([['Disturbed ' . $this->tag, []]], [$genre], [$second, $first]);

        self::assertSame('advisory', $waiting['waitsOn'], 'The retag waits for the genre lock.');
        self::assertFalse($this->isRowLocked($first), 'It waits for the genre before it locks a song.');
        self::assertFalse($this->isRowLocked($second), 'Nor the song whose ID sorts last.');
        self::assertSame(1, $this->advisoryLocksHeldBy($waiting['pid']), 'It holds the artist lock, whose key sorts before the genre key.');
        $this->holder->commit();
        self::assertSame(['locked' => true], $this->finishRetag());
    }

    public function testTheRetagLocksItsSongRowsInIdOrderAfterEveryArtistAndGenre(): void
    {
        [$first, $second] = $this->storeSongs();
        $this->holder->beginTransaction();
        $this->holder->executeQuery('SELECT id FROM songs WHERE id = ? FOR NO KEY UPDATE', [$second])->free();

        // The songs are passed in the reverse of their ID order.
        $waiting = $this->startRetag([['Disturbed ' . $this->tag, [self::DISTURBED_ID]]], ['Metal ' . $this->tag], [$second, $first]);

        self::assertNotSame('advisory', $waiting['waitsOn'], 'The retag waits for the song row, not for an artist or genre lock.');
        self::assertTrue($this->isRowLocked($first), 'It locked the song whose ID sorts first before it waited for the other.');
        self::assertSame(3, $this->advisoryLocksHeldBy($waiting['pid']), 'It took the artist name, artist ID and genre locks before any song row.');
        $this->holder->commit();
        self::assertSame(['locked' => true], $this->finishRetag());
    }

    /**
     * Starts the retag in its child session and waits until it waits on a lock.
     *
     * @param list<array{0: string, 1: list<string>}> $artists tagged name and MusicBrainz IDs of each artist
     * @param list<string>                            $genres
     * @param list<string>                            $songIds
     *
     * @return array{pid: int, waitsOn: string} the retag's backend process ID and the type of the lock it waits on
     */
    private function startRetag(array $artists, array $genres, array $songIds): array
    {
        $this->retag = RetagLockRace::start($this->schema, $artists, $genres, $songIds);

        return RetagLockRace::awaitLockWait($this->observer, $this->retag);
    }

    /** @return array<string, mixed> what the retag printed once it committed */
    private function finishRetag(): array
    {
        self::assertNotNull($this->retag);
        $retag = $this->retag;
        $this->retag = null;

        return ArtistImportIdentityRace::finish($retag, $this->observer);
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

    private function advisoryLocksHeldBy(int $pid): int
    {
        return (int) $this->observer->fetchOne(
            "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND granted AND pid = ?",
            [$pid],
        );
    }

    /**
     * Stores two songs, the one whose ID sorts last first, so a scan of the table meets them against
     * their ID order. With fresh statistics and index scans off, the retag's lock reads the songs in
     * that table order and locks them in ID order only by sorting them.
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
        $sorted = array_map(strval(...), $this->observer->fetchFirstColumn('SELECT id::text FROM songs ORDER BY id'));
        self::assertSame(array_reverse($sorted), array_map(strval(...), $this->observer->fetchFirstColumn('SELECT id::text FROM songs')), 'The table holds the songs against their ID order.');
        self::assertCount(2, $sorted);

        return [$sorted[0], $sorted[1]];
    }
}
