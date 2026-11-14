<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\Port\ArtistIdentity;
use App\Catalog\Application\Query\MusicBrainzMatch\CatalogCredit;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchCatalogReadInterface;
use App\Catalog\Application\Query\MusicBrainzMatch\PredictedArtist;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Catalog\Infrastructure\Doctrine\Query\MatchCatalogReadQuery;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Support\FinalSchemaBootstrap;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Reads credits, genres and artists for a MusicBrainz comparison through the container's catalog
 * read service, against the migrated PostgreSQL schema in a schema of the test's own.
 */
final class MatchCatalogReadQueryTest extends KernelTestCase
{
    private const string POST_MALONE_ID = 'b1e26560-60e5-4236-bbdb-9aa5a8d5ee19';
    private const string LUKE_COMBS_ID = '23a4c7d5-1f3e-4b8f-9a61-5c0e2d7b4a10';
    private const string HARDY_ID = '4f0d8a2c-7b6e-4c1d-8e9f-a1b2c3d4e5f6';
    private const string MORGAN_WALLEN_ID = '6a7b8c9d-0e1f-4a2b-8c3d-4e5f6a7b8c9d';
    private const string ANOTHER_LUKE_COMBS_ID = '9d8c7b6a-5f4e-4d3c-8b2a-1f0e9d8c7b6a';

