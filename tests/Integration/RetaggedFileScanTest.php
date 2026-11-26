<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\Port\LibraryPortInterface;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Domain\Event\Outbox\RelayOutboxCommand;
use App\Shared\Domain\Event\Outbox\RelayOutboxHandler;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Tests\Support\FinalSchemaBootstrap;
use App\Transcode\Application\DTO\AudioProbeResult;
use App\Transcode\Application\Port\AudioProbePortInterface;
use App\Transcode\Application\Port\VideoProbePortInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Scans a tagged FLAC file through the console, retags the file on disk and scans again, all against
 * PostgreSQL. The changed file updates the song stored at its path: the song keeps its ID and so its
 * play history, takes the new tags, and the new tag's credit replaces every credit it held. What a
 * MusicBrainz match set stays, unless the new tags carry a value for the field or credit their
 * artists by MusicBrainz ID.
 */
final class RetaggedFileScanTest extends KernelTestCase
{
    private const string ALBUM = 'Ten Thousand Fists';
    private const string FILE = '03 Track.flac';
    private const string POST_MALONE_ID = '5b7d9f1a-3c5e-4b7d-9f1a-3c5e7b9d1f3a';
    private const string LUKE_COMBS_ID = '8a0c2e4f-6b8d-4f0a-a2c4-6e8a0c2e4f6b';
    private const string RECORDING_ID = '0c9e5a4b-2d7f-4e1a-8b3c-6f5d4e3c2b1a';

    private string $libraryDirectory;
    private string $path;
    private ?string $schema = null;
    private string $searchPath;

    protected function setUp(): void
    {
        $this->libraryDirectory = sys_get_temp_dir() . '/baander-retagged-file-' . bin2hex(random_bytes(4));
        mkdir($this->libraryDirectory . '/' . self::ALBUM, 0o777, true);
        $this->path = $this->libraryDirectory . '/' . self::ALBUM . '/' . self::FILE;
        self::bootKernel();
        $this->isolateSchema();
        $probe = $this->createStubForIntersectionOfInterfaces([AudioProbePortInterface::class, VideoProbePortInterface::class]);
        $probe->method('probeAudio')->willReturn(new AudioProbeResult('flac', 0, 'flac', 2, 44100, 900000, 241.65));
        static::getContainer()->set(AudioProbePortInterface::class, $probe);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->libraryDirectory);
        if ($this->schema !== null) {
            $connection = static::getContainer()->get(Connection::class);
            $connection->executeStatement('SET search_path TO ' . $this->searchPath);
            $connection->executeStatement('DROP SCHEMA ' . $connection->quoteIdentifier($this->schema) . ' CASCADE');
        }
        static::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testARetaggedFileUpdatesItsSongAndTheNewTagsCreditReplacesAHandMadeOne(): void
    {
        $library = $this->createLibrary();
        $this->writeFile(['TITLE' => 'Track', 'ALBUM' => self::ALBUM, 'ARTIST' => 'Disturbed']);
        $this->scanAndIngest($library);
        [$songId, $songPublicId] = $this->onlySong();

        // An administrator credits the song to "X" in place of its tagged artist; a listener plays it.
        $this->runConsole('app:artist:create', ['name' => 'X']);
        $this->runConsole('app:artist:song:remove', ['public-id' => $this->artistPublicId('Disturbed'), 'song-id' => $songPublicId]);
        $this->runConsole('app:artist:song:add', ['public-id' => $this->artistPublicId('X'), 'song-id' => $songPublicId, 'role' => 'primary']);
        self::assertSame([[self::FILE, 'X', 'primary', 'X']], $this->credits());
        $activityId = $this->recordPlay($songId);

        $this->writeFile(['TITLE' => 'Stricken', 'ALBUM' => self::ALBUM, 'ARTIST' => 'Y']);
        $this->scanAndIngest($library);

        self::assertSame([$songId, $songPublicId], $this->onlySong(), 'No second song exists for the path.');
        self::assertSame('Stricken', $this->connection()->fetchOne('SELECT title FROM songs WHERE id = ?', [$songId]));
        self::assertSame([[self::FILE, 'Y', 'primary', 'Y']], $this->credits(), 'The song is credited only to the new tag\'s artist.');
        self::assertSame($songId, $this->connection()->fetchOne('SELECT song_id FROM media_activities WHERE id = ?', [$activityId]), 'The song keeps its play history.');
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT count(*) FROM albums WHERE library_id = ?', [$library->getId()->toString()]));
    }

