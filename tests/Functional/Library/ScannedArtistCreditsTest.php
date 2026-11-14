<?php

declare(strict_types=1);

namespace App\Tests\Functional\Library;

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Application\Port\LibraryPortInterface;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Lyrics\Application\Port\LrclibClientInterface;
use App\Lyrics\Infrastructure\Api\LrclibClient;
use App\Shared\Domain\Event\Outbox\RelayOutboxCommand;
use App\Shared\Domain\Event\Outbox\RelayOutboxHandler;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Tests\Fixtures\Audio\TaggedAudioFixture;
use App\Tests\Functional\Lyrics\FakeLrclibClient;
use App\Tests\Functional\TestCase;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Imports FLAC and MP3 files that FFmpeg encodes and that carry tags as tagging tools write them,
 * through the console scan, the outbox relay and the import handler, against PostgreSQL with the
 * real tag readers and the real audio probe. It checks the artists, the song and album credits
 * with their credited names and the song lengths a scan leaves, how a rescan repairs them, how a
 * retagged file updates its song, and what a lyrics fetch then sends to LRCLIB.
 */
final class ScannedArtistCreditsTest extends TestCase
{
    private const string HEADHUNTERZ_ID = '4d1e2a3b-5c6f-4a7b-8c9d-0e1f2a3b4c5d';
    private const string KREWELLA_ID = '7f8e9d0c-1b2a-4c3d-9e4f-5a6b7c8d9e0f';
    /** The start of an ID3 TXXX frame that holds MusicBrainz artist IDs, as Picard writes it. */
    private const string ID3_ARTIST_IDS = "MusicBrainz Artist Id\0";

    private string $libraryDirectory;
    private Library $library;
    private TaggedAudioFixture $audio;
    /** @var list<array{path: string, query: array<mixed>}> each request LRCLIB received, in order */
    private array $lrclibRequests = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The image's FFmpeg must encode MP3 itself; these tests make their MP3 files with LAME.
        self::assertStringContainsString(' libmp3lame ', TaggedAudioFixture::encoders(), 'FFmpeg lists no libmp3lame encoder.');

        $suffix = bin2hex(random_bytes(8));
        $this->libraryDirectory = sys_get_temp_dir() . '/baander-scanned-credits-' . $suffix;
        self::assertTrue(mkdir($this->libraryDirectory, 0o700));
        $this->audio = new TaggedAudioFixture($suffix);

        // The real client builds each request, so a test sees the query a lyrics fetch sends with
        // the length as the client rounds it. Its transport is mocked: nothing leaves the test, and
        // LRCLIB finds no record.
        $lrclib = new FakeLrclibClient();
        $lrclib->delegate = new LrclibClient(new MockHttpClient(function (string $method, string $url): MockResponse {
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            ksort($query);
            $this->lrclibRequests[] = ['path' => (string) parse_url($url, PHP_URL_PATH), 'query' => $query];

            return new MockResponse('{"code":404,"name":"TrackNotFound"}', ['http_code' => 404]);
        }), new NullLogger(), 'https://lrclib.baander.app');
        static::getContainer()->set(LrclibClientInterface::class, $lrclib);

