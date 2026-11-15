<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\AlbumMergeRace;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\MatchApplyRace;
use App\Tests\Fixtures\Catalog\RetagLockRace;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\TestCase;

/**
 * An album merge runs in one transaction. It locks both album rows in ID order, the source FOR
 * UPDATE and the target FOR NO KEY UPDATE, then the rows of every song the two albums hold, and
 * reads those songs only then. It therefore waits for a writer that holds one of them and merges
 * what that writer committed; a song stored for the source meanwhile waits and then fails instead of
 * being deleted with it; and a merge that fails before its commit leaves everything as it was. The
 * source's cover is discarded only after the commit. Each test holds one side open in the test
 * session while the other runs in a child process, waits until the child waits on a lock, then
 * commits. The tables copy the migrated ones without their PGroonga indexes or triggers; the
 * foreign keys of credits and of songs to their album are added back.
 */
final class AlbumMergeRaceTest extends TestCase
{
    /** The SQLSTATE of a NOWAIT lock another session holds. */
    private const string LOCK_NOT_AVAILABLE = '55P03';

    /** The SQLSTATE of an insert whose foreign key names a row that does not exist. */
    private const string FOREIGN_KEY_VIOLATION = '23503';

    private Connection $writer;
    private Connection $observer;
    private string $schema;
    private string $tag;
    /** @var list<array{process: resource, output: resource, application: string}> */
    private array $children = [];

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }

        $this->tag = random_bytes(4) |> bin2hex(...);
        $this->schema = 'album_merge_race_' . (random_bytes(8) |> bin2hex(...));
        $this->observer = ArtistImportIdentityRace::connect('public');
        self::assertStringStartsWith('18.', (string) $this->observer->fetchOne('SHOW server_version'));
        // The reads after each lock see a competing commit only under READ COMMITTED.
        self::assertSame('read committed', $this->observer->fetchOne('SHOW default_transaction_isolation'));
        $this->observer->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->observer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        AlbumMergeRace::copyTables($this->observer);

        $this->writer = ArtistImportIdentityRace::connect($this->schema);
    }

    protected function tearDown(): void
    {
        if (isset($this->writer)) {
            while ($this->writer->isTransactionActive()) {
                $this->writer->rollBack();
            }
            $this->writer->close();
        }
        foreach ($this->children as $child) {
            // The test failed while the child ran; its own failure is the one to report.
            try {
                ArtistImportIdentityRace::finish($child);
            } catch (\RuntimeException|\JsonException) {
            }
        }
        if (isset($this->observer)) {
            $this->observer->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->observer->close();
        }
        $coverDirectory = isset($this->tag) ? AlbumMergeRace::storage() . '/images/album/' . $this->tag : null;
        if ($coverDirectory !== null && is_dir($coverDirectory)) {
            array_map(unlink(...), glob($coverDirectory . '/*') ?: []);
            rmdir($coverDirectory);
        }
    }

    /**
     * The merge fails in its transaction after it moved the songs, removed the duplicate, moved the
     * source's favorites and plays, deleted the source and saved the target, before the commit. The
     * source keeps its songs, its cover, whose record and file are both still there, the favorite a
     * user holds of it and the plays of its song, and the target is as it was.
     */
    public function testAMergeThatFailsBeforeItsCommitLeavesTheAlbumsTheirSongsTheCoverFavoritesAndPlaysAsTheyWere(): void
    {
        $seed = AlbumMergeRace::seed($this->observer, $this->tag);
        $listener = (new Uuid())->toString();
        $favorite = AlbumMergeRace::favorite($this->observer, $listener, $seed['source']);
        $play = AlbumMergeRace::play($this->observer, $listener, $seed['moved'], $seed['source']);
        $failure = null;

        try {
            AlbumMergeRace::service(ArtistImportIdentityRace::entityManager($this->writer), static function (): never {
                throw new \RuntimeException('The merge failed before its commit.');
            })->mergeAlbums(Uuid::fromString($seed['target']), Uuid::fromString($seed['source']));
        } catch (\RuntimeException $caught) {
            $failure = $caught;
        }

        self::assertSame($seed['cover'], $this->observer->fetchOne('SELECT cover_image_id FROM albums WHERE id = ?', [$seed['source']]), 'The source keeps its cover.');
        self::assertSame(
            [$seed['kept'] => $seed['target'], $seed['moved'] => $seed['source'], $seed['duplicate'] => $seed['source']],
            $this->albumsOfSongs([$seed['kept'], $seed['moved'], $seed['duplicate']]),
        );
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM images WHERE id = ?', [$seed['cover']]), 'The cover\'s record is there.');
        self::assertFileExists($seed['coverFile'], 'The cover\'s file is there.');
        self::assertSame('[]', $this->observer->fetchOne('SELECT merged_from::text FROM albums WHERE id = ?', [$seed['target']]), 'The target records no merge.');
        self::assertSame([$favorite => $this->publicIdOf($seed['source'])], $this->albumFavoritesOf($listener), 'The favorite still names the source.');
        self::assertSame($seed['source'], $this->observer->fetchOne('SELECT album_id FROM media_activities WHERE id = ?', [$play]), 'The plays still count for the source.');
        self::assertSame('The merge failed before its commit.', $failure?->getMessage());
    }

    /**
     * A user who favorited the source favorites the target while the merge runs, and has not
     * committed when the merge moves the favorites. The merge waits for that favorite, and once it
     * is committed leaves the user the one favorite of the target and completes, rather than
     * failing on the key that allows a user one favorite of an album.
     */
    public function testAUserWhoFavoritesTheTargetWhileTheMergeMovesTheirFavoriteOfTheSourceEndsWithOneAndTheMergeCompletes(): void
    {
        $seed = AlbumMergeRace::seed($this->observer, $this->tag);
        $listener = (new Uuid())->toString();
        AlbumMergeRace::favorite($this->observer, $listener, $seed['source']);
        $this->writer->beginTransaction();
        $favoriteOfTarget = AlbumMergeRace::favorite($this->writer, $listener, $seed['target'], '2026-11-15 12:00:00+00');

        $merge = $this->startChild('merge', ['target' => $seed['target'], 'source' => $seed['source']]);
        $waiting = RetagLockRace::awaitLockWait($this->observer, $merge);

        self::assertSame('transactionid', $waiting['waitsOn'], 'The merge waits for the favorite of the target to commit.');
        $this->writer->commit();

        self::assertSame(['merged' => $seed['target']], $this->finish($merge));
        self::assertSame([$favoriteOfTarget => $this->publicIdOf($seed['target'])], $this->albumFavoritesOf($listener));
    }

    /**
     * The test holds a source song's row. The merge waits for it, and by then holds both album
     * rows: the source so that nothing may refer to it anew, the target only against other writers
     * of it. Once the test commits a new title, the merge moves the song with that title, deletes
     * the source and then discards the source's cover.
     */
    public function testAMergeWaitsForASourceSongsRowHoldingBothAlbumRowsAndMovesTheSongAsCommitted(): void
    {
        $seed = AlbumMergeRace::seed($this->observer, $this->tag);
        $this->holdSong($seed['moved']);

        $merge = $this->startChild('merge', ['target' => $seed['target'], 'source' => $seed['source']]);
        $waiting = RetagLockRace::awaitLockWait($this->observer, $merge);

        self::assertNotSame('advisory', $waiting['waitsOn'], 'The merge waits for the song\'s row.');
        self::assertTrue($this->isLocked('albums', $seed['source'], 'FOR KEY SHARE'), 'It holds the source against anything referring to it.');
        self::assertTrue($this->isLocked('albums', $seed['target'], 'FOR NO KEY UPDATE'), 'It holds the target against its other writers.');
        self::assertFalse($this->isLocked('albums', $seed['target'], 'FOR KEY SHARE'), 'A song may still be stored for the target.');
        $this->writer->executeStatement("UPDATE songs SET title = 'Something (2019 Mix)' WHERE id = ?", [$seed['moved']]);
        $this->writer->commit();

        self::assertSame(['merged' => $seed['target']], $this->finish($merge));
        self::assertSame(
            ['album_id' => $seed['target'], 'title' => 'Something (2019 Mix)'],
            $this->observer->fetchAssociative('SELECT album_id, title FROM songs WHERE id = ?', [$seed['moved']]),
        );
        self::assertSame([$seed['kept'] => $seed['target'], $seed['moved'] => $seed['target']], $this->albumsOfSongs([$seed['kept'], $seed['moved'], $seed['duplicate']]));
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM albums WHERE id = ?', [$seed['source']]));
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM images WHERE id = ?', [$seed['cover']]), 'The cover\'s record is discarded after the commit.');
        self::assertFileDoesNotExist($seed['coverFile'], 'So is its file.');
    }

    /**
     * While the merge holds its locks and waits for a song the test holds, an import stores a song
     * for the source and another for the target. The first waits for the source's row and fails on
     * its foreign key once the merge deleted the source, rather than being deleted with it unseen;
     * the second is stored at once and stays on the target.
     */
    public function testASongStoredForTheSourceDuringTheMergeWaitsAndFailsWhileOneForTheTargetIsStoredAtOnce(): void
    {
        $seed = AlbumMergeRace::seed($this->observer, $this->tag);
        $this->holdSong($seed['moved']);
        $merge = $this->startChild('merge', ['target' => $seed['target'], 'source' => $seed['source']]);
        RetagLockRace::awaitLockWait($this->observer, $merge);

        $intoSource = $this->startChild('insert', ['album' => $seed['source']]);
        RetagLockRace::awaitLockWait($this->observer, $intoSource);
        $intoTarget = $this->finish($this->startChild('insert', ['album' => $seed['target']]));
        $this->writer->commit();

        self::assertSame(['merged' => $seed['target']], $this->finish($merge));
        self::assertSame(['sqlstate' => self::FOREIGN_KEY_VIOLATION], $this->finish($intoSource), 'The song stored for the source waited and then failed.');
        self::assertArrayHasKey('inserted', $intoTarget, 'The song stored for the target did not wait for the merge.');
        self::assertSame([$intoTarget['inserted'] => $seed['target']], $this->albumsOfSongs([(string) $intoTarget['inserted']]));
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM albums WHERE id = ?', [$seed['source']]));
    }

    /**
     * The admin's lookup loaded the target before another session matched it to a release, gave
     * it a label and committed. The merge reads the target again under its lock: it keeps the
     * release and the label, rather than filling the label from the source as the target it
     * looked up, which had none, would.
     */
    public function testATargetChangedAfterTheLookupBeforeTheMergeKeepsWhatWasCommitted(): void
    {
        $seed = AlbumMergeRace::seed($this->observer, $this->tag);
        $this->observer->executeStatement("UPDATE albums SET label = 'Apple' WHERE id = ?", [$seed['source']]);
        $manager = ArtistImportIdentityRace::entityManager($this->writer);
        self::assertNull(MatchApplyRace::services($manager, null)['albums']->findByUuid(Uuid::fromString($seed['target']))?->getLabel());
        $this->observer->executeStatement(
            "UPDATE albums SET release_mbid = ?, matched_at = now(), label = 'Parlophone' WHERE id = ?",
            [MatchApplyRace::RELEASE_ID, $seed['target']],
        );

        self::assertSame(['merged' => $seed['target']], AlbumMergeRace::merge($manager, $seed['target'], $seed['source']));

        self::assertSame(
            ['release_mbid' => MatchApplyRace::RELEASE_ID, 'label' => 'Parlophone'],
            $this->observer->fetchAssociative('SELECT release_mbid, label FROM albums WHERE id = ?', [$seed['target']]),
        );
    }

    /**
     * Two admins merge the same source at once, into two targets. The second merge waits for the
     * first one's locks; once that one committed, the source is gone and the second answers "not
     * found" without changing its target.
     */
    public function testTwoMergesOfOneSourceAtOnceMergeItOnceAndTheOtherIsNotFound(): void
    {
        $seed = AlbumMergeRace::seed($this->observer, $this->tag);
        $otherTarget = AlbumMergeRace::insertAlbum($this->observer, $seed['library'], 'Abbey Road (Deluxe)');
        $otherSong = AlbumMergeRace::insertSong($this->observer, $otherTarget, 'Golden Slumbers', 'golden-slumbers-' . $this->tag);
        $second = null;

        $first = AlbumMergeRace::merge(ArtistImportIdentityRace::entityManager($this->writer), $seed['target'], $seed['source'], function () use ($seed, $otherTarget, &$second): void {
            $second = $this->startChild('merge', ['target' => $otherTarget, 'source' => $seed['source']]);
            RetagLockRace::awaitLockWait($this->observer, $second);
        });

        self::assertSame(['merged' => $seed['target']], $first);
        self::assertNotNull($second);
        self::assertSame(['notFound' => 'Source album not found.'], $this->finish($second));
        self::assertSame([$seed['moved'] => $seed['target'], $otherSong => $otherTarget], $this->albumsOfSongs([$seed['moved'], $otherSong]));
        self::assertSame('[]', $this->observer->fetchOne('SELECT merged_from::text FROM albums WHERE id = ?', [$otherTarget]), 'The other target records no merge.');
    }

    /** Opens a transaction in the test session that holds the song's row as a writer of it does. */
    private function holdSong(string $songId): void
    {
        $this->writer->beginTransaction();
        $this->writer->executeQuery('SELECT id FROM songs WHERE id = ? FOR NO KEY UPDATE', [$songId])->free();
    }

    /**
     * @param 'merge'|'insert'     $mode
     * @param array<string, mixed> $payload
     *
     * @return array{process: resource, output: resource, application: string}
     */
    private function startChild(string $mode, array $payload): array
    {
        $child = AlbumMergeRace::start($this->schema, $mode, $payload);
        $this->children[] = $child;

        return $child;
    }

    /**
     * @param array{process: resource, output: resource, application: string} $child
     *
     * @return array<string, mixed> what the child printed once it ended
     */
    private function finish(array $child): array
    {
        $this->children = array_values(array_filter($this->children, static fn (array $running): bool => $running['application'] !== $child['application']));

        return ArtistImportIdentityRace::finish($child, $this->observer);
    }

    /**
     * @param list<string> $songIds
     *
     * @return array<string, string> the album of each song that still exists, by song ID in the order given
     */
    private function albumsOfSongs(array $songIds): array
    {
        $albums = [];
        foreach ($songIds as $songId) {
            $album = $this->observer->fetchOne('SELECT album_id FROM songs WHERE id = ?', [$songId]);
            if ($album !== false) {
                $albums[$songId] = (string) $album;
            }
        }

        return $albums;
    }

    /** @return array<string, string> the public ID of the album each of the user's album favorites names, by favorite ID */
    private function albumFavoritesOf(string $userId): array
    {
        $favorites = [];
        foreach ($this->observer->fetchAllNumeric("SELECT id, entity_public_id FROM user_favorites WHERE user_id = ? AND entity_type = 'album' ORDER BY id", [$userId]) as [$id, $album]) {
            $favorites[(string) $id] = (string) $album;
        }

        return $favorites;
    }

    private function publicIdOf(string $albumId): string
    {
        return (string) $this->observer->fetchOne('SELECT public_id FROM albums WHERE id = ?', [$albumId]);
    }

    /** Whether another session holds a lock on the row that a lock of $strength conflicts with. */
    private function isLocked(string $table, string $id, string $strength): bool
    {
        try {
            $this->observer->executeQuery(sprintf('SELECT id FROM %s WHERE id = ? %s NOWAIT', $table, $strength), [$id])->free();
        } catch (DriverException $e) {
            self::assertSame(self::LOCK_NOT_AVAILABLE, $e->getSQLState(), $e->getMessage());

            return true;
        }

        return false;
    }
}