    public function testARetagThatKeepsTheArtistGivesTheSongTheSameCreditAgain(): void
    {
        $library = $this->createLibrary();
        $this->writeFile(['TITLE' => 'Track', 'ALBUM' => self::ALBUM, 'ARTIST' => 'Disturbed']);
        $this->scanAndIngest($library);

        // The old credit and the new one share artist, song and role.
        $this->writeFile(['TITLE' => 'Stricken', 'ALBUM' => self::ALBUM, 'ARTIST' => 'Disturbed']);
        $this->scanAndIngest($library);

        self::assertSame([[self::FILE, 'Disturbed', 'primary', 'Disturbed']], $this->credits());
        [$songId] = $this->onlySong();
        self::assertSame('Stricken', $this->connection()->fetchOne('SELECT title FROM songs WHERE id = ?', [$songId]));
    }

    public function testARetaggedFileReturningToAPathWhoseSongWasMarkedUnavailableUpdatesThatSong(): void
    {
        $library = $this->createLibrary();
        $this->writeFile(['TITLE' => 'Track', 'ALBUM' => self::ALBUM, 'ARTIST' => 'Disturbed']);
        $this->scanAndIngest($library);
        [$songId, $songPublicId] = $this->onlySong();

        unlink($this->path);
        self::assertSame([], $this->scan($library), 'A scan without the file queues nothing.');
        self::assertFalse($this->indexedFile()['available'], 'The scan marked the song\'s file unavailable.');

        $this->writeFile(['TITLE' => 'Stricken', 'ALBUM' => self::ALBUM, 'ARTIST' => 'Y']);
        $this->scanAndIngest($library);

        self::assertSame([$songId, $songPublicId], $this->onlySong(), 'The returning file updates the song at its path.');
        self::assertSame('Stricken', $this->connection()->fetchOne('SELECT title FROM songs WHERE id = ?', [$songId]));
        self::assertSame([[self::FILE, 'Y', 'primary', 'Y']], $this->credits());
        $file = $this->indexedFile();
        self::assertTrue($file['available'], 'The song is available again.');
        self::assertSame($file['hash'], $this->connection()->fetchOne('SELECT hash FROM songs WHERE id = ?', [$songId]));
    }

    /**
     * A ReplayGain pass rewrites the file of a song a match credited to Post Malone and Luke Combs.
     * Its artist tag still names both in one value, and it carries no year.
     */
    public function testAMatchedSongKeepsItsCreditsAndItsMatchedYearThroughAReplayGainPass(): void
    {
        $library = $this->createLibrary();
        $tags = ['TITLE' => 'Pour Me A Drink', 'ALBUM' => self::ALBUM, 'ARTIST' => 'Post Malone, Luke Combs'];
        $this->writeFile($tags);
        $this->scanAndIngest($library);
        [$songId] = $this->onlySong();
        $this->match($songId);

        $this->writeFile([...$tags, 'REPLAYGAIN_TRACK_GAIN' => '-7.85 dB']);
        $this->scanAndIngest($library);

        self::assertSame([$songId], array_slice($this->onlySong(), 0, 1));
        self::assertSame(
            [[self::FILE, 'Luke Combs', 'featured', 'Luke Combs'], [self::FILE, 'Post Malone', 'primary', 'Post Malone, Luke Combs']],
            $this->credits(),
            'The primary and featured credits the match set stay.',
        );
        self::assertSame(
            ['year' => 2024, 'mbid' => self::RECORDING_ID, 'matched_fields' => ['year', 'credits']],
            $this->matchedSong($songId),
        );
        self::assertSame(0, (int) $this->connection()->fetchOne("SELECT count(*) FROM artists WHERE name = 'Post Malone, Luke Combs'"), 'The retag creates no artist for the tagged name.');
    }

    /**
     * Picard rewrites the file of a song a match credited, naming each artist with its MusicBrainz ID.
     * Those tags give the credits a match would, so they replace the match's.
     */
    public function testARetagWithAnIdPerArtistReplacesTheCreditsOfAMatchedSong(): void
    {
        $library = $this->createLibrary();
        $tags = ['TITLE' => 'Pour Me A Drink', 'ALBUM' => self::ALBUM, 'ARTIST' => 'Post Malone, Luke Combs'];
        $this->writeFile($tags);
        $this->scanAndIngest($library);
        [$songId] = $this->onlySong();
        $this->match($songId);

        $this->writeFile([
            ...$tags,
            'ARTIST' => 'Post Malone feat. Luke Combs',
            'ARTISTS' => ['Post Malone', 'Luke Combs'],
            'MUSICBRAINZ_ARTISTID' => [self::POST_MALONE_ID, self::LUKE_COMBS_ID],
        ]);
        $this->scanAndIngest($library);

        self::assertSame(
            [[self::FILE, 'Luke Combs', 'featured', 'Luke Combs'], [self::FILE, 'Post Malone', 'primary', 'Post Malone feat. Luke Combs']],
            $this->credits(),
        );
        self::assertSame(
            ['year' => 2024, 'mbid' => self::RECORDING_ID, 'matched_fields' => ['year']],
            $this->matchedSong($songId),
            'The credits are the tags\' now; the year, which the tags do not carry, is still the match\'s.',
        );
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT count(*) FROM artists WHERE name = 'Post Malone'"), 'The tags\' IDs name the artists the match credited.');
    }

