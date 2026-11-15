<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Catalog;

use App\Catalog\Application\CommandHandler\MusicBrainzMatch\ApplyMatchHandler;
use App\Catalog\Application\CommandHandler\MusicBrainzMatch\MatchCovers;
use App\Catalog\Application\Query\MusicBrainzMatch\CompareMatchQuery;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchComparison;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\CompareMatchHandler;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\MatchComparisonBuilder;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\MatchSnapshots;
use App\Catalog\Application\Service\CoverImageDiscarder;
use App\Catalog\Application\Service\MusicBrainzMatch\CandidateScorer;
use App\Catalog\Application\Service\MusicBrainzMatch\TrackPairing;
use App\Catalog\Infrastructure\AlbumService;
use App\Catalog\Infrastructure\ArtistMergeService;
use App\Catalog\Infrastructure\ArtistService;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ArtistIdentityResolver;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ArtistMusicbrainzIdAssigner;
use App\Catalog\Infrastructure\Doctrine\Query\MatchCatalogReadQuery;
use App\Catalog\Infrastructure\Doctrine\Repository\AlbumMatchStateRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\AlbumRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\ArtistRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\GenreMovieRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\GenreRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\SongRepository;
use App\Catalog\Infrastructure\GenreService;
use App\Catalog\Infrastructure\SongService;
use App\Media\Infrastructure\Doctrine\Repository\ImageRepository;
use App\Media\Infrastructure\ImageService;
use App\Media\Infrastructure\Storage\FlysystemStorage;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtistCredit;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzCreditedArtist;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzMedium;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzTrack;
use App\Shared\Application\Exception\TransactionDeadlockException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Pagination\CursorCodec;
use App\Shared\Infrastructure\Pagination\CursorPaginator;
use App\Tests\Unit\Catalog\Application\QueryHandler\MusicBrainzMatch\FakeMusicBrainzLookup;
use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManager;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Throwable;

/**
 * The production match apply over a disposable schema, shared by MusicBrainzMatchApplyRaceTest,
 * MatchedSongRetagRaceTest and the child process they run (match-apply.php) as a second PostgreSQL
 * session. The release has two tracks: "Guy For That" by Post Malone featuring Luke Combs, and
 * "Pour Me A Drink" by Post Malone alone. Every name carries the test's tag, since advisory locks
 * span the database. Connections, entity managers and the child's result come from
 * {@see ArtistImportIdentityRace}.
 */
final class MatchApplyRace
{
    public const string RELEASE_ID = '0e1ea5e0-0000-4000-8000-00000000a001';

    /** The tables an apply reads or writes. */
    private const array TABLES = [
        'libraries', 'albums', 'songs', 'artists', 'artist_song', 'artist_album', 'genres', 'genre_album',
        'album_match_states', 'images', 'user_favorites', 'media_activities', 'recommendations',
    ];

    /**
     * Copies the tables into the connection's current schema without their PGroonga indexes, and
     * adds back the foreign keys of credits, which the row locks of the apply and of a competing
     * import meet on.
     */
    public static function copyTables(Connection $connection): void
    {
        foreach (self::TABLES as $table) {
            ArtistImportIdentityRace::copyTableWithoutPgroonga($connection, $table);
        }
        foreach (['artist_song' => 'song', 'artist_album' => 'album'] as $table => $item) {
            $connection->executeStatement(sprintf(
                'ALTER TABLE %1$s ADD CONSTRAINT fk_%1$s_artist_id FOREIGN KEY (artist_id) REFERENCES artists (id) ON DELETE CASCADE',
                $table,
            ));
            $connection->executeStatement(sprintf(
                'ALTER TABLE %1$s ADD CONSTRAINT fk_%1$s_%2$s_id FOREIGN KEY (%2$s_id) REFERENCES %2$ss (id) ON DELETE CASCADE',
                $table,
                $item,
            ));
        }
    }

    /** The name of the artist "Post Malone" in the test tagged $tag. */
    public static function postMalone(string $tag): string
    {
        return 'Post Malone ' . $tag;
    }

    /** The name of the artist "Luke Combs" in the test tagged $tag. */
    public static function lukeCombs(string $tag): string
    {
        return 'Luke Combs ' . $tag;
    }