        $this->library = Library::create(
            'Scanned artist credits',
            new LibrarySlug('scanned-credits-' . bin2hex(random_bytes(6))),
            new LibraryPath($this->libraryDirectory),
            LibraryType::Music,
            FilesystemType::Local,
        );
        static::getContainer()->get(LibraryPortInterface::class)->save($this->library);
    }

    protected function tearDown(): void
    {
        if (isset($this->libraryDirectory)) {
            new Filesystem()->remove($this->libraryDirectory);
        }

        parent::tearDown();
    }

    /**
     * The shapes the stored library's files have, without MusicBrainz tags: an album over two disc
     * directories with an album-artist tag, an album without one, an artist tag of three separate
     * values, single values that join names with a comma, and ID3v2.3 MP3s whose TLEN holds seconds
     * and whose artist ends in a NUL byte.
     */
    public function testTheStoredLibrarysTagShapesCreditEverySongAndAlbumAndMeasureEachSongFromItsAudio(): void
    {
        $postMalone = [['ALBUM', 'F-1 Trillion'], ['ALBUMARTIST', 'Post Malone']];
        $this->flac('F-1 Trillion/CD1/01 - Wrong Ones.flac', 1.0, [['TITLE', 'Wrong Ones'], ['ARTIST', 'Post Malone, Tim McGraw'], ...$postMalone, ['DISCNUMBER', '1'], ['TRACKNUMBER', '1']]);
        $this->flac('F-1 Trillion/CD1/02 - Finer Things.flac', 1.0, [['TITLE', 'Finer Things'], ['ARTIST', 'Post Malone, Hank Williams Jr.'], ...$postMalone, ['DISCNUMBER', '1'], ['TRACKNUMBER', '2']]);
        $this->flac('F-1 Trillion/CD2/01 - Yours.flac', 1.0, [['TITLE', 'Yours'], ['ARTIST', 'Post Malone'], ...$postMalone, ['DISCNUMBER', '2'], ['TRACKNUMBER', '1']]);
        $disturbed = [['ARTIST', 'Disturbed'], ['ALBUM', 'Ten Thousand Fists']];
        $this->flac('Ten Thousand Fists/01 - Ten Thousand Fists.flac', 1.5, [['TITLE', 'Ten Thousand Fists'], ...$disturbed, ['TRACKNUMBER', '1']]);
        $this->flac('Ten Thousand Fists/02 - Just Stop.flac', 1.5, [['TITLE', 'Just Stop'], ...$disturbed, ['TRACKNUMBER', '2']]);
        $this->flac('Rescue Me/02 - Rescue Me.flac', 2.0, [
            ['TITLE', 'Rescue Me'], ['ALBUM', 'Rescue Me'], ['TRACKNUMBER', '2'],
            ['ARTIST', 'Headhunterz'], ['ARTIST', 'Sound Rush'], ['ARTIST', 'Eurielle'],
        ]);
        // TLEN counts milliseconds, so "238" claims 0.238 seconds; the MP3 holds 2.5 seconds of audio.
        $this->mp3('Prima Nocte/13 - Amore Infelice.mp3', 2.5, [['TIT2', 'Amore Infelice'], ['TPE1', "Suspekt\0"], ['TALB', 'Prima Nocte'], ['TRCK', '13'], ['TLEN', '238']]);
        $this->mp3('Bulldogg Sessions/01 - Intro.mp3', 1.5, [['TIT2', 'Intro'], ['TPE1', "Bulldogg\0"], ['TALB', 'Bulldogg Sessions'], ['TRCK', '1']]);

        self::assertSame(6, $this->scan(), 'Each directory, each disc of the split album included, is imported on its own.');

        self::assertSame([
            'Bulldogg Sessions/01 - Intro.mp3' => [['Bulldogg', 'primary', 'Bulldogg']],
            'F-1 Trillion/CD1/01 - Wrong Ones.flac' => [['Post Malone, Tim McGraw', 'primary', 'Post Malone, Tim McGraw']],
            'F-1 Trillion/CD1/02 - Finer Things.flac' => [['Post Malone, Hank Williams Jr.', 'primary', 'Post Malone, Hank Williams Jr.']],
            'F-1 Trillion/CD2/01 - Yours.flac' => [['Post Malone', 'primary', 'Post Malone']],
            'Prima Nocte/13 - Amore Infelice.mp3' => [['Suspekt', 'primary', 'Suspekt']],
            'Rescue Me/02 - Rescue Me.flac' => [['Headhunterz', 'primary', 'Headhunterz; Sound Rush; Eurielle']],
            'Ten Thousand Fists/01 - Ten Thousand Fists.flac' => [['Disturbed', 'primary', 'Disturbed']],
            'Ten Thousand Fists/02 - Just Stop.flac' => [['Disturbed', 'primary', 'Disturbed']],
        ], $this->songCredits());
        self::assertSame([
            'Bulldogg Sessions' => [['Bulldogg', 'primary', 'Bulldogg']],
            'F-1 Trillion' => [['Post Malone', 'primary', 'Post Malone']],
            'Prima Nocte' => [['Suspekt', 'primary', 'Suspekt']],
            'Rescue Me' => [['Headhunterz', 'primary', 'Headhunterz; Sound Rush; Eurielle']],
            'Ten Thousand Fists' => [['Disturbed', 'primary', 'Disturbed']],
        ], $this->albumCredits(), 'Each album is credited once.');
        self::assertSame(
            ['Bulldogg Sessions', 'F-1 Trillion', 'Prima Nocte', 'Rescue Me', 'Ten Thousand Fists'],
            array_column($this->albums(), 0),
            'The two discs form one album.',
        );
        // One artist per name. Neither the later values of the three-value tag nor the parts of a
        // comma value became artists.
        self::assertSame([
            ['Bulldogg', null],
            ['Disturbed', null],
            ['Headhunterz', null],
            ['Post Malone', null],
            ['Post Malone, Hank Williams Jr.', null],
            ['Post Malone, Tim McGraw', null],
            ['Suspekt', null],
        ], $this->creditedArtists());
        self::assertSame([], $this->artistsNamed('Sound Rush', 'Eurielle', 'Tim McGraw', 'Hank Williams Jr.'));
        $this->assertLengths([
            'Bulldogg Sessions/01 - Intro.mp3' => 1.5,
            'F-1 Trillion/CD1/01 - Wrong Ones.flac' => 1.0,
            'F-1 Trillion/CD1/02 - Finer Things.flac' => 1.0,
            'F-1 Trillion/CD2/01 - Yours.flac' => 1.0,
            'Prima Nocte/13 - Amore Infelice.mp3' => 2.5,
            'Rescue Me/02 - Rescue Me.flac' => 2.0,
            'Ten Thousand Fists/01 - Ten Thousand Fists.flac' => 1.5,
            'Ten Thousand Fists/02 - Just Stop.flac' => 1.5,
        ]);
    }

    /**
     * Files tagged with MusicBrainz artist IDs, as Picard tags them: an alias of an artist already
     * scanned, under the same ID; a combined credit that carries two IDs; and an MP3 whose TXXX
     * frame gives the ID under another name.
     */
    public function testMusicBrainzArtistIdsGroupAliasesUnderOneArtistThatKeepsEachTaggedName(): void
    {
        $album = ['ALBUM', 'Studio Tracks'];
        $this->flac('Headhunterz/01 - Dragonborn.flac', 2.0, [['TITLE', 'Dragonborn'], $album, ['ARTIST', 'Headhunterz'], ['MUSICBRAINZ_ARTISTID', self::HEADHUNTERZ_ID]]);
        $this->scan();

        $this->flac('Headhunterz/02 - Orange Heart.flac', 2.0, [['TITLE', 'Orange Heart'], $album, ['ARTIST', 'HeadHunterz'], ['MUSICBRAINZ_ARTISTID', self::HEADHUNTERZ_ID]]);
        $this->flac('Headhunterz/03 - Feel Alive.flac', 2.0, [
            ['TITLE', 'Feel Alive'], $album, ['ARTIST', 'Headhunterz feat. Krewella'],
            ['MUSICBRAINZ_ARTISTID', self::HEADHUNTERZ_ID], ['MUSICBRAINZ_ARTISTID', self::KREWELLA_ID],
        ]);
        $this->mp3('Headhunterz/04 - Live Set.mp3', 2.0, [['TIT2', 'Live Set'], ['TALB', 'Studio Tracks'], ['TPE1', 'Willem Rebergen'], ['TXXX', self::ID3_ARTIST_IDS . self::HEADHUNTERZ_ID]]);
        $this->scan();

        self::assertSame([
            'Headhunterz/01 - Dragonborn.flac' => [['Headhunterz', 'primary', 'Headhunterz']],
            'Headhunterz/02 - Orange Heart.flac' => [['Headhunterz', 'primary', 'HeadHunterz']],
            'Headhunterz/03 - Feel Alive.flac' => [['Headhunterz feat. Krewella', 'primary', 'Headhunterz feat. Krewella']],
            'Headhunterz/04 - Live Set.mp3' => [['Headhunterz', 'primary', 'Willem Rebergen']],
        ], $this->songCredits());
        // The ID's artist keeps the first name scanned; the combined credit, with two IDs, matches by
        // name and takes neither.
        self::assertSame([
            ['Headhunterz', self::HEADHUNTERZ_ID],
            ['Headhunterz feat. Krewella', null],
        ], $this->creditedArtists());
        self::assertSame([], $this->artistsNamed('HeadHunterz', 'Willem Rebergen', 'Krewella'));

        // The alias song is looked up under the name it was tagged with.
        $this->fetchLyrics('Headhunterz/02 - Orange Heart.flac');
        self::assertSame(['HeadHunterz', 'HeadHunterz'], array_map(
            static fn (array $request): mixed => $request['query']['artist_name'] ?? null,
            $this->lrclibRequests,
        ));
    }

    /**
     * Leaves the library as the faulty import left the stored one, songs and an album without
     * credits and MP3 lengths read from TLEN or missing, and hands one song to another artist the
     * way an administrator would. A rescan restores what is missing and keeps the hand-made credit.
     */
    public function testARescanRestoresRemovedCreditsAndLengthsAndKeepsACreditChangedByHand(): void
    {
        $disturbed = [['ARTIST', 'Disturbed'], ['ALBUM', 'Ten Thousand Fists']];
        $this->flac('Ten Thousand Fists/01 - Ten Thousand Fists.flac', 1.5, [['TITLE', 'Ten Thousand Fists'], ...$disturbed, ['TRACKNUMBER', '1']]);
        $this->flac('Ten Thousand Fists/02 - Just Stop.flac', 1.5, [['TITLE', 'Just Stop'], ...$disturbed, ['TRACKNUMBER', '2']]);
        $this->mp3('Prima Nocte/13 - Amore Infelice.mp3', 2.5, [['TIT2', 'Amore Infelice'], ['TPE1', "Suspekt\0"], ['TALB', 'Prima Nocte'], ['TRCK', '13'], ['TLEN', '238']]);
        $this->mp3('Prima Nocte/14 - Outro.mp3', 2.5, [['TIT2', 'Outro'], ['TPE1', "Suspekt\0"], ['TALB', 'Prima Nocte'], ['TRCK', '14'], ['TLEN', '150']]);
        $this->scan();
        $songs = $this->songs();
        $albums = $this->albums();

        $this->console('app:artist:song:remove', ['public-id' => $this->artistPublicId('Disturbed'), 'song-id' => $this->songPublicId('Ten Thousand Fists/01 - Ten Thousand Fists.flac')]);
        $this->console('app:artist:song:remove', ['public-id' => $this->artistPublicId('Disturbed'), 'song-id' => $this->songPublicId('Ten Thousand Fists/02 - Just Stop.flac')]);
        $this->console('app:artist:album:remove', ['public-id' => $this->artistPublicId('Disturbed'), 'album-id' => $this->albumPublicId('Ten Thousand Fists')]);
        $this->console('app:artist:song:remove', ['public-id' => $this->artistPublicId('Suspekt'), 'song-id' => $this->songPublicId('Prima Nocte/13 - Amore Infelice.mp3')]);
        $this->setLength('Prima Nocte/13 - Amore Infelice.mp3', 0.238);
        $this->setLength('Prima Nocte/14 - Outro.mp3', null);
        $this->console('app:artist:create', ['name' => 'Orgi-E']);
        $this->console('app:artist:song:remove', ['public-id' => $this->artistPublicId('Suspekt'), 'song-id' => $this->songPublicId('Prima Nocte/14 - Outro.mp3')]);
        $this->console('app:artist:song:add', ['public-id' => $this->artistPublicId('Orgi-E'), 'song-id' => $this->songPublicId('Prima Nocte/14 - Outro.mp3'), 'role' => 'primary']);
        self::assertSame([
            'Prima Nocte/13 - Amore Infelice.mp3' => [],
            'Prima Nocte/14 - Outro.mp3' => [['Orgi-E', 'primary', 'Orgi-E']],
            'Ten Thousand Fists/01 - Ten Thousand Fists.flac' => [],
            'Ten Thousand Fists/02 - Just Stop.flac' => [],
        ], $this->songCredits(), 'The songs start out as the faulty import and the administrator left them.');
        self::assertSame(['Prima Nocte' => [['Suspekt', 'primary', 'Suspekt']], 'Ten Thousand Fists' => []], $this->albumCredits());

        $this->scan(rescan: true);

        self::assertSame($songs, $this->songs(), 'The rescan repairs the stored songs and creates none.');
        self::assertSame($albums, $this->albums(), 'The rescan creates no album.');
        self::assertSame([
            'Prima Nocte/13 - Amore Infelice.mp3' => [['Suspekt', 'primary', 'Suspekt']],
            'Prima Nocte/14 - Outro.mp3' => [['Orgi-E', 'primary', 'Orgi-E']],
            'Ten Thousand Fists/01 - Ten Thousand Fists.flac' => [['Disturbed', 'primary', 'Disturbed']],
            'Ten Thousand Fists/02 - Just Stop.flac' => [['Disturbed', 'primary', 'Disturbed']],
        ], $this->songCredits(), 'Removed credits come back; the credit changed by hand stays and gains no tag credit.');
        self::assertSame([
            'Prima Nocte' => [['Suspekt', 'primary', 'Suspekt']],
            'Ten Thousand Fists' => [['Disturbed', 'primary', 'Disturbed']],
        ], $this->albumCredits());
        $this->assertLengths([
            'Prima Nocte/13 - Amore Infelice.mp3' => 2.5,
            'Prima Nocte/14 - Outro.mp3' => 2.5,
            'Ten Thousand Fists/01 - Ten Thousand Fists.flac' => 1.5,
            'Ten Thousand Fists/02 - Just Stop.flac' => 1.5,
        ]);
    }

    /**
     * A song with play history whose credit an administrator changed, and whose file then gets a
     * new artist and title tag. The next scan updates the same song from the new tags.
     */
    public function testARetaggedFileUpdatesItsSongInPlaceAndTakesTheNewTagsCredit(): void
    {
        $file = 'Ten Thousand Fists/07 - Avarice.flac';
        $this->flac($file, 1.5, [['TITLE', 'Avarice'], ['ARTIST', 'Disturbed'], ['ALBUM', 'Ten Thousand Fists'], ['TRACKNUMBER', '7']]);
        $this->scan();
        $rows = $this->songRows($file);
        self::assertCount(1, $rows, 'The scan imported the file.');
        [$songId, $publicId] = $rows[0];

        $listener = $this->createTestUser();
        static::getContainer()->get(LibraryAccessPortInterface::class)->grant($listener->getId(), $this->library->getId());
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/activity/play', $listener, ['songId' => $publicId]), 201);
        $this->console('app:artist:create', ['name' => 'David Draiman']);
        $this->console('app:artist:song:remove', ['public-id' => $this->artistPublicId('Disturbed'), 'song-id' => $publicId]);
        $this->console('app:artist:song:add', ['public-id' => $this->artistPublicId('David Draiman'), 'song-id' => $publicId, 'role' => 'primary']);
        self::assertSame([$file => [['David Draiman', 'primary', 'David Draiman']]], $this->songCredits());

        $this->flac($file, 1.5, [['TITLE', 'Avarice (Remastered)'], ['ARTIST', 'Device'], ['ALBUM', 'Ten Thousand Fists'], ['TRACKNUMBER', '7']]);
        $this->scan();

        self::assertSame([[$songId, $publicId, 'Avarice (Remastered)']], $this->songRows($file), 'One song at the path: the same song, with the new title.');
        self::assertSame([$file => [['Device', 'primary', 'Device']]], $this->songCredits(), 'The new tag replaces the credit changed by hand.');
        self::assertSame(
            [1],
            array_map(intval(...), $this->connection()->fetchFirstColumn(
                'SELECT play_count FROM media_activities WHERE user_id = ? AND song_id = ?',
                [$listener->getId()->toString(), $songId],
            )),
            'The song keeps its play history.',
        );
        $this->assertLengths([$file => 1.5]);
    }

    public function testALyricsFetchSendsTheCreditedNameAndTheRoundedLength(): void
    {
        $file = 'Rescue Me/02 - Rescue Me.flac';
        // 2.6 seconds rounds to 3; cutting off the fraction would send 2.
        $this->flac($file, 2.6, [
            ['TITLE', 'Rescue Me'], ['ALBUM', 'Rescue Me'], ['TRACKNUMBER', '2'],
            ['ARTIST', 'Headhunterz'], ['ARTIST', 'Sound Rush'], ['ARTIST', 'Eurielle'],
        ]);
        $this->scan();

        $this->fetchLyrics($file);

        $query = ['album_name' => 'Rescue Me', 'artist_name' => 'Headhunterz; Sound Rush; Eurielle', 'duration' => '3', 'track_name' => 'Rescue Me'];
        self::assertSame([
            ['path' => '/api/get-cached', 'query' => $query],
            ['path' => '/api/get', 'query' => $query],
        ], $this->lrclibRequests);
    }

    /** @param list<array{string, string}> $comments */
    private function flac(string $file, float $seconds, array $comments): void
    {
        $this->audio->writeFlac($this->libraryDirectory . '/' . $file, $seconds, $comments);
    }

    /** @param list<array{string, string}> $frames */
    private function mp3(string $file, float $seconds, array $frames): void
    {
        $this->audio->writeMp3($this->libraryDirectory . '/' . $file, $seconds, $frames);
    }

    /**
     * Scans the library through the console, then hands each directory the scan queued to the
     * import handler, in the order queued, with a cleared entity manager as a worker has. The
     * transport is emptied first and read through what was sent, since get() returns one envelope
     * at a time and an earlier import leaves its cover extraction queued in front.
     *
     * @return int the number of directories imported
     */
    private function scan(bool $rescan = false): int
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();
        $this->console('app:library:scan', ['library' => $this->library->getSlug()->toString(), '--rescan' => $rescan]);
        static::getContainer()->get(RelayOutboxHandler::class)(new RelayOutboxCommand());

        $discoveries = array_values(array_filter(
            array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $transport->getSent()),
            fn (object $message): bool => $message instanceof FilesDiscovered && $message->libraryId->equals($this->library->getId()),
        ));
        self::assertNotSame([], $discoveries, 'The scan queued the library\'s files for import.');
        foreach ($discoveries as $discovery) {
            $this->entityManager->clear();
            static::getContainer()->get(FilesDiscoveredHandler::class)($discovery);
        }

        return count($discoveries);
    }

    private function fetchLyrics(string $file): void
    {
        $display = $this->console('app:song:lyrics:fetch', ['song' => $this->songPublicId($file)]);
        self::assertStringContainsString('No lyrics were found', $display);
    }

    /** @param array<string, string|bool> $input */
    private function console(string $name, array $input): string
    {
        $tester = new CommandTester(new Application(self::$kernel)->find($name));
        self::assertSame(Command::SUCCESS, $tester->execute($input), $tester->getDisplay());

        return $tester->getDisplay();
    }

    /** @return array<string, list<array{string, string, string}>> each song's credits as artist name, role and credited name, by its file */
    private function songCredits(): array
    {
        return $this->creditsBy(<<<'SQL'
            SELECT s.path, a.name, c.role, c.credited_name
            FROM songs s
            JOIN albums al ON al.id = s.album_id
            LEFT JOIN artist_song c ON c.song_id = s.id
            LEFT JOIN artists a ON a.id = c.artist_id
            WHERE al.library_id = ?
            SQL, $this->relative(...));
    }

    /** @return array<string, list<array{string, string, string}>> each album's credits as artist name, role and credited name, by its title */
    private function albumCredits(): array
    {
        return $this->creditsBy(<<<'SQL'
            SELECT al.title, a.name, c.role, c.credited_name
            FROM albums al
            LEFT JOIN artist_album c ON c.album_id = al.id
            LEFT JOIN artists a ON a.id = c.artist_id
            WHERE al.library_id = ?
            SQL, static fn (string $title): string => $title);
    }

    /**
     * @param \Closure(string): string $key the key of a row, from its first column
     *
     * @return array<string, list<array{string, string, string}>> in byte order of the keys, whatever the database collation
     */
    private function creditsBy(string $sql, \Closure $key): array
    {
        $credits = [];
        foreach ($this->connection()->fetchAllNumeric($sql, [$this->library->getId()->toString()]) as [$owner, $artist, $role, $creditedName]) {
            $credits[$key((string) $owner)] ??= [];
            if ($artist !== null) {
                $credits[$key((string) $owner)][] = [(string) $artist, (string) $role, (string) $creditedName];
            }
        }
        ksort($credits, SORT_STRING);

        return array_map(static function (array $list): array {
            sort($list);

            return $list;
        }, $credits);
    }

    /** @return list<array{string, string|null}> the name and MusicBrainz ID of each artist a song or album of the library is credited to, by name */
    private function creditedArtists(): array
    {
        $artists = array_map(
            static fn (array $row): array => [(string) $row[0], $row[1] === null ? null : (string) $row[1]],
            $this->connection()->fetchAllNumeric(<<<'SQL'
                SELECT a.name, a.mbid
                FROM artists a
                WHERE a.id IN (
                    SELECT c.artist_id FROM artist_song c JOIN songs s ON s.id = c.song_id JOIN albums al ON al.id = s.album_id WHERE al.library_id = :library
                    UNION
                    SELECT c.artist_id FROM artist_album c JOIN albums al ON al.id = c.album_id WHERE al.library_id = :library
                )
                SQL, ['library' => $this->library->getId()->toString()]),
        );
        usort($artists, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return $artists;
    }

    /** @return list<string> */
    private function artistsNamed(string ...$names): array
    {
        return array_map(strval(...), $this->connection()->fetchFirstColumn(
            'SELECT name FROM artists WHERE name IN (?) ORDER BY name',
            [array_values($names)],
            [ArrayParameterType::STRING],
        ));
    }

    private function artistPublicId(string $name): string
    {
        $ids = $this->connection()->fetchFirstColumn('SELECT public_id FROM artists WHERE name = ?', [$name]);
        self::assertCount(1, $ids, sprintf('One artist is named "%s".', $name));

        return (string) $ids[0];
    }

    /** @return list<array{string, string}> the file and public ID of each song of the library, by file */
    private function songs(): array
    {
        return $this->sortedPairs($this->connection()->fetchAllNumeric(
            'SELECT s.path, s.public_id FROM songs s JOIN albums al ON al.id = s.album_id WHERE al.library_id = ?',
            [$this->library->getId()->toString()],
        ), $this->relative(...));
    }

    /** @return list<array{string, string}> the title and public ID of each album of the library, by title */
    private function albums(): array
    {
        return $this->sortedPairs($this->connection()->fetchAllNumeric(
            'SELECT title, public_id FROM albums WHERE library_id = ?',
            [$this->library->getId()->toString()],
        ), static fn (string $title): string => $title);
    }

    /**
     * @param list<list<mixed>>         $rows
     * @param \Closure(string): string $key the first value of a pair, from the row's first column
     *
     * @return list<array{string, string}> in byte order, whatever the database collation
     */
    private function sortedPairs(array $rows, \Closure $key): array
    {
        $pairs = array_map(static fn (array $row): array => [$key((string) $row[0]), (string) $row[1]], $rows);
        sort($pairs);

        return $pairs;
    }

    private function songPublicId(string $file): string
    {
        $rows = $this->songRows($file);
        self::assertCount(1, $rows, sprintf('One song is at %s.', $file));

        return $rows[0][1];
    }

    private function albumPublicId(string $title): string
    {
        $ids = array_column(array_filter($this->albums(), static fn (array $album): bool => $album[0] === $title), 1);
        self::assertCount(1, $ids, sprintf('One album is titled "%s".', $title));

        return $ids[0];
    }

    /** @return list<array{string, string, string}> the ID, public ID and title of each song at the file's path */
    private function songRows(string $file): array
    {
        return array_map(
            static fn (array $row): array => [(string) $row[0], (string) $row[1], (string) $row[2]],
            $this->connection()->fetchAllNumeric('SELECT id, public_id, title FROM songs WHERE path = ?', [$this->libraryDirectory . '/' . $file]),
        );
    }

    /** Overwrites a song's stored length, as an import that read it from the tags left it. */
    private function setLength(string $file, ?float $seconds): void
    {
        self::assertSame(1, $this->connection()->executeStatement('UPDATE songs SET length = ? WHERE path = ?', [$seconds, $this->libraryDirectory . '/' . $file]));
    }

    /**
     * Each song of the library is as long as the audio encoded for it, within one MP3 frame.
     *
     * @param array<string, float> $expected the encoded seconds, by file
     */
    private function assertLengths(array $expected): void
    {
        $lengths = array_map(
            fn (array $row): array => [$this->relative((string) $row[0]), $row[1] === null ? null : (float) $row[1]],
            $this->connection()->fetchAllNumeric(
                'SELECT s.path, s.length FROM songs s JOIN albums al ON al.id = s.album_id WHERE al.library_id = ?',
                [$this->library->getId()->toString()],
            ),
        );
        usort($lengths, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        ksort($expected, SORT_STRING);

        self::assertSame(array_keys($expected), array_column($lengths, 0), 'The library holds one song per file.');
        foreach ($lengths as [$file, $length]) {
            self::assertNotNull($length, $file . ' has no length.');
            self::assertEqualsWithDelta($expected[$file], $length, 0.05, $file);
        }
    }

    private function relative(string $path): string
    {
        self::assertStringStartsWith($this->libraryDirectory . '/', $path);

        return substr($path, strlen($this->libraryDirectory) + 1);
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