    private ?string $schema = null;
    private string $searchPath;
    private string $libraryId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->isolateSchema();
        $this->libraryId = $this->storeLibrary();
    }

    protected function tearDown(): void
    {
        if ($this->schema !== null) {
            $connection = $this->connection();
            $connection->executeStatement('SET search_path TO ' . $this->searchPath);
            $connection->executeStatement('DROP SCHEMA ' . $connection->quoteIdentifier($this->schema) . ' CASCADE');
        }
        static::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testTheContainerReadsTheCatalogThroughTheDoctrineQuery(): void
    {
        self::assertInstanceOf(MatchCatalogReadQuery::class, $this->reads());
    }

    public function testASongsCreditsComePrimaryFirstThenByCreditedNameAndArtist(): void
    {
        $album = $this->storeAlbum();
        [$mike] = $this->storeArtist('Mike');
        [$oscar, $oscarPublicId] = $this->storeArtist('Oscar Mike');
        [$alpha] = $this->storeArtist('Alpha');
        [$kilo] = $this->storeArtist('Kilo');
        [$charlie] = $this->storeArtist('Charlie');
        [$secondMike] = $this->storeArtist('Mike');
        $song = $this->storeSong($album, [
            [$alpha, 'featured', 'Alpha'],
            [$kilo, 'producer', 'Kilo'],
            [$oscar, 'primary', 'Oscar'],
            [$charlie, null, 'Charlie'],
            [$secondMike, 'primary', 'Mike'],
            [$mike, 'primary', 'Mike'],
        ]);
        $uncredited = $this->storeSong($album, []);
        $this->storeSong($album, [[$alpha, 'primary', 'Alpha']]);
        $mikes = [$mike, $secondMike];
        sort($mikes);

        $credits = $this->reads()->songCredits([new Uuid($song), new Uuid($uncredited)]);

        self::assertSame([$song], array_keys($credits), 'A song without credits and a song not asked for are absent.');
        self::assertSame(
            [
                [$mikes[0], 'primary', 'Mike'],
                [$mikes[1], 'primary', 'Mike'],
                [$oscar, 'primary', 'Oscar'],
                [$alpha, 'featured', 'Alpha'],
                [$charlie, null, 'Charlie'],
                [$kilo, 'producer', 'Kilo'],
            ],
            self::creditRows($credits[$song]),
        );
        $oscarCredit = $credits[$song][2];
        self::assertSame($oscarPublicId, $oscarCredit->artistPublicId);
        self::assertSame('Oscar Mike', $oscarCredit->artistName, 'The artist keeps its own name beside the credited one.');
        self::assertSame([], $this->reads()->songCredits([]));
    }

    public function testAnAlbumsCreditsComePrimaryFirstThenByCreditedName(): void
    {
        $album = $this->storeAlbum();
        $otherAlbum = $this->storeAlbum();
        [$postMalone] = $this->storeArtist('Post Malone');
        [$lukeCombs] = $this->storeArtist('Luke Combs');
        [$producer] = $this->storeArtist('Andrew Watt');
        $this->storeAlbumCredit($album, $producer, 'producer', 'Andrew Watt');
        $this->storeAlbumCredit($album, $lukeCombs, 'featured', 'Luke Combs');
        $this->storeAlbumCredit($album, $postMalone, 'primary', 'Post Malone');
        $this->storeAlbumCredit($otherAlbum, $lukeCombs, 'primary', 'Luke Combs');

        self::assertSame(
            [[$postMalone, 'primary', 'Post Malone'], [$producer, 'producer', 'Andrew Watt'], [$lukeCombs, 'featured', 'Luke Combs']],
            $this->reads()->albumCredits(new Uuid($album)) |> self::creditRows(...),
        );
        self::assertSame([], $this->reads()->albumCredits(new Uuid($this->storeAlbum())));
    }

    public function testAnAlbumsGenresComeSortedByName(): void
    {
        $album = $this->storeAlbum();
        $otherAlbum = $this->storeAlbum();
        $this->fileUnder($album, $this->storeGenre('Rock'));
        $this->fileUnder($album, $this->storeGenre('Country'));
        $this->fileUnder($album, $this->storeGenre('Hip Hop'));
        $this->fileUnder($otherAlbum, $this->storeGenre('Jazz'));

        self::assertSame(['Country', 'Hip Hop', 'Rock'], $this->reads()->albumGenres(new Uuid($album)));
        self::assertSame([], $this->reads()->albumGenres(new Uuid($this->storeAlbum())));
    }

    public function testArtistsCreditedElsewhereCountEverySongAndAlbumCreditOutsideTheGivenOnes(): void
    {
        $album = $this->storeAlbum();
        $otherAlbum = $this->storeAlbum();
        [$onlyHere] = $this->storeArtist('Post Malone, Luke Combs');
        [$alsoOnAnotherSong] = $this->storeArtist('Post Malone');
        [$onlyOnThisAlbum] = $this->storeArtist('Morgan Wallen');
        [$onAnotherAlbum] = $this->storeArtist('Blake Shelton');
        [$uncredited] = $this->storeArtist('Hardy');
        $first = $this->storeSong($album, [[$onlyHere, 'primary', 'Post Malone, Luke Combs'], [$alsoOnAnotherSong, 'featured', 'Post Malone']]);
        $second = $this->storeSong($album, [[$onlyHere, 'primary', 'Post Malone, Luke Combs']]);
        $this->storeSong($otherAlbum, [[$alsoOnAnotherSong, 'primary', 'Post Malone']]);
        $this->storeAlbumCredit($album, $onlyOnThisAlbum, 'primary', 'Morgan Wallen');
        $this->storeAlbumCredit($otherAlbum, $onAnotherAlbum, 'primary', 'Blake Shelton');
        $artists = array_map(static fn (string $id): Uuid => new Uuid($id), [$onlyHere, $alsoOnAnotherSong, $onlyOnThisAlbum, $onAnotherAlbum, $uncredited]);

        self::assertEqualsCanonicalizing(
            [$onlyHere, $alsoOnAnotherSong, $onlyOnThisAlbum, $onAnotherAlbum],
            $this->reads()->artistsCreditedElsewhere($artists, [], []),
            'Without songs or albums to leave out, every credited artist counts.',
        );
        self::assertEqualsCanonicalizing(
            [$alsoOnAnotherSong, $onAnotherAlbum],
            $this->reads()->artistsCreditedElsewhere($artists, [new Uuid($first), new Uuid($second)], [new Uuid($album)]),
        );
        self::assertEqualsCanonicalizing(
            [$alsoOnAnotherSong, $onlyOnThisAlbum, $onAnotherAlbum],
            $this->reads()->artistsCreditedElsewhere($artists, [new Uuid($first), new Uuid($second)], []),
            'An album credit counts unless its album is left out.',
        );
        self::assertSame([], $this->reads()->artistsCreditedElsewhere([], [new Uuid($first)], [new Uuid($album)]));
    }

    public function testAnIdentityResolvesToItsIdHolderElseTheOldestNamesakeWithoutAnIdElseANewArtist(): void
    {
        [$holder, $holderPublicId] = $this->storeArtist('Post Malone', self::POST_MALONE_ID);
        [$oldestLukeCombs, $oldestLukeCombsPublicId] = $this->storeArtist('Luke Combs');
        $this->storeArtist('Luke Combs');
        $this->storeArtist('Luke Combs', self::ANOTHER_LUKE_COMBS_ID);
        $posty = new ArtistIdentity('Posty', [self::POST_MALONE_ID]);
        $lukeCombs = new ArtistIdentity('Luke Combs', [self::LUKE_COMBS_ID]);
        $hardy = new ArtistIdentity('Hardy', [self::HARDY_ID]);

        $predicted = $this->reads()->predictArtists([$posty, $lukeCombs, $hardy]);

        self::assertSame([$posty->key(), $lukeCombs->key(), $hardy->key()], array_keys($predicted));
        self::assertSame([$holder, $holderPublicId, 'Post Malone', false], self::prediction($predicted[$posty->key()]), 'The ID holder wins whatever its name.');
        self::assertSame([$oldestLukeCombs, $oldestLukeCombsPublicId, 'Luke Combs', true], self::prediction($predicted[$lukeCombs->key()]), 'The oldest namesake without an ID takes the ID on.');
        self::assertSame([null, null, 'Hardy', false], self::prediction($predicted[$hardy->key()]));
        self::assertSame(
            [self::ANOTHER_LUKE_COMBS_ID, self::POST_MALONE_ID],
            $this->connection()->fetchFirstColumn('SELECT mbid FROM artists WHERE mbid IS NOT NULL ORDER BY mbid'),
            'A prediction writes nothing.',
        );
        self::assertSame(3, (int) $this->connection()->fetchOne("SELECT count(*) FROM artists WHERE name = 'Luke Combs'"));
        self::assertSame([], $this->reads()->predictArtists([]));
    }

    public function testTwoIdentitiesSharingOneIdResolveToTheSameArtist(): void
    {
        [$postMalone, $postMalonePublicId] = $this->storeArtist('Post Malone');
        $credited = new ArtistIdentity('Post Malone', [self::POST_MALONE_ID]);
        $alias = new ArtistIdentity('Posty', [self::POST_MALONE_ID]);
        $newArtist = new ArtistIdentity('Morgan Wallen', [self::MORGAN_WALLEN_ID]);
        $newArtistAlias = new ArtistIdentity('Morgan Wallen & Friends', [self::MORGAN_WALLEN_ID]);

        $predicted = $this->reads()->predictArtists([$credited, $alias, $newArtist, $newArtistAlias]);

        self::assertSame([$postMalone, $postMalonePublicId, 'Post Malone', true], self::prediction($predicted[$credited->key()]));
        self::assertSame(
            [$postMalone, $postMalonePublicId, 'Post Malone', true],
            self::prediction($predicted[$alias->key()]),
            'The artist that takes the ID on holds it for the second identity too.',
        );
        self::assertSame([null, null, 'Morgan Wallen', false], self::prediction($predicted[$newArtist->key()]));
        self::assertSame(
            [null, null, 'Morgan Wallen', false],
            self::prediction($predicted[$newArtistAlias->key()]),
            'One new artist, named by the first identity, stands for both.',
        );
    }

    public function testAPredictionReadsWhatIsCommittedRatherThanAnArtistTheUnitOfWorkHolds(): void
    {
        [$lukeCombs] = $this->storeArtist('Luke Combs');
        $loaded = $this->entityManager()->find(ArtistEntity::class, new Uuid($lukeCombs));
        self::assertInstanceOf(ArtistEntity::class, $loaded);
        $this->connection()->executeStatement('UPDATE artists SET mbid = ? WHERE id = ?', [self::LUKE_COMBS_ID, $lukeCombs]);
        $identity = new ArtistIdentity('Luke Combs', [self::LUKE_COMBS_ID]);

        $predicted = $this->reads()->predictArtists([$identity]);

        self::assertNull($loaded->getMbid(), 'The loaded artist still shows no ID.');
        self::assertFalse($predicted[$identity->key()]->takesId, 'The committed row already holds the ID.');
        self::assertSame($lukeCombs, $predicted[$identity->key()]->id?->toString());
    }

    /**
     * @param list<CatalogCredit> $credits
     *
     * @return list<array{string, string|null, string}> artist ID, role and credited name of each credit
     */
    private static function creditRows(array $credits): array
    {
        return array_map(static fn (CatalogCredit $credit): array => [$credit->artistId->toString(), $credit->role, $credit->creditedName], $credits);
    }

    /** @return array{string|null, string|null, string, bool} the artist's ID, public ID and name, and whether it takes the ID on */
    private static function prediction(PredictedArtist $artist): array
    {
        return [$artist->id?->toString(), $artist->publicId, $artist->name, $artist->takesId];
    }

    private function storeLibrary(): string
    {
        $id = (new Uuid())->toString();
        $slug = 'match-catalog-read-' . (random_bytes(4) |> bin2hex(...));
        $this->connection()->executeStatement(
            "INSERT INTO libraries (id, public_id, name, slug, path, type, created_at, updated_at) VALUES (?, ?, 'Country', ?, ?, 'music', clock_timestamp(), clock_timestamp())",
            [$id, (new PublicId())->toString(), $slug, '/' . $slug],
        );

        return $id;
    }

    private function storeAlbum(): string
    {
        $id = (new Uuid())->toString();
        $this->connection()->executeStatement(
            "INSERT INTO albums (id, public_id, library_id, title, type, created_at, updated_at) VALUES (?, ?, ?, 'F-1 Trillion: Long Bed', 'studio', clock_timestamp(), clock_timestamp())",
            [$id, (new PublicId())->toString(), $this->libraryId],
        );

        return $id;
    }

    /** @return array{string, string} the artist's ID and public ID */
    private function storeArtist(string $name, ?string $mbid = null): array
    {
        $id = (new Uuid())->toString();
        $publicId = (new PublicId())->toString();
        $this->connection()->executeStatement(
            'INSERT INTO artists (id, public_id, name, mbid, created_at, updated_at) VALUES (?, ?, ?, ?, clock_timestamp(), clock_timestamp())',
            [$id, $publicId, $name, $mbid],
        );

        return [$id, $publicId];
    }

    /** @param list<array{0: string, 1: string|null, 2: string}> $credits artist ID, role and credited name */
    private function storeSong(string $albumId, array $credits): string
    {
        $id = (new Uuid())->toString();
        $this->connection()->executeStatement(
            "INSERT INTO songs (id, public_id, album_id, title, path, size, mime_type, created_at, updated_at) VALUES (?, ?, ?, 'Pour Me A Drink', ?, 1000000, 'audio/flac', clock_timestamp(), clock_timestamp())",
            [$id, (new PublicId())->toString(), $albumId, '/music/' . $id . '.flac'],
        );
        foreach ($credits as [$artistId, $role, $creditedName]) {
            $this->connection()->executeStatement(
                'INSERT INTO artist_song (id, artist_id, song_id, role, credited_name) VALUES (?, ?, ?, ?, ?)',
                [(new Uuid())->toString(), $artistId, $id, $role, $creditedName],
            );
        }

        return $id;
    }

    private function storeAlbumCredit(string $albumId, string $artistId, string $role, string $creditedName): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO artist_album (id, artist_id, album_id, role, credited_name) VALUES (?, ?, ?, ?, ?)',
            [(new Uuid())->toString(), $artistId, $albumId, $role, $creditedName],
        );
    }

    private function storeGenre(string $name): string
    {
        $id = (new Uuid())->toString();
        $this->connection()->executeStatement(
            'INSERT INTO genres (id, public_id, name, slug, created_at, updated_at) VALUES (?, ?, ?, ?, clock_timestamp(), clock_timestamp())',
            [$id, (new PublicId())->toString(), $name, $name |> strtolower(...) |> (static fn (string $lower): string => str_replace(' ', '-', $lower))],
        );

        return $id;
    }

    private function fileUnder(string $albumId, string $genreId): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO genre_album (id, genre_id, album_id) VALUES (?, ?, ?)',
            [(new Uuid())->toString(), $genreId, $albumId],
        );
    }

    private function reads(): MatchCatalogReadInterface
    {
        return static::getContainer()->get(MatchCatalogReadInterface::class);
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function isolateSchema(): void
    {
        $connection = $this->connection();
        $this->searchPath = (string) $connection->fetchOne('SHOW search_path');
        $this->schema = 'match_catalog_read_test_' . (random_bytes(8) |> bin2hex(...));
        $schema = $connection->quoteIdentifier($this->schema);
        $connection->executeStatement('CREATE SCHEMA ' . $schema);
        $connection->executeStatement('SET search_path TO ' . $schema . ', public');
        FinalSchemaBootstrap::bootstrap($connection);
    }
}
