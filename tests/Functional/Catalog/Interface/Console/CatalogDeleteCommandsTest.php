<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Interface\Console;

use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieVideoEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Media\Application\Port\StoragePortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The catalog delete routes and the app:album:delete, app:song:delete, app:movie:delete and
 * app:artist:delete commands run the same use cases against a real library folder.
 */
final class CatalogDeleteCommandsTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/baander-catalog-delete-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->base . '/elsewhere', 0777, true));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->base);
        parent::tearDown();
    }

    public function testDeletingAnAlbumWithItsFilesRemovesEverySongFileUnderTheLibraryRoot(): void
    {
        $admin = $this->createAdminUser();
        $fixture = $this->albumWithSongs(3);
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE libraries SET scan_status = 'completed', last_scan = now() - interval '1 day' WHERE id = ?",
            [$fixture['library']],
        );
        $scanState = $this->scanState($fixture['library']);

        $response = $this->assertJsonResponse(
            $this->authenticatedRequest('DELETE', '/api/admin/albums/' . $fixture['album'] . '?deleteFiles=true', $admin),
            200,
        );

        self::assertSame(['albums' => 1, 'songs' => 3, 'coverImages' => 0], $response['data']['deleted']);
        self::assertSame($this->sorted($fixture['paths']), $this->sorted($response['data']['files']['removed']));
        self::assertSame([], $response['data']['files']['missing']);
        self::assertSame([], $response['data']['files']['left']);
        foreach ($fixture['paths'] as $path) {
            self::assertFileDoesNotExist($path);
        }
        self::assertSame(0, $this->rows('albums', 'public_id', $fixture['album']));
        self::assertSame(0, $this->rows('songs', 'album_id', $fixture['albumId']));
        self::assertSame(0, $this->indexRows($fixture['library']));
        self::assertSame($scanState, $this->scanState($fixture['library']), 'The delete held and released the library without touching its scan status.');
        self::assertNull($scanState['claim_id']);
    }

    public function testDeletingAnAlbumWithSongsRemovesItsCoverImageAndKeepCoverKeepsIt(): void
    {
        $admin = $this->createAdminUser();
        $withCover = $this->albumWithSongs(2, withFiles: false);
        $coverPath = $this->setCover('app:album:cover:set', $withCover['album'], 'albums');
        $kept = $this->albumWithSongs(2, withFiles: false);
        $keptPath = $this->setCover('app:album:cover:set', $kept['album'], 'albums');

        $api = $this->assertJsonResponse($this->authenticatedRequest('DELETE', '/api/albums/' . $withCover['album'], $admin), 200)['data'];
        self::assertSame(['albums' => 1, 'songs' => 2, 'coverImages' => 1], $api['deleted']);
        self::assertSame(0, $this->rows('images', 'path', $coverPath));
        self::assertFileDoesNotExist($this->storage()->resolve($coverPath));

        $delete = $this->command('app:album:delete');
        self::assertSame(Command::SUCCESS, $delete->execute(['public-id' => $kept['album'], '--keep-cover' => true, '--force' => true, '--json' => true], ['interactive' => false]), $delete->getDisplay());
        self::assertSame(['albums' => 1, 'songs' => 2, 'coverImages' => 0], $this->decode($delete->getDisplay())['deleted']);
        self::assertSame(1, $this->rows('images', 'path', $keptPath));
        self::assertFileExists($this->storage()->resolve($keptPath));
        $this->storage()->delete($keptPath);
    }

    public function testAnAlbumOf1200SongsIsPreviewedAndDeletedCompletely(): void
    {
        $admin = $this->createAdminUser();
        $fixture = $this->albumWithSongs(1200, withFiles: false);

        $preview = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/admin/albums/' . $fixture['album'] . '/delete-preview', $admin),
            200,
        )['data'];
        self::assertSame(1200, $preview['album']['songCount']);
        self::assertSame(['count' => 1200, 'totalSize' => 1200 * 7], $preview['files']);

        $delete = $this->command('app:album:delete');
        self::assertSame(Command::SUCCESS, $delete->execute(['public-id' => $fixture['album'], '--force' => true, '--json' => true], ['interactive' => false]), $delete->getDisplay());
        $result = $this->decode($delete->getDisplay());

        self::assertSame(['albums' => 1, 'songs' => 1200, 'coverImages' => 0], $result['deleted']);
        self::assertSame(0, $this->rows('songs', 'album_id', $fixture['albumId']));
    }

    public function testASongFileOutsideTheLibraryRootRefusesTheAlbumDeleteOnBothPaths(): void
    {
        $admin = $this->createAdminUser();
        $fixture = $this->albumWithSongs(2, outsideRoot: 1);

        $error = $this->assertJsonResponse(
            $this->authenticatedRequest('DELETE', '/api/admin/albums/' . $fixture['album'] . '?deleteFiles=true', $admin),
            422,
        );
        self::assertStringContainsString($this->base . '/elsewhere/', $error['error']['message']);

        $delete = $this->command('app:album:delete');
        self::assertSame(Command::INVALID, $delete->execute(['public-id' => $fixture['album'], '--delete-files' => true, '--force' => true], ['interactive' => false]));

        self::assertSame(1, $this->rows('albums', 'public_id', $fixture['album']));
        self::assertSame(3, $this->rows('songs', 'album_id', $fixture['albumId']));
        self::assertSame(2, $this->indexRows($fixture['library']));
        foreach ($fixture['paths'] as $path) {
            self::assertFileExists($path);
        }
    }

    public function testDeletingFilesOfALibraryThatIsBeingScannedIsAConflictNamingTheScanOnBothPaths(): void
    {
        $admin = $this->createAdminUser();
        $fixture = $this->albumWithSongs(1);
        $song = $this->songPublicId($fixture['albumId']);
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE libraries SET scan_status = 'scanning', claim_id = ?, claim_kind = 'scan', claim_expires_at = clock_timestamp() + interval '15 minutes' WHERE id = ?",
            [(new Uuid())->toString(), $fixture['library']],
        );

        $album = $this->assertJsonResponse($this->authenticatedRequest('DELETE', '/api/admin/albums/' . $fixture['album'] . '?deleteFiles=true', $admin), 409);
        $this->assertJsonResponse($this->authenticatedRequest('DELETE', '/api/admin/songs/' . $song . '?deleteFile=true', $admin), 409);
        $delete = $this->command('app:song:delete');
        self::assertSame(Command::FAILURE, $delete->execute(['public-id' => $song, '--delete-files' => true, '--force' => true], ['interactive' => false]));

        self::assertSame(['reason' => 'library_busy', 'holder' => 'scan'], $album['error']['details']);
        self::assertStringContainsString('A scan is already in progress for the library', $album['error']['message']);
        self::assertStringContainsString('A scan is already in progress for the library', $delete->getDisplay());
        self::assertSame(1, $this->rows('songs', 'public_id', $song));
        self::assertFileExists($fixture['paths'][0]);
    }

    public function testAScanStartedWhileADeleteHoldsTheLibraryIsAConflictNamingTheDelete(): void
    {
        $admin = $this->createAdminUser();
        $fixture = $this->albumWithSongs(1);
        $files = static::getContainer()->get(LibraryMediaFilesInterface::class);
        self::assertInstanceOf(LibraryMediaFilesInterface::class, $files);
        $claim = $files->claim(Uuid::fromString($fixture['library']));
        $this->entityManager->clear();

        try {
            $refused = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/libraries/' . $fixture['library'] . '/scan', $admin), 409);
            $scan = $this->command('app:library:scan');
            self::assertSame(Command::FAILURE, $scan->execute(['library' => $fixture['library']], ['interactive' => false]));
        } finally {
            $files->release($claim);
        }

        self::assertSame(['reason' => 'library_busy', 'holder' => 'delete'], $refused['error']['details']);
        self::assertStringContainsString('A delete with files is in progress for the library', $refused['error']['message']);
        self::assertStringContainsString('A delete with files is in progress for the library', $scan->getDisplay());
        self::assertNull($this->scanState($fixture['library'])['scan_status'], 'The refused scans left the status alone.');
    }

    public function testDeleteFilesWithoutForceOnATerminalExitsInvalidAndChangesNothing(): void
    {
        $fixture = $this->albumWithSongs(1);

        $delete = $this->command('app:album:delete');
        $delete->setInputs(['yes']);
        self::assertSame(Command::INVALID, $delete->execute(['public-id' => $fixture['album'], '--delete-files' => true]));
        self::assertStringContainsString('--force', $delete->getDisplay());

        self::assertSame(1, $this->rows('albums', 'public_id', $fixture['album']));
        self::assertFileExists($fixture['paths'][0]);
    }

    public function testDryRunPrintsThePreviewTheApiReturnsAndChangesNothing(): void
    {
        $admin = $this->createAdminUser();
        $fixture = $this->albumWithSongs(2, outsideRoot: 1);
        $song = $this->songPublicId($fixture['albumId']);

        $albumApi = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/admin/albums/' . $fixture['album'] . '/delete-preview?deleteFiles=true', $admin),
            200,
        )['data'];
        $albumCli = $this->command('app:album:delete');
        self::assertSame(Command::SUCCESS, $albumCli->execute(['public-id' => $fixture['album'], '--dry-run' => true, '--delete-files' => true, '--json' => true]));
        self::assertSame($albumApi, $this->decode($albumCli->getDisplay()));
        self::assertFalse($albumApi['fileDeletion']['allowed']);
        self::assertSame(
            ['deletable', 'deletable', 'outside_root'],
            $this->sorted(array_column($albumApi['fileDeletion']['files'], 'verdict')),
        );

        $songApi = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/admin/songs/' . $song . '/delete-preview?deleteFile=true', $admin),
            200,
        )['data'];
        $songCli = $this->command('app:song:delete');
        self::assertSame(Command::SUCCESS, $songCli->execute(['public-id' => $song, '--dry-run' => true, '--delete-files' => true, '--json' => true]));
        self::assertSame($songApi, $this->decode($songCli->getDisplay()));
        self::assertCount(1, $songApi['fileDeletion']['files']);

        $plain = $this->command('app:album:delete');
        self::assertSame(Command::SUCCESS, $plain->execute(['public-id' => $fixture['album'], '--dry-run' => true]));
        self::assertStringContainsString('Dry run: nothing was changed.', $plain->getDisplay());

        self::assertSame(3, $this->rows('songs', 'album_id', $fixture['albumId']));
        self::assertSame(2, $this->indexRows($fixture['library']));
        foreach ($fixture['paths'] as $path) {
            self::assertFileExists($path);
        }
    }

    public function testDeletingAnArtistRemovesItsCoverImageRowAndFileOnBothPaths(): void
    {
        $admin = $this->createAdminUser();
        $overHttp = $this->artistWithCover();
        $fromShell = $this->artistWithCover();

        $api = $this->assertJsonResponse($this->authenticatedRequest('DELETE', '/api/artists/' . $overHttp['artist'], $admin), 200)['data'];
        $delete = $this->command('app:artist:delete');
        self::assertSame(Command::SUCCESS, $delete->execute(['public-id' => $fromShell['artist'], '--force' => true, '--json' => true], ['interactive' => false]), $delete->getDisplay());

        $expected = ['deleted' => ['artists' => 1, 'coverImages' => 1], 'files' => ['removed' => [], 'missing' => [], 'left' => []]];
        self::assertSame($expected, $api);
        self::assertSame($expected, $this->decode($delete->getDisplay()));
        foreach ([$overHttp, $fromShell] as $fixture) {
            self::assertSame(0, $this->rows('artists', 'public_id', $fixture['artist']));
            self::assertSame(0, $this->rows('images', 'path', $fixture['imagePath']));
            self::assertFileDoesNotExist($this->storage()->resolve($fixture['imagePath']));
        }
    }

    public function testDeletingAMovieRemovesTheMovieAndTheVideosOnlyItUsesOnBothPaths(): void
    {
        $admin = $this->createAdminUser();
        $library = $this->library();
        $shared = new VideoEntity(new PublicId(), '/movies/shared.mkv', bin2hex(random_bytes(16)));
        $this->entityManager->persist($shared);
        $keeper = $this->movie($library, [$shared]);
        $overHttp = $this->movie($library, [$this->video(), $this->video(), $shared]);
        $fromShell = $this->movie($library, [$this->video(), $this->video(), $shared]);
        $this->entityManager->flush();

        $api = $this->assertJsonResponse($this->authenticatedRequest('DELETE', '/api/movies/' . $overHttp['movie'], $admin), 200)['data'];
        $delete = $this->command('app:movie:delete');
        self::assertSame(Command::SUCCESS, $delete->execute(['public-id' => $fromShell['movie'], '--force' => true, '--json' => true], ['interactive' => false]), $delete->getDisplay());

        $expected = ['deleted' => ['movies' => 1, 'videos' => 2], 'files' => ['removed' => [], 'missing' => [], 'left' => []]];
        self::assertSame($expected, $api);
        self::assertSame($expected, $this->decode($delete->getDisplay()));
        foreach ([$overHttp, $fromShell] as $fixture) {
            self::assertSame(0, $this->rows('movies', 'public_id', $fixture['movie']));
            foreach (array_slice($fixture['videos'], 0, 2) as $video) {
                self::assertSame(0, $this->rows('videos', 'id', $video));
            }
        }
        self::assertSame(1, $this->rows('videos', 'id', $shared->getId()->toString()), 'a video another movie uses stays');
        self::assertSame(1, $this->rows('movies', 'public_id', $keeper['movie']));
    }

    public function testApiAndShellDeletesOfTheSameFixtureLeaveTheSameState(): void
    {
        $admin = $this->createAdminUser();
        $overHttp = $this->albumWithSongs(2);
        $fromShell = $this->albumWithSongs(2);

        $api = $this->assertJsonResponse(
            $this->authenticatedRequest('DELETE', '/api/admin/albums/' . $overHttp['album'] . '?deleteFiles=true', $admin),
            200,
        )['data'];
        $delete = $this->command('app:album:delete');
        self::assertSame(Command::SUCCESS, $delete->execute(['public-id' => $fromShell['album'], '--delete-files' => true, '--force' => true, '--json' => true], ['interactive' => false]), $delete->getDisplay());
        $cli = $this->decode($delete->getDisplay());

        self::assertSame($this->sorted($overHttp['paths']), $this->sorted($api['files']['removed']));
        self::assertSame($this->sorted($fromShell['paths']), $this->sorted($cli['files']['removed']));
        $api['files']['removed'] = $cli['files']['removed'] = [];
        self::assertSame($api, $cli);
        foreach ([$overHttp, $fromShell] as $fixture) {
            self::assertSame(0, $this->rows('albums', 'public_id', $fixture['album']));
            self::assertSame(0, $this->rows('songs', 'album_id', $fixture['albumId']));
            self::assertSame(0, $this->indexRows($fixture['library']));
            foreach ($fixture['paths'] as $path) {
                self::assertFileDoesNotExist($path);
            }
        }

        $songOverHttp = $this->songPublicId($this->albumWithSongs(1, withFiles: false)['albumId']);
        $songFromShell = $this->songPublicId($this->albumWithSongs(1, withFiles: false)['albumId']);
        $songApi = $this->assertJsonResponse($this->authenticatedRequest('DELETE', '/api/songs/' . $songOverHttp, $admin), 200)['data'];
        $songDelete = $this->command('app:song:delete');
        self::assertSame(Command::SUCCESS, $songDelete->execute(['public-id' => $songFromShell, '--force' => true, '--json' => true], ['interactive' => false]));
        self::assertSame($songApi, $this->decode($songDelete->getDisplay()));
        self::assertSame(['songs' => 1], $songApi['deleted']);
        self::assertSame(0, $this->rows('songs', 'public_id', $songOverHttp));
        self::assertSame(0, $this->rows('songs', 'public_id', $songFromShell));
    }

    public function testMalformedAndUnknownIdsAreRefusedOnBothPaths(): void
    {
        $admin = $this->createAdminUser();
        $unknown = (new PublicId())->toString();

        foreach (['albums', 'songs', 'movies', 'artists'] as $collection) {
            $this->assertJsonResponse($this->authenticatedRequest('DELETE', sprintf('/api/%s/not-a-public-id', $collection), $admin), 422);
            $this->assertJsonResponse($this->authenticatedRequest('DELETE', sprintf('/api/%s/%s', $collection, $unknown), $admin), 404);
        }
        $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/admin/albums/not-a-public-id/delete-preview', $admin), 422);
        $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/admin/songs/' . $unknown . '/delete-preview', $admin), 404);

        foreach (['app:album:delete', 'app:song:delete', 'app:movie:delete', 'app:artist:delete'] as $name) {
            $malformed = $this->command($name);
            self::assertSame(Command::INVALID, $malformed->execute(['public-id' => 'not-a-public-id', '--force' => true], ['interactive' => false]), $name);
            $missing = $this->command($name);
            self::assertSame(Command::FAILURE, $missing->execute(['public-id' => $unknown, '--force' => true], ['interactive' => false]), $name);
        }
    }

    /**
     * @return array{library: string, album: string, albumId: string, paths: list<string>}
     */
    private function albumWithSongs(int $songs, int $outsideRoot = 0, bool $withFiles = true): array
    {
        $library = $this->library();
        $root = $library->getPath();
        $album = new AlbumEntity(new PublicId(), $library, 'Delete fixture album', 'album');
        $this->entityManager->persist($album);

        $paths = [];
        $indexed = [];
        for ($i = 1; $i <= $songs + $outsideRoot; ++$i) {
            $path = $i <= $songs
                ? sprintf('%s/Artist/Album/%02d.flac', $root, $i)
                : sprintf('%s/elsewhere/%s.flac', $this->base, bin2hex(random_bytes(4)));
            if ($withFiles) {
                file_put_contents($path, 'audio ' . $i);
                $paths[] = $path;
                if ($i <= $songs) {
                    $indexed[] = $path;
                }
            }
            $this->entityManager->persist(new SongEntity(new PublicId(), $album, 'Track ' . $i, $path, 7, 'audio/flac'));
        }
        $this->entityManager->flush();
        $this->indexFiles($library->getId(), $indexed);

        return [
            'library' => $library->getId()->toString(),
            'album' => $album->getPublicId()->toString(),
            'albumId' => $album->getId()->toString(),
            'paths' => $paths,
        ];
    }

    /** @return array{scan_status: ?string, last_scan: ?string, claim_id: ?string} */
    private function scanState(string $libraryId): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT scan_status, last_scan, claim_id FROM libraries WHERE id = ?',
            [$libraryId],
        );
        self::assertIsArray($row);

        /** @var array{scan_status: ?string, last_scan: ?string, claim_id: ?string} $row */
        return $row;
    }

    private function library(): LibraryEntity
    {
        $suffix = bin2hex(random_bytes(6));
        $root = $this->base . '/library-' . $suffix;
        self::assertTrue(mkdir($root . '/Artist/Album', 0777, true));
        $library = new LibraryEntity('Delete fixture ' . $suffix, 'delete-' . $suffix, $root, 'music', 'local');
        $this->entityManager->persist($library);

        return $library;
    }

    /**
     * @param list<VideoEntity> $videos
     * @return array{movie: string, videos: list<string>}
     */
    private function movie(LibraryEntity $library, array $videos): array
    {
        $movie = new MovieEntity(new PublicId(), $library, 'Delete fixture movie');
        $this->entityManager->persist($movie);
        foreach ($videos as $order => $video) {
            $this->entityManager->persist(new MovieVideoEntity($movie, $video, $order));
        }

        return [
            'movie' => $movie->getPublicId()->toString(),
            'videos' => array_map(static fn (VideoEntity $video): string => $video->getId()->toString(), $videos),
        ];
    }

    private function video(): VideoEntity
    {
        $video = new VideoEntity(new PublicId(), '/movies/' . bin2hex(random_bytes(4)) . '.mkv', bin2hex(random_bytes(16)));
        $this->entityManager->persist($video);

        return $video;
    }

    /** @return array{artist: string, imagePath: string} */
    private function artistWithCover(): array
    {
        $artist = new ArtistEntity(new PublicId(), 'Delete fixture artist');
        $this->entityManager->persist($artist);
        $this->entityManager->flush();
        $publicId = $artist->getPublicId()->toString();

        return ['artist' => $publicId, 'imagePath' => $this->setCover('app:artist:cover:set', $publicId, 'artists')];
    }

    /**
     * @param 'albums'|'artists' $table
     * @return string the stored image's path
     */
    private function setCover(string $command, string $publicId, string $table): string
    {
        $jpeg = $this->base . '/elsewhere/' . bin2hex(random_bytes(4)) . '.jpg';
        $canvas = imagecreatetruecolor(4, 4);
        self::assertNotFalse($canvas);
        imagejpeg($canvas, $jpeg);
        $set = $this->command($command);
        self::assertSame(Command::SUCCESS, $set->execute(['public-id' => $publicId, 'path' => $jpeg]), $set->getDisplay());

        $path = $this->entityManager->getConnection()->fetchOne(
            sprintf('SELECT i.path FROM %s o JOIN images i ON i.id = o.cover_image_id WHERE o.public_id = ?', $table),
            [$publicId],
        );
        self::assertIsString($path);
        self::assertFileExists($this->storage()->resolve($path));

        return $path;
    }

    private function songPublicId(string $albumId): string
    {
        $publicId = $this->entityManager->getConnection()->fetchOne('SELECT public_id FROM songs WHERE album_id = ? LIMIT 1', [$albumId]);
        self::assertIsString($publicId);

        return $publicId;
    }

    /** @param list<string> $paths */
    private function indexFiles(Uuid $libraryId, array $paths): void
    {
        foreach ($paths as $path) {
            $this->entityManager->getConnection()->insert('library_file_index', [
                'id' => (new Uuid())->toString(),
                'library_id' => $libraryId->toString(),
                'path' => $path,
                'hash' => hash('xxh128', $path),
                'size' => 7,
                'extension' => 'flac',
                'modified_at' => time(),
                'discovered_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        }
    }

    private function indexRows(string $libraryId): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT count(*) FROM library_file_index WHERE library_id = ?',
            [$libraryId],
        );
    }

    private function rows(string $table, string $column, string $value): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            sprintf('SELECT count(*) FROM %s WHERE %s = ?', $table, $column),
            [$value],
        );
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function storage(): StoragePortInterface
    {
        return static::getContainer()->get(StoragePortInterface::class);
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application($this->client->getKernel()))->find($name));
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