    /** The tag text of the duet and the name of the artist the scan made of it. */
    public static function duet(string $tag): string
    {
        return self::postMalone($tag) . ', ' . self::lukeCombs($tag);
    }

    public static function release(string $tag): MusicBrainzRelease
    {
        $postMalone = self::postMalone($tag) |> self::credit(...);
        $tracks = [
            new MusicBrainzTrack('7a1c0de0-0000-4000-8000-00000000a001', 1, '1', 'Guy For That', 180_000, self::recordingId(1), self::credit(self::postMalone($tag), self::lukeCombs($tag))),
            new MusicBrainzTrack('7a1c0de0-0000-4000-8000-00000000a002', 2, '2', 'Pour Me A Drink', 200_000, self::recordingId(2), $postMalone),
        ];

        return new MusicBrainzRelease(
            id: self::RELEASE_ID,
            title: 'F-1 Trillion',
            artistCredit: $postMalone,
            date: '2024-08-16',
            country: null,
            status: 'Official',
            disambiguation: null,
            barcode: null,
            labels: [],
            media: [new MusicBrainzMedium(1, 'Digital Media', null, $tracks)],
            releaseGroup: null,
            genres: [],
            hasFrontCover: false,
        );
    }

    public static function recordingId(int $position): string
    {
        return sprintf('5ec0bd00-0000-4000-8000-%012d', $position);
    }

