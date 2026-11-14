<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\Port\LibraryPortInterface;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Domain\Event\Outbox\RelayOutboxCommand;
use App\Shared\Domain\Event\Outbox\RelayOutboxHandler;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Tests\Support\FinalSchemaBootstrap;
use App\Transcode\Application\DTO\AudioProbeResult;
use App\Transcode\Application\Port\AudioProbePortInterface;
use App\Transcode\Application\Port\VideoProbePortInterface;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Scans tagged FLAC files through the console, edits the album and the credits as an administrator
 * would, rescans with --rescan and ingests the files again, all against PostgreSQL. The rescan
 * repairs the songs in their renamed album instead of creating an album from the old album tag.
 */
final class RenamedAlbumRescanTest extends KernelTestCase
{
    private const string ALBUM = 'Ten Thousand Fists';

    private string $libraryDirectory;
    private ?string $schema = null;
    private string $searchPath;
    /** The length the stubbed probe measures, in seconds. */
    private float $probedLength = 0.238;

    protected function setUp(): void
    {
        $this->libraryDirectory = sys_get_temp_dir() . '/baander-renamed-album-' . bin2hex(random_bytes(4));
        mkdir($this->libraryDirectory . '/' . self::ALBUM, 0o777, true);
        foreach (['01' => 'Ten Thousand Fists', '02' => 'Just Stop'] as $track => $title) {
            file_put_contents(
                sprintf('%s/%s/%s %s.flac', $this->libraryDirectory, self::ALBUM, $track, $title),
                self::taggedFlac(['TITLE' => $title, 'ALBUM' => self::ALBUM, 'ARTIST' => 'Disturbed', 'TRACKNUMBER' => $track]),
            );
        }
        self::bootKernel();
        $this->isolateSchema();
        $probe = $this->createStubForIntersectionOfInterfaces([AudioProbePortInterface::class, VideoProbePortInterface::class]);
        $probe->method('probeAudio')->willReturnCallback(fn (): AudioProbeResult => new AudioProbeResult('flac', 0, 'flac', 2, 44100, 900000, $this->probedLength));
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

    public function testARescanAfterAnAlbumTitleEditCreatesNoAlbumAndRepairsItsSongs(): void
    {
        $library = $this->createLibrary();
        $this->scanAndIngest($library);
        $album = $this->onlyAlbum($library);
        self::assertSame([
            ['01 Ten Thousand Fists.flac', 'Disturbed', 'primary', 'Disturbed'],
            ['02 Just Stop.flac', 'Disturbed', 'primary', 'Disturbed'],
        ], $this->credits());
        $songIds = $this->songIdsByFile();
        $disturbed = (string) $this->connection()->fetchOne("SELECT public_id FROM artists WHERE name = 'Disturbed'");

        // An administrator removes the first song's credit, turns the second song's credit into a
        // featured one, and renames the album.
        $this->runConsole('app:artist:song:remove', ['public-id' => $disturbed, 'song-id' => $songIds['01 Ten Thousand Fists.flac']]);
        $this->runConsole('app:artist:song:role', ['public-id' => $disturbed, 'song-id' => $songIds['02 Just Stop.flac'], 'role' => 'featured']);
        $this->runConsole('app:album:update', ['public-id' => $album->getPublicId()->toString(), '--title' => self::ALBUM . ' (Remastered)']);
        $this->probedLength = 241.65;

        $this->scanAndIngest($library, rescan: true);

        self::assertSame(
            [[$album->getId()->toString(), self::ALBUM . ' (Remastered)']],
            $this->connection()->fetchAllNumeric('SELECT id, title FROM albums WHERE library_id = ?', [$library->getId()->toString()]),
            'The rescan creates no album from the old album tag.',
        );
        self::assertSame($songIds, $this->songIdsByFile(), 'The rescan creates no song.');
        self::assertSame([
            ['01 Ten Thousand Fists.flac', 'Disturbed', 'primary', 'Disturbed'],
            ['02 Just Stop.flac', 'Disturbed', 'featured', 'Disturbed'],
        ], $this->credits(), 'The removed credit comes back; the edited one stays and gains none.');
        self::assertSame(
            [241.65, 241.65],
            array_map(floatval(...), $this->connection()->fetchFirstColumn('SELECT length FROM songs ORDER BY path')),
            'Each song is measured from its audio again.',
        );
    }

    private function createLibrary(): Library
    {
        $library = Library::create(
            'Renamed album',
            new LibrarySlug('renamed-album-' . bin2hex(random_bytes(4))),
            new LibraryPath($this->libraryDirectory),
            LibraryType::Music,
            FilesystemType::Local,
        );
        static::getContainer()->get(LibraryPortInterface::class)->save($library);

        return $library;
    }

    /**
     * Scans through the console, then hands each discovery of the library to the ingest handler.
     * The transport is emptied first and read through what was sent, since get() returns one
     * envelope at a time and an earlier ingest leaves its cover extraction queued in front.
     */
    private function scanAndIngest(Library $library, bool $rescan = false): void
    {
        $async = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        $async->reset();
        $this->runConsole('app:library:scan', ['library' => $library->getSlug()->toString(), '--rescan' => $rescan]);

        static::getContainer()->get(RelayOutboxHandler::class)(new RelayOutboxCommand());
        $discoveries = array_values(array_filter(
            array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $async->getSent()),
            static fn (object $message): bool => $message instanceof FilesDiscovered && $message->libraryId->equals($library->getId()),
        ));
        self::assertCount(1, $discoveries, 'The scan queued the album directory for ingestion.');
        static::getContainer()->get(FilesDiscoveredHandler::class)($discoveries[0]);
    }

