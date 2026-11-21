<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\CommandHandler;

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Catalog\Application\Port\AlbumMatchRequestInterface;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\ArtistSyncRequestInterface;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Infrastructure\Scanner\DirectoryScanner;
use App\Lyrics\Application\Port\LyricsFetchRequestInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\DiscoveredFileObservations;
use App\Tests\Fixtures\Catalog\PassThroughTransaction;
use App\Tests\Support\Metadata\MetadataReaderFixture;
use App\Transcode\Application\Port\VideoProbePortInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;

final class AudioMetadataImportTest extends TestCase
{
    use DiscoveredFileObservations;

    #[DataProvider('formats')]
    public function testScannedAudioReachesCatalogWithEmbeddedTags(string $fixture, string $extension, string $format, string $mime): void
    {
        $directory = sys_get_temp_dir() . '/baander-audio-import-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $path = $directory . '/unrelated-filename.' . $extension;
        copy(dirname(__DIR__, 4) . '/Fixtures/AudioMetadata/tagged.' . $fixture, $path);
        try {
            $before = hash_file('sha256', $path);
            $scanned = (new DirectoryScanner())->scan(new LibraryPath($directory));
            self::assertCount(1, $scanned);
            self::assertTrue($scanned[0]->isAudio());
            $file = $this->observedFile(new DiscoveredFile(
                $path, $scanned[0]->getRelativePath(), $scanned[0]->getExtension(),
                $scanned[0]->getSize(), $scanned[0]->getModifiedAt(), $before,
            ));
            self::assertTrue($file->isAudio());

            $extracted = MetadataReaderFixture::reader()->extract($path);
            self::assertSame($format, $extracted['format']);
            self::assertSame('Imported title', $extracted['metadata']['title']);
            self::assertSame('Imported artist', $extracted['metadata']['artist']);

            $library = Uuid::v7();
            $album = Album::create($library, 'Imported album', 'Studio');
            $album->setCoverImage(Uuid::v7());
            $albums = $this->createStub(AlbumPortInterface::class);
            $albums->method('findByTitleAndLibrary')->willReturn($album);
            $songs = $this->createMock(SongPortInterface::class);
            $songs->expects(self::once())->method('persist')->willReturnCallback(static function (Song $song) use ($mime, $album): void {
                self::assertSame('Imported title', $song->getTitle());
                self::assertSame($mime, $song->getMimeType());
                self::assertSame(3, $song->getTrack());
                self::assertSame($album->getId()->toString(), $song->getAlbumId()->toString());
            });
            $songs->expects(self::once())->method('addArtistCredit');
            $handler = new FilesDiscoveredHandler(
                $albums, $this->createStub(GenrePortInterface::class), $songs,
                $this->createStub(MoviePortInterface::class), $this->createStub(VideoRepositoryInterface::class),
                MetadataReaderFixture::contentReader(), $this->createStub(VideoProbePortInterface::class),
                $this->createStub(MessageBusInterface::class), $this->createStub(LyricsFetchRequestInterface::class),
                $this->createStub(AlbumMatchRequestInterface::class), new NullLogger(),
                $this->observedMediaFiles($this->createStub(LibraryMediaFilesInterface::class)),
                new PassThroughTransaction(), $this->audioProbeStub(), $this->artistIdentitiesStub(),
                $this->createStub(ArtistSyncRequestInterface::class),
            );
            $handler(new FilesDiscovered($library, 'music', $directory, [$file]));
            self::assertSame($before, hash_file('sha256', $path));
        } finally {
            unlink($path);
            rmdir($directory);
        }
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function formats(): iterable
    {
        yield 'M4A' => ['m4a', 'm4a', 'm4a', 'audio/mp4'];
        yield 'MP4A alias' => ['m4a', 'MP4A', 'm4a', 'audio/mp4'];
        yield 'M4B alias' => ['m4a', 'm4b', 'm4a', 'audio/mp4'];
        yield 'WMA' => ['wma', 'wma', 'wma', 'audio/x-ms-wma'];
        yield 'WAV' => ['wav', 'wav', 'wav', 'audio/wav'];
        yield 'WAVE alias' => ['wav', 'wave', 'wav', 'audio/wav'];
        yield 'AIFF' => ['aiff', 'AIFF', 'aiff', 'audio/aiff'];
        yield 'AIF alias' => ['aiff', 'aif', 'aiff', 'audio/aiff'];
        yield 'AIFC' => ['aifc', 'aifc', 'aiff', 'audio/aiff'];
        yield 'AAC' => ['aac', 'aac', 'aac', 'audio/aac'];
        yield 'OGA alias' => ['ogg', 'oga', 'ogg', 'audio/ogg'];
    }
}