    /** A UUID-shaped MusicBrainz artist ID that stays the same for one name. */
    public static function artistId(string $name): string
    {
        $hash = md5($name);

        return sprintf('%s-%s-4%s-8%s-%s', substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 13, 3), substr($hash, 17, 3), substr($hash, 20, 12));
    }

    public static function lookup(string $tag): FakeMusicBrainzLookup
    {
        $lookup = new FakeMusicBrainzLookup();
        $lookup->releases[self::RELEASE_ID] = self::release($tag);

        return $lookup;
    }

    /**
     * The album "F-1 Trillion" with the release's two songs: the duet credited to the scan's
     * combined artist, the other to an ID-less Post Malone, who is also the album's artist; and a
     * song of another album without credits.
     *
     * @return array{album: string, albumPublicId: string, duetSong: string, soloSong: string, otherSong: string, duetArtist: string, postMalone: string}
     */
    public static function seed(Connection $connection, string $tag): array
    {
        $library = (new Uuid())->toString();
        $connection->executeStatement(
            "INSERT INTO libraries (id, public_id, name, slug, path, type, created_at, updated_at) VALUES (?, ?, 'Country', ?, ?, 'music', now(), now())",
            [$library, (new PublicId())->toString(), 'country-' . $tag, '/music/' . $tag],
        );
        [$album, $albumPublicId] = self::insertAlbum($connection, $library, 'F-1 Trillion');
        [$other] = self::insertAlbum($connection, $library, 'Austin');
        $postMalone = self::insertArtist($connection, self::postMalone($tag));
        $duetArtist = self::insertArtist($connection, self::duet($tag));
        $connection->executeStatement(
            "INSERT INTO artist_album (id, artist_id, album_id, role, credited_name) VALUES (?, ?, ?, 'primary', ?)",
            [(new Uuid())->toString(), $postMalone, $album, self::postMalone($tag)],
        );

        return [
            'album' => $album,
            'albumPublicId' => $albumPublicId,
            'duetSong' => self::insertSong($connection, $album, 'Guy For That', 1, 181.5, [$duetArtist, self::duet($tag)]),
            'soloSong' => self::insertSong($connection, $album, 'Pour Me A Drink', 2, 201.5, [$postMalone, self::postMalone($tag)]),
            'otherSong' => self::insertSong($connection, $other, 'Wow', 1, 150.0, null),
            'duetArtist' => $duetArtist,
            'postMalone' => $postMalone,
        ];
    }

    /**
     * The apply handler over the manager. $beforeCommit runs inside the transaction once the apply
     * wrote everything, while it holds every lock, before the commit.
     */
    public static function handler(EntityManager $manager, FakeMusicBrainzLookup $lookup, ?Closure $beforeCommit = null): ApplyMatchHandler
    {
        $services = self::services($manager, $beforeCommit);
        $storage = new FlysystemStorage(sys_get_temp_dir() . '/baander-match-apply-race');

        return new ApplyMatchHandler(
            musicBrainz: $lookup,
            scorer: new CandidateScorer(new TrackPairing()),
            snapshots: $services['snapshots'],
            comparisons: $services['comparisons'],
            covers: new MatchCovers($lookup, $storage, new NullLogger()),
            albums: $services['albums'],
            songs: $services['songs'],
            artists: $services['artists'],
            genres: new GenreService(new GenreRepository($manager), new GenreMovieRepository($manager)),
            artistIdentities: new ArtistIdentityResolver($manager),
            merges: $services['merges'],
            matchStates: new AlbumMatchStateRepository($manager->getConnection()),
            images: $services['images'],
            discarder: $services['discarder'],
            transaction: $services['transaction'],
            clock: new NativeClock(),
            logger: new NullLogger(),
        );
    }

    /** The comparison of the album with the release, as the admin sees it before applying. */
    public static function compare(EntityManager $manager, FakeMusicBrainzLookup $lookup, string $albumPublicId): MatchComparison
    {
        $services = self::services($manager, null);

        return new CompareMatchHandler($lookup, new CandidateScorer(new TrackPairing()), $services['snapshots'], $services['comparisons'])(
            new CompareMatchQuery(MatchItemType::Album, $albumPublicId, self::RELEASE_ID),
        );
    }

    /**
     * Starts the child session. `apply` applies the release to the album with the selection given and
     * prints the result's outcome, merged and removed artists, or {"conflict": reason}; `import`
     * resolves an artist by its name alone and credits it on a song in one transaction, as an import
     * does, and prints {"artist": ID}, or {"sqlstate": "..."} when that fails; `retag` takes a
     * retag's locks of a song and the artists its tags name, reads the song as the retag then reads
     * it, and prints its matched fields and credits.
     *
     * @param 'apply'|'import'|'retag' $mode
     * @param array<string, mixed>     $payload
     *
     * @return array{process: resource, output: resource, application: string}
     */
    public static function start(string $schema, string $mode, array $payload): array
    {
        $application = 'match_apply_' . (random_bytes(6) |> bin2hex(...));
        $output = tmpfile();
        if ($output === false) {
            throw new \RuntimeException('Cannot create the contender output.');
        }
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/match-apply.php', $schema, $application, $mode, json_encode($payload, JSON_THROW_ON_ERROR)],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
        );
        if (!is_resource($process)) {
            fclose($output);
            throw new \RuntimeException('Cannot start the contender.');
        }

        return ['process' => $process, 'output' => $output, 'application' => $application];
    }

    /** @return list<array{string, string, string}> artist name, role and credited name of each credit of the song, primary first */
    public static function songCredits(Connection $connection, string $songId): array
    {
        return array_map(
            static fn (array $row): array => [(string) $row[0], (string) $row[1], (string) $row[2]],
            $connection->fetchAllNumeric(
                "SELECT a.name, c.role, c.credited_name FROM artist_song c JOIN artists a ON a.id = c.artist_id WHERE c.song_id = ? ORDER BY c.role <> 'primary', c.credited_name",
                [$songId],
            ),
        );
    }

    /**
     * The production catalog services over the manager, for this fixture and for the fixtures of
     * other catalog writers that race an apply. Their transaction port is the one transaction()
     * returns for $beforeCommit.
     *
     * @return array{albums: AlbumService, songs: SongService, artists: ArtistService, merges: ArtistMergeService, images: ImageService, discarder: CoverImageDiscarder, transaction: TransactionPortInterface, snapshots: MatchSnapshots, comparisons: MatchComparisonBuilder}
     */
    public static function services(EntityManager $manager, ?Closure $beforeCommit): array
    {
        $transaction = self::transaction($manager, $beforeCommit);
        $merges = ArtistMergeRace::merges($manager);
        $images = new ImageService(new ImageRepository($manager));
        $discarder = new CoverImageDiscarder($images, new FlysystemStorage(sys_get_temp_dir() . '/baander-match-apply-race'), new NullLogger());
        $albums = new AlbumService(new AlbumRepository($manager), $discarder, $transaction, new ArtistIdentityResolver($manager));
        $songs = new SongService(new SongRepository($manager, new CursorPaginator(), new CursorCodec(new JsonEncoder())), $manager->getConnection());
        $artists = new ArtistService(new ArtistRepository($manager), $transaction, new ArtistMusicbrainzIdAssigner($manager), new NullLogger(), $merges, $discarder);
        $catalog = new MatchCatalogReadQuery($manager);

        return [
            'albums' => $albums,
            'songs' => $songs,
            'artists' => $artists,
            'merges' => $merges,
            'images' => $images,
            'discarder' => $discarder,
            'transaction' => $transaction,
            'snapshots' => new MatchSnapshots($albums, $songs, $artists, $catalog),
            'comparisons' => new MatchComparisonBuilder($catalog, $artists),
        ];
    }

    /**
     * Runs an operation as the production transaction port does: in one transaction, flushed at the
     * end, the manager cleared when it fails, and a deadlock reported as TransactionDeadlockException.
     * $beforeCommit runs inside the outermost transaction once the operation's writes are flushed,
     * while every lock is held, before the commit.
     */
    public static function transaction(EntityManager $manager, ?Closure $beforeCommit): TransactionPortInterface
    {
        return new readonly class ($manager, $beforeCommit) implements TransactionPortInterface {
            public function __construct(
                private EntityManager $manager,
                private ?Closure $beforeCommit,
            ) {
            }

            public function run(callable $operation): mixed
            {
                $connection = $this->manager->getConnection();
                $connection->beginTransaction();
                try {
                    $result = $operation();
                    $this->manager->flush();
                    if ($this->beforeCommit !== null && $connection->getTransactionNestingLevel() === 1) {
                        ($this->beforeCommit)();
                    }
                    $connection->commit();

                    return $result;
                } catch (Throwable $error) {
                    $connection->rollBack();
                    if ($this->manager->isOpen()) {
                        $this->manager->clear();
                    }
                    throw $error instanceof DeadlockException ? new TransactionDeadlockException($error) : $error;
                }
            }
        };
    }

    private static function credit(string ...$names): MusicBrainzArtistCredit
    {
        $last = array_key_last($names);

        return new MusicBrainzArtistCredit(array_map(
            static fn (int $index, string $name): MusicBrainzCreditedArtist => new MusicBrainzCreditedArtist(self::artistId($name), $name, $index === $last ? '' : ' & ', $name, null),
            array_keys($names),
            $names,
        ));
    }

    /** @return array{string, string} the album's ID and public ID */
    private static function insertAlbum(Connection $connection, string $library, string $title): array
    {
        $id = (new Uuid())->toString();
        $publicId = (new PublicId())->toString();
        $connection->executeStatement(
            "INSERT INTO albums (id, public_id, library_id, title, type, created_at, updated_at) VALUES (?, ?, ?, ?, 'album', now(), now())",
            [$id, $publicId, $library, $title],
        );

        return [$id, $publicId];
    }

    private static function insertArtist(Connection $connection, string $name): string
    {
        $id = (new Uuid())->toString();
        $connection->executeStatement(
            'INSERT INTO artists (id, public_id, name, created_at, updated_at) VALUES (?, ?, ?, clock_timestamp(), clock_timestamp())',
            [$id, (new PublicId())->toString(), $name],
        );

        return $id;
    }

    /** @param array{string, string}|null $primary the primary artist's ID and the credited name */
    private static function insertSong(Connection $connection, string $album, string $title, int $track, float $length, ?array $primary): string
    {
        $id = (new Uuid())->toString();
        $connection->executeStatement(
            "INSERT INTO songs (id, public_id, album_id, title, path, size, mime_type, length, track, disc, hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1000000, 'audio/flac', ?, ?, 1, 'tagged', now(), now())",
            [$id, (new PublicId())->toString(), $album, $title, '/music/' . $id . '.flac', $length, $track],
        );
        if ($primary !== null) {
            $connection->executeStatement(
                "INSERT INTO artist_song (id, artist_id, song_id, role, credited_name) VALUES (?, ?, ?, 'primary', ?)",
                [(new Uuid())->toString(), $primary[0], $id, $primary[1]],
            );
        }

        return $id;
    }
}