    private function createLibrary(): Library
    {
        $library = Library::create(
            'Retagged file',
            new LibrarySlug('retagged-file-' . bin2hex(random_bytes(4))),
            new LibraryPath($this->libraryDirectory),
            LibraryType::Music,
            FilesystemType::Local,
        );
        static::getContainer()->get(LibraryPortInterface::class)->save($library);

        return $library;
    }

    /** @param array<string, string|list<string>> $comments */
    private function writeFile(array $comments): void
    {
        file_put_contents($this->path, self::taggedFlac($comments));
    }

    /**
     * Gives the song what a MusicBrainz match would: Post Malone as primary artist under the song's
     * artist text and Luke Combs as featured artist, both by ID, a year and a recording ID. The artist
     * the scan created for the joined name is merged into Post Malone, as the match would merge it.
     */
    private function match(string $songId): void
    {
        $connection = $this->connection();
        $joined = (string) $connection->fetchOne("SELECT id FROM artists WHERE name = 'Post Malone, Luke Combs'");
        $postMalone = $this->storeArtist('Post Malone', self::POST_MALONE_ID);
        $lukeCombs = $this->storeArtist('Luke Combs', self::LUKE_COMBS_ID);
        $connection->executeStatement('DELETE FROM artist_song WHERE song_id = ?', [$songId]);
        foreach ([[$postMalone, 'primary', 'Post Malone, Luke Combs'], [$lukeCombs, 'featured', 'Luke Combs']] as [$artistId, $role, $creditedName]) {
            $connection->executeStatement(
                'INSERT INTO artist_song (id, artist_id, song_id, role, credited_name) VALUES (?, ?, ?, ?, ?)',
                [Uuid::v7()->toString(), $artistId, $songId, $role, $creditedName],
            );
        }
        $connection->executeStatement('UPDATE artist_album SET artist_id = ? WHERE artist_id = ?', [$postMalone, $joined]);
        $connection->executeStatement('DELETE FROM artists WHERE id = ?', [$joined]);
        $connection->executeStatement(
            "UPDATE songs SET year = 2024, mbid = ?, matched_fields = CAST(? AS jsonb) WHERE id = ?",
            [self::RECORDING_ID, json_encode(['year', 'credits'], JSON_THROW_ON_ERROR), $songId],
        );
        // The first scan's artist, credits and song are still managed; the next scan reads them as stored.
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertSame(
            [[self::FILE, 'Luke Combs', 'featured', 'Luke Combs'], [self::FILE, 'Post Malone', 'primary', 'Post Malone, Luke Combs']],
            $this->credits(),
        );
    }

    private function storeArtist(string $name, string $musicbrainzId): string
    {
        $id = Uuid::v7()->toString();
        $this->connection()->executeStatement(
            'INSERT INTO artists (id, public_id, name, mbid, created_at, updated_at) VALUES (?, ?, ?, ?, clock_timestamp(), clock_timestamp())',
            [$id, (new PublicId())->toString(), $name, $musicbrainzId],
        );

        return $id;
    }

    /** @return array{year: int|null, mbid: string|null, matched_fields: list<string>} what a match may have set on the song */
    private function matchedSong(string $songId): array
    {
        $row = $this->connection()->fetchAssociative('SELECT year, mbid, matched_fields FROM songs WHERE id = ?', [$songId]);
        self::assertIsArray($row);

        return [
            'year' => $row['year'] === null ? null : (int) $row['year'],
            'mbid' => $row['mbid'] === null ? null : (string) $row['mbid'],
            'matched_fields' => json_decode((string) $row['matched_fields'], true, flags: JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * Scans through the console and relays the queued directories.
     *
     * The transport is emptied first and read through what was sent, since get() returns one
     * envelope at a time and an earlier ingest leaves its cover extraction queued in front.
     *
     * @return list<FilesDiscovered> the library's discoveries the scan queued
     */
    private function scan(Library $library): array
    {
        $async = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        $async->reset();
        $this->runConsole('app:library:scan', ['library' => $library->getSlug()->toString()]);

        static::getContainer()->get(RelayOutboxHandler::class)(new RelayOutboxCommand());

        return array_filter(
            array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $async->getSent()),
            static fn (object $message): bool => $message instanceof FilesDiscovered && $message->libraryId->equals($library->getId()),
        ) |> array_values(...);
    }

    /** Scans, then hands the album directory's discovery to the ingest handler. */
    private function scanAndIngest(Library $library): void
    {
        $discoveries = $this->scan($library);
        self::assertCount(1, $discoveries, 'The scan queued the album directory for ingestion.');
        static::getContainer()->get(FilesDiscoveredHandler::class)($discoveries[0]);
    }

    /** @param array<string, string> $input */
    private function runConsole(string $name, array $input): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find($name));
        $exitCode = $tester->execute($input);
        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
    }

