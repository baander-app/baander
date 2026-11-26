<?php

declare(strict_types=1);

namespace App\Tests\Functional\Library;

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Catalog\Domain\Repository\SongRepositoryInterface;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\Port\LibraryPortInterface;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryFileIndexRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Domain\Event\Outbox\RelayOutboxCommand;
use App\Shared\Domain\Event\Outbox\RelayOutboxHandler;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Tests\Functional\TestCase;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Process\Process;

/**
 * A scan with the production probes imports a library file only when real ffprobe opens it as
 * a container of its kind, and its report names the format of each file it refused.
 */
final class UnsupportedContainerScanTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/baander-unsupported-container-' . (random_bytes(6) |> bin2hex(...));
        mkdir($this->directory . '/elsewhere', 0700, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
        parent::tearDown();
    }

    public function testAManifestNamedLikeASongIsRefusedWhileTheGenuineSongImports(): void
    {
        $root = $this->directory . '/music/Album';
        mkdir($root, 0700, true);
        $private = $this->media($this->directory . '/elsewhere/private.m4a', ['-f', 'lavfi', '-i', 'sine=frequency=880:duration=2', '-c:a', 'aac']);
        $manifest = $this->manifest($root . '/01 - Borrowed Track.mp3', $private, 'audio/mp4', 'mp4a.40.2');
        $genuine = $this->media($root . '/02 - Own Track.mp3', ['-f', 'lavfi', '-i', 'sine=frequency=440:duration=1',
            '-c:a', 'libmp3lame', '-metadata', 'title=Own Track', '-metadata', 'album=Album']);

        [$library, $failure] = $this->scan(LibraryType::Music, $this->directory . '/music');

        $songs = self::getContainer()->get(SongRepositoryInterface::class);
        self::assertNotNull($songs->findByHash((string) hash_file('xxh3', $genuine)));
        self::assertNull($songs->findByHash((string) hash_file('xxh3', $manifest)));
        self::assertStringContainsString($manifest . ': Not a supported media container: dash.', $failure);
        self::assertSame(
            [$manifest => false, $genuine => true],
            self::getContainer()->get(LibraryFileIndexRepositoryInterface::class)->findAvailabilityByPaths($library->getId(), [$manifest, $genuine]),
        );
    }

    public function testAManifestAndATransportStreamNamedLikeFilmsAreRefusedWhileTheGenuineFilmImports(): void
    {
        $root = $this->directory . '/movies/Holiday Clip (2021)';
        mkdir($root, 0700, true);
        $picture = ['-f', 'lavfi', '-i', 'testsrc2=size=64x64:rate=10:duration=2', '-c:v', 'libx264', '-pix_fmt', 'yuv420p'];
        $private = $this->media($this->directory . '/elsewhere/private.mp4', $picture);
        $manifest = $this->manifest($root . '/Holiday Clip (2021).mkv', $private, 'video/mp4', 'avc1.64000a');
        $transportStream = $this->media($root . '/Broadcast.mkv', [...$picture, '-f', 'mpegts']);
        $film = $this->media($root . '/Film.mkv', $picture);

        [, $failure] = $this->scan(LibraryType::Movie, $this->directory . '/movies');

        $videos = self::getContainer()->get(VideoRepositoryInterface::class);
        self::assertNotNull($videos->findByHash((string) hash_file('xxh3', $film)));
        self::assertNull($videos->findByHash((string) hash_file('xxh3', $manifest)));
        self::assertNull($videos->findByHash((string) hash_file('xxh3', $transportStream)));
        self::assertStringContainsString($manifest . ': Not a supported media container: dash.', $failure);
        self::assertStringContainsString($transportStream . ': Not a supported media container: mpegts.', $failure);
    }

    /** @return array{Library, string} the library and the import's failure report */
    private function scan(LibraryType $type, string $path): array
    {
        $container = self::getContainer();
        $slug = new LibrarySlug('unsupported-container-' . (random_bytes(6) |> bin2hex(...)));
        $library = Library::create('Unsupported containers', $slug, new LibraryPath($path), $type, FilesystemType::Local);
        $container->get(LibraryPortInterface::class)->save($library);
        $transport = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();
        $scan = new CommandTester(new Application(self::$kernel)->find('app:library:scan'));
        self::assertSame(Command::SUCCESS, $scan->execute(['library' => $slug->toString()]), $scan->getDisplay());
        $container->get(RelayOutboxHandler::class)(new RelayOutboxCommand());
        $messages = array_filter(
            array_map(static fn(Envelope $envelope): object => $envelope->getMessage(), $transport->getSent()),
            static fn(object $message): bool => $message instanceof FilesDiscovered,
        ) |> array_values(...);
        self::assertCount(1, $messages);
        $message = $messages[0];
        self::assertInstanceOf(FilesDiscovered::class, $message);
        self::assertCount(count(glob($path . '/*/*') ?: []), $message->files, implode(', ', array_map(
            static fn(DiscoveredFile $file): string => $file->relativePath,
            $message->files,
        )));
        try {
            $container->get(FilesDiscoveredHandler::class)($message);
        } catch (RuntimeException $failure) {
            return [$library, $failure->getMessage()];
        }

        self::fail('The import must report the refused files.');
    }

    /** @param list<string> $arguments */
    private function media(string $path, array $arguments): string
    {
        new Process(['ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-y', ...$arguments, $path])->mustRun();

        return $path;
    }

    private function manifest(string $path, string $target, string $mimeType, string $codecs): string
    {
        $escapedTarget = htmlspecialchars($target, ENT_XML1);
        file_put_contents($path, sprintf(
            '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<MPD xmlns="urn:mpeg:dash:schema:mpd:2011" profiles="urn:mpeg:dash:profile:isoff-on-demand:2011" type="static"'
            . ' mediaPresentationDuration="PT2S" minBufferTime="PT1S"><Period><AdaptationSet mimeType="%s">'
            . '<Representation id="1" bandwidth="100000" codecs="%s"><BaseURL>%s</BaseURL><SegmentBase indexRange="0-0"/>'
            . '</Representation></AdaptationSet></Period></MPD>' . "\n",
            $mimeType,
            $codecs,
            $escapedTarget,
        ));

        return $path;
    }
}
