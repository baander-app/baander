<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\AlbumCreditRace;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\GenreImportRace;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An import decides an album's artist credit after its songs are committed, under a lock on the
 * album's row: the album is credited only when it has songs and no credit, from its album-artist
 * tag, else from the artist all its songs' primary credits share. Two imports deciding at once take
 * turns, so the second finds the first one's credit and adds none. The tables copy the migrated
 * ones with their unique and B-tree indexes but not their PGroonga indexes, foreign keys or
 * triggers. Advisory locks span the database, so every artist name is random.
 */
final class AlbumCreditRaceTest extends TestCase
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

        $this->tag = bin2hex(random_bytes(4));
        $this->schema = 'album_credit_' . bin2hex(random_bytes(8));
        $this->observer = ArtistImportIdentityRace::connect('public');
        self::assertStringStartsWith('18.', (string) $this->observer->fetchOne('SHOW server_version'));
        // The reads after the row lock see a competing commit only under READ COMMITTED.
        self::assertSame('read committed', $this->observer->fetchOne('SHOW default_transaction_isolation'));
        $this->observer->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->observer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        foreach (['artists', 'albums', 'songs', 'artist_song', 'artist_album'] as $table) {
            ArtistImportIdentityRace::copyTableWithoutPgroonga($this->observer, $table);
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
    public static function whichDecidesFirst(): iterable
    {
        yield 'the album-artist tag first' => [true];
        yield 'the shared track artist first' => [false];
    }

    /**
     * The two imports would credit different artists, so a decision that did not wait for the
     * other's would leave two credits rather than fail on the credit's unique key.
     */
    #[DataProvider('whichDecidesFirst')]
    public function testTwoImportsDecidingOneAlbumAtOnceLeaveOneCredit(bool $tagFirst): void
    {
        $album = $this->storeAlbum();
        $disturbed = $this->storeArtist($this->tagged('Disturbed'));
        $this->storeSong($album, [[$disturbed, 'primary', 'Disturbed']]);
        $this->storeSong($album, [[$disturbed, 'primary', 'Disturbed']]);
        $tagged = [$this->tagged('Post Malone'), [], 'Post Malone'];
        [$first, $second] = $tagFirst ? [$tagged, null] : [null, $tagged];

        $this->writer->beginTransaction();
        AlbumCreditRace::decide($this->manager, $album, $first);
        $child = AlbumCreditRace::start($this->schema, $album, $second);
        GenreImportRace::awaitLockWait($this->observer, $child);
        $this->writer->commit();

        self::assertSame(['decided' => true], ArtistImportIdentityRace::finish($child, $this->observer));
        $postMalone = $this->artistsNamed($this->tagged('Post Malone'));
        if ($tagFirst) {
            self::assertCount(1, $postMalone);
            self::assertSame([[$postMalone[0], 'primary', 'Post Malone']], $this->albumCredits($album));
        } else {
            self::assertSame([[$disturbed, 'primary', 'Disturbed']], $this->albumCredits($album));
            self::assertSame([], $postMalone, 'The second import found the credit before resolving its tag.');
        }
    }

    public function testASongAddedToTheAlbumDoesNotWaitForItsCreditDecision(): void
    {
        $this->observer->executeStatement('ALTER TABLE songs ADD CONSTRAINT fk_songs_album_id FOREIGN KEY (album_id) REFERENCES albums (id)');
        $album = $this->storeAlbum();
        $disturbed = $this->storeArtist($this->tagged('Disturbed'));
        $this->storeSong($album, [[$disturbed, 'primary', 'Disturbed']]);

        $this->writer->beginTransaction();
        AlbumCreditRace::decide($this->manager, $album, null);
        // The song's foreign key check locks the album's row FOR KEY SHARE, which the decision's
        // lock does not conflict with; under a FOR UPDATE lock the insert would time out.
        $this->observer->executeStatement("SET lock_timeout = '2s'");
        try {
            $this->storeSong($album, []);
        } finally {
            $this->observer->executeStatement('RESET lock_timeout');
        }
        $this->writer->commit();

        self::assertSame(2, (int) $this->observer->fetchOne('SELECT count(*) FROM songs WHERE album_id = ?', [$album]));
        self::assertSame([[$disturbed, 'primary', 'Disturbed']], $this->albumCredits($album));
    }

    /**
     * @param list<list<array{0: string, 1: string, 2: string}>> $songs        each song's credits: artist, role and credited name
     * @param list<array{0: string, 1: string, 2: string}>       $albumCredits the album's credits before the decision
     * @param array{0: string, 1: string}|null                   $albumArtist  the tagged album artist and its credited name
     * @param list<array{0: string, 1: string, 2: string}>       $expected     the album's credits after it; an artist is a key of
     *                                                                         artistNames(), and a credited name "@A" is artist A's own name
     */
    #[DataProvider('albums')]
    public function testTheAlbumIsCreditedFromItsTagElseFromTheArtistItsSongsShare(array $songs, array $albumCredits, ?array $albumArtist, array $expected): void
    {
        $album = $this->storeAlbum();
        $artists = [];
        foreach (['A', 'B'] as $key) {
            $artists[$key] = $this->storeArtist($this->tagged($key));
        }
        $artistOf = static fn (array $credit): array => [$artists[$credit[0]], $credit[1], $credit[2]];
        foreach ($songs as $credits) {
            $this->storeSong($album, array_map($artistOf, $credits));
        }
        foreach ($albumCredits as $credit) {
            $this->storeAlbumCredit($album, ...$artistOf($credit));
        }

        $this->writer->beginTransaction();
        AlbumCreditRace::decide($this->manager, $album, $albumArtist === null ? null : [$this->tagged($albumArtist[0]), [], $albumArtist[1]]);
        $this->writer->commit();

        $artists['T'] = $this->artistsNamed($this->tagged('T'))[0] ?? 'none';
        self::assertSame(
            array_map(fn (array $credit): array => [
                $artists[$credit[0]],
                $credit[1],
                str_starts_with($credit[2], '@') ? $this->tagged(substr($credit[2], 1)) : $credit[2],
            ], $expected),
            $this->albumCredits($album),
        );
    }

    /** @return iterable<string, array{list<list<array{0: string, 1: string, 2: string}>>, list<array{0: string, 1: string, 2: string}>, array{0: string, 1: string}|null, list<array{0: string, 1: string, 2: string}>}> */
    public static function albums(): iterable
    {
        yield 'every song credits one artist under one name' => [
            [[['A', 'primary', 'A alias']], [['A', 'primary', 'A alias']]], [], null,
            [['A', 'primary', 'A alias']],
        ];
        yield 'the songs credit one artist under two names' => [
            [[['A', 'primary', 'A alias']], [['A', 'primary', 'A other alias']]], [], null,
            [['A', 'primary', '@A']],
        ];
        yield 'the songs credit two artists' => [
            [[['A', 'primary', 'A']], [['B', 'primary', 'B']]], [], null,
            [],
        ];
        yield 'a song has no credit' => [
            [[['A', 'primary', 'A']], []], [], null,
            [],
        ];
        yield 'a song has only a featured credit' => [
            [[['A', 'primary', 'A']], [['A', 'featured', 'A']]], [], null,
            [],
        ];
        yield 'a song credits a featured artist beside the shared one' => [
            [[['A', 'primary', 'A']], [['A', 'primary', 'A'], ['B', 'featured', 'B']]], [], null,
            [['A', 'primary', 'A']],
        ];
        yield 'the album-artist tag names an artist the songs do not' => [
            [[['A', 'primary', 'A']]], [], ['T', 'T; Guest'],
            [['T', 'primary', 'T; Guest']],
        ];
        yield 'an album without songs and with a tag' => [
            [], [], ['T', 'T'],
            [],
        ];
        yield 'an album with a hand-made credit' => [
            [[['A', 'primary', 'A']]], [['B', 'featured', 'B by hand']], ['T', 'T'],
            [['B', 'featured', 'B by hand']],
        ];
    }

    private function tagged(string $base): string
    {
        return $base . ' ' . $this->tag;
    }

    private function storeAlbum(): string
    {
        $id = (new Uuid())->toString();
        $this->observer->executeStatement(
            "INSERT INTO albums (id, public_id, library_id, title, type, created_at, updated_at) VALUES (:id, :publicId, :libraryId, 'Ten Thousand Fists', 'studio', now(), now())",
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
    private function storeSong(string $albumId, array $credits): void
    {
        $id = (new Uuid())->toString();
        $this->observer->executeStatement(
            "INSERT INTO songs (id, public_id, album_id, title, path, size, mime_type, created_at, updated_at) VALUES (:id, :publicId, :albumId, 'Song', :path, 1000000, 'audio/flac', now(), now())",
            ['id' => $id, 'publicId' => (new PublicId())->toString(), 'albumId' => $albumId, 'path' => '/music/' . $id . '.flac'],
        );
        foreach ($credits as [$artistId, $role, $creditedName]) {
            $this->observer->executeStatement(
                'INSERT INTO artist_song (id, artist_id, song_id, role, credited_name) VALUES (:id, :artistId, :songId, :role, :creditedName)',
                ['id' => (new Uuid())->toString(), 'artistId' => $artistId, 'songId' => $id, 'role' => $role, 'creditedName' => $creditedName],
            );
        }
    }

    private function storeAlbumCredit(string $albumId, string $artistId, string $role, string $creditedName): void
    {
        $this->observer->executeStatement(
            'INSERT INTO artist_album (id, artist_id, album_id, role, credited_name) VALUES (:id, :artistId, :albumId, :role, :creditedName)',
            ['id' => (new Uuid())->toString(), 'artistId' => $artistId, 'albumId' => $albumId, 'role' => $role, 'creditedName' => $creditedName],
        );
    }

    /** @return list<string> the IDs of the artists with the name */
    private function artistsNamed(string $name): array
    {
        return array_map(strval(...), $this->observer->fetchFirstColumn('SELECT id::text FROM artists WHERE name = ? ORDER BY created_at, id', [$name]));
    }

    /** @return list<array{0: string, 1: ?string, 2: string}> artist ID, role and credited name of each credit */
    private function albumCredits(string $albumId): array
    {
        return array_map(
            static fn (array $row): array => [(string) $row['artist_id'], $row['role'] === null ? null : (string) $row['role'], (string) $row['credited_name']],
            $this->observer->fetchAllAssociative('SELECT artist_id::text AS artist_id, role, credited_name FROM artist_album WHERE album_id = ? ORDER BY role, credited_name', [$albumId]),
        );
    }
}