    /** @return array{string, string} the ID and public ID of the only song */
    private function onlySong(): array
    {
        $songs = $this->connection()->fetchAllNumeric('SELECT id, public_id FROM songs');
        self::assertCount(1, $songs);

        return [(string) $songs[0][0], (string) $songs[0][1]];
    }

    private function artistPublicId(string $name): string
    {
        return (string) $this->connection()->fetchOne('SELECT public_id FROM artists WHERE name = ?', [$name]);
    }

    /** Records that a listener played the song, and returns the activity's ID. */
    private function recordPlay(string $songId): string
    {
        $userId = Uuid::v7()->toString();
        $this->connection()->executeStatement(
            "INSERT INTO users (id, public_id, name, email, password, created_at, updated_at) VALUES (?, ?, 'Listener', 'listener@baander.app', 'hash', clock_timestamp(), clock_timestamp())",
            [$userId, (new PublicId())->toString()],
        );
        $activityId = Uuid::v7()->toString();
        $this->connection()->executeStatement(
            "INSERT INTO media_activities (id, public_id, user_id, activity_type, song_id, play_count, love, created_at, updated_at) VALUES (?, ?, ?, 'play', ?, 3, false, clock_timestamp(), clock_timestamp())",
            [$activityId, (new PublicId())->toString(), $userId, $songId],
        );

        return $activityId;
    }

    /** @return list<array{string, string, string, string}> the file name, artist name, role and credited name of each song credit */
    private function credits(): array
    {
        return array_map(
            static fn (array $row): array => [basename((string) $row[0]), (string) $row[1], (string) $row[2], (string) $row[3]],
            $this->connection()->fetchAllNumeric(
                'SELECT s.path, a.name, c.role, c.credited_name FROM artist_song c JOIN artists a ON a.id = c.artist_id JOIN songs s ON s.id = c.song_id ORDER BY s.path, c.role, a.name',
            ),
        );
    }

    /** @return array{available: bool, hash: string} the file index's observation of the file's path */
    private function indexedFile(): array
    {
        $row = $this->connection()->fetchAssociative('SELECT available, hash FROM library_file_index WHERE path = ?', [$this->path]);
        self::assertIsArray($row);

        return ['available' => (bool) $this->connection()->convertToPHPValue($row['available'], 'boolean'), 'hash' => (string) $row['hash']];
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    /**
     * A FLAC file of a STREAMINFO block and a VORBIS_COMMENT block, enough for the tag reader. A field
     * given a list of values is written once per value.
     *
     * @param array<string, string|list<string>> $comments
     */
    private static function taggedFlac(array $comments): string
    {
        $streamInfo = pack('nn', 4096, 4096) . "\x00\x00\x00\x00\x00\x00" . pack('NN', 0x0AC442F0, 0x0006BAA8) . str_repeat("\x00", 16);
        $vendor = 'Baander test';
        $entries = [];
        foreach ($comments as $key => $values) {
            foreach ((array) $values as $value) {
                $entries[] = $key . '=' . $value;
            }
        }
        $tags = pack('V', strlen($vendor)) . $vendor . pack('V', count($entries));
        foreach ($entries as $entry) {
            $tags .= pack('V', strlen($entry)) . $entry;
        }
        $block = static fn (int $type, string $data, bool $last): string => chr(($last ? 0x80 : 0x00) | $type) . self::uint24(strlen($data)) . $data;

        return 'fLaC' . $block(0, $streamInfo, false) . $block(4, $tags, true);
    }

    private static function uint24(int $value): string
    {
        return substr(pack('N', $value), 1);
    }

    private function isolateSchema(): void
    {
        $connection = static::getContainer()->get(Connection::class);
        $this->searchPath = (string) $connection->fetchOne('SHOW search_path');
        $this->schema = 'scan_test_' . bin2hex(random_bytes(8));
        $schema = $connection->quoteIdentifier($this->schema);
        $connection->executeStatement('CREATE SCHEMA ' . $schema);
        $connection->executeStatement('SET search_path TO ' . $schema . ', public');
        FinalSchemaBootstrap::bootstrap($connection);
    }
}
