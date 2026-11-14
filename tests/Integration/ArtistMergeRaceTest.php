<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\ArtistMergeRace;
use App\Tests\Fixtures\Catalog\GenreImportRace;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An artist merge or removal deletes the artist's row last, and the database deletes its credits
 * with it. A credit another session inserts for the artist in the meantime must fail rather than
 * be deleted unseen, so the merge port locks the artist's row FOR UPDATE first: the insert's
 * foreign key check waits for the lock and then finds the artist gone. The lock also reports
 * whether the artist still has credits, read after the lock was granted. The tables copy the
 * migrated ones with their unique and B-tree indexes but not their PGroonga indexes or triggers;
 * the foreign keys of credits to artists are added back.
 */
final class ArtistMergeRaceTest extends TestCase
{
    private Connection $writer;
    private Connection $observer;
    private EntityManager $manager;
    private string $schema;
    private string $tag;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }

        $this->tag = random_bytes(4) |> bin2hex(...);
        $this->schema = 'artist_merge_' . (random_bytes(8) |> bin2hex(...));
        $this->observer = ArtistImportIdentityRace::connect('public');
        self::assertStringStartsWith('18.', (string) $this->observer->fetchOne('SHOW server_version'));
        // The credit count after the row lock sees a competing commit only under READ COMMITTED.
        self::assertSame('read committed', $this->observer->fetchOne('SHOW default_transaction_isolation'));
        $this->observer->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->observer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        // Every table the merge writes, so none of its statements reaches the shared public schema.
        foreach (['artists', 'albums', 'songs', 'artist_song', 'artist_album', 'user_favorites', 'media_activities', 'recommendations'] as $table) {
            ArtistImportIdentityRace::copyTableWithoutPgroonga($this->observer, $table);
        }
        foreach (['artist_song', 'artist_album'] as $table) {
            $this->observer->executeStatement(sprintf(
                'ALTER TABLE %1$s ADD CONSTRAINT fk_%1$s_artist_id FOREIGN KEY (artist_id) REFERENCES artists (id) ON DELETE CASCADE',
                $table,
            ));
        }

        $this->writer = ArtistImportIdentityRace::connect($this->schema);
        $this->manager = ArtistImportIdentityRace::entityManager($this->writer);
    }

    protected function tearDown(): void
    {
        if (isset($this->writer)) {
            while ($this->writer->isTransactionActive()) {
                $this->writer->rollBack();
            }
            $this->writer->close();
        }
        if (isset($this->observer)) {
            $this->observer->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->observer->close();
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function mergeOrRemoval(): iterable
    {
        yield 'a merge' => [true];
        yield 'a removal' => [false];
    }

    /**
     * Without the row lock the insert would commit at once, and the artist's delete would take
     * the new credit with it.
     */
    #[DataProvider('mergeOrRemoval')]
    public function testACreditInsertedWhileTheArtistsRowIsLockedFailsInsteadOfBeingDeletedWithIt(bool $merge): void
    {
        $album = $this->storeAlbum();
        $source = $this->tagged('HeadHunterz') |> $this->storeArtist(...);
        $target = $this->tagged('Headhunterz') |> $this->storeArtist(...);
        $creditedSong = $this->storeSong($album, $merge ? [[$source, 'primary', 'HeadHunterz']] : []);
        $guestSong = $this->storeSong($album, []);
        $merges = ArtistMergeRace::merges($this->manager);

        $this->writer->beginTransaction();
        self::assertEquals(
            [$source => $merge, $target => false],
            $merges->lock([Uuid::fromString($source), Uuid::fromString($target)]),
        );
        $child = ArtistMergeRace::start($this->schema, 'credit', $source, $guestSong);
        GenreImportRace::awaitLockWait($this->observer, $child);
        $removal = $merge
            ? $merges->merge(Uuid::fromString($source), Uuid::fromString($target))
            : (Uuid::fromString($source) |> $merges->remove(...));
        $this->writer->commit();

        self::assertSame(
            ['sqlstate' => '23503'],
            ArtistImportIdentityRace::finish($child, $this->observer),
            'The credit waited for the artist\'s row and then failed on its foreign key.',
        );
        self::assertSame([], $removal->imagesToDiscard);
        self::assertSame([$target], $this->artistIds());
        self::assertSame($merge ? [[$creditedSong, $target, 'primary', 'HeadHunterz']] : [], $this->songCredits());
    }

    /**
     * The credit's foreign key check holds the artist's row FOR KEY SHARE until the credit
     * commits, so the lock waits for it. A credit count in the locking statement would read the
     * snapshot taken before the wait and miss the credit.
     */
    public function testTheLockSeesACreditCommittedWhileItWaitedForTheArtistsRow(): void
    {
        $album = $this->storeAlbum();
        $artist = $this->tagged('Post Malone, Luke Combs') |> $this->storeArtist(...);
        $song = $this->storeSong($album, []);

        $this->writer->beginTransaction();
        $this->insertCredit($this->writer, $artist, $song, 'primary', 'Post Malone, Luke Combs');
        $child = ArtistMergeRace::start($this->schema, 'lock', $artist);
        GenreImportRace::awaitLockWait($this->observer, $child);
        $this->writer->commit();

        self::assertSame([$artist => true], ArtistImportIdentityRace::finish($child, $this->observer));
    }

    private function tagged(string $base): string
    {
        return $base . ' ' . $this->tag;
    }

    private function storeAlbum(): string
    {
        $id = (new Uuid())->toString();
        $this->observer->executeStatement(
            "INSERT INTO albums (id, public_id, library_id, title, type, created_at, updated_at) VALUES (:id, :publicId, :libraryId, 'F-1 Trillion', 'album', now(), now())",
            ['id' => $id, 'publicId' => (new PublicId())->toString(), 'libraryId' => (new Uuid())->toString()],
        );

        return $id;
    }

    private function storeArtist(string $name): string
    {
        $id = (new Uuid())->toString();
        $this->observer->executeStatement(
            'INSERT INTO artists (id, public_id, name, created_at, updated_at) VALUES (:id, :publicId, :name, now(), now())',
            ['id' => $id, 'publicId' => (new PublicId())->toString(), 'name' => $name],
        );

        return $id;
    }

    /** @param list<array{0: string, 1: string, 2: string}> $credits artist ID, role and credited name */
    private function storeSong(string $albumId, array $credits): string
    {
        $id = (new Uuid())->toString();
        $this->observer->executeStatement(
            "INSERT INTO songs (id, public_id, album_id, title, path, size, mime_type, created_at, updated_at) VALUES (:id, :publicId, :albumId, 'Song', :path, 1000000, 'audio/flac', now(), now())",
            ['id' => $id, 'publicId' => (new PublicId())->toString(), 'albumId' => $albumId, 'path' => '/music/' . $id . '.flac'],
        );
        foreach ($credits as [$artistId, $role, $creditedName]) {
            $this->insertCredit($this->observer, $artistId, $id, $role, $creditedName);
        }

        return $id;
    }

    private function insertCredit(Connection $connection, string $artistId, string $songId, string $role, string $creditedName): void
    {
        $connection->executeStatement(
            'INSERT INTO artist_song (id, artist_id, song_id, role, credited_name) VALUES (:id, :artistId, :songId, :role, :creditedName)',
            ['id' => (new Uuid())->toString(), 'artistId' => $artistId, 'songId' => $songId, 'role' => $role, 'creditedName' => $creditedName],
        );
    }

    /** @return list<string> */
    private function artistIds(): array
    {
        return $this->observer->fetchFirstColumn('SELECT id::text FROM artists ORDER BY id') |> (static fn (array $ids): array => array_map(strval(...), $ids));
    }

    /** @return list<array{0: string, 1: string, 2: ?string, 3: string}> song, artist, role and credited name of each song credit */
    private function songCredits(): array
    {
        return array_map(
            static fn (array $row): array => [(string) $row['song_id'], (string) $row['artist_id'], $row['role'] === null ? null : (string) $row['role'], (string) $row['credited_name']],
            $this->observer->fetchAllAssociative('SELECT song_id::text AS song_id, artist_id::text AS artist_id, role, credited_name FROM artist_song ORDER BY song_id, role'),
        );
    }
}