    /** @param array<string, string|bool> $input */
    private function runConsole(string $name, array $input): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find($name));
        $exitCode = $tester->execute($input);
        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
    }

    private function onlyAlbum(Library $library): Album
    {
        $albums = static::getContainer()->get(AlbumPortInterface::class)->findByLibrary($library->getId());
        self::assertCount(1, $albums);

        return $albums[0];
    }

    /** @return array<string, string> each song's ID, by its file name */
    private function songIdsByFile(): array
    {
        $ids = [];
        foreach ($this->connection()->fetchAllNumeric('SELECT path, public_id FROM songs ORDER BY path') as [$path, $id]) {
            $ids[basename((string) $path)] = (string) $id;
        }

        return $ids;
    }

    /** @return list<array{string, string, string, string}> the file name, artist name, role and credited name of each song credit */
    private function credits(): array
    {
        return array_map(
            static fn (array $row): array => [basename((string) $row[0]), (string) $row[1], (string) $row[2], (string) $row[3]],
            $this->connection()->fetchAllNumeric(
                'SELECT s.path, a.name, c.role, c.credited_name FROM artist_song c JOIN artists a ON a.id = c.artist_id JOIN songs s ON s.id = c.song_id ORDER BY s.path, c.role',
            ),
        );
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    /**
     * A FLAC file of a STREAMINFO block and a VORBIS_COMMENT block, enough for the tag reader.
     *
     * @param array<string, string> $comments
     */
    private static function taggedFlac(array $comments): string
    {
        $streamInfo = pack('nn', 4096, 4096) . "\x00\x00\x00\x00\x00\x00" . pack('NN', 0x0AC442F0, 0x0006BAA8) . str_repeat("\x00", 16);
        $vendor = 'Baander test';
        $tags = pack('V', strlen($vendor)) . $vendor . pack('V', count($comments));
        foreach ($comments as $key => $value) {
            $entry = $key . '=' . $value;
            $tags .= pack('V', strlen($entry)) . $entry;
        }
        $block = static fn (int $type, string $data, bool $last): string => chr(($last ? 0x80 : 0x00) | $type) . substr(pack('N', strlen($data)), 1) . $data;

        return 'fLaC' . $block(0, $streamInfo, false) . $block(4, $tags, true);
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
