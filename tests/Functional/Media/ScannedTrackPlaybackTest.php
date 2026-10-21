<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Shared\Domain\Event\Outbox\RelayOutboxCommand;
use App\Shared\Domain\Event\Outbox\RelayOutboxHandler;
use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Catalog\Domain\Repository\SongRepositoryInterface;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Application\Port\LibraryPortInterface;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryFileIndexRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Application\Http\BaanderHeader;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Tests\Functional\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Process\Process;

final class ScannedTrackPlaybackTest extends TestCase
{
    private string $libraryDirectory;

    protected function tearDown(): void
    {
        if (isset($this->libraryDirectory)) {
            (new Filesystem())->remove($this->libraryDirectory);
        }

        parent::tearDown();
    }

    public function testFreshlyScannedTrackStreamsItsOriginalBytesAndAnAuthorizedRange(): void
    {
        $container = self::getContainer();
        $suffix = bin2hex(random_bytes(8));
        $this->libraryDirectory = sys_get_temp_dir() . '/baander-scanned-playback-' . $suffix;
        self::assertTrue(mkdir($this->libraryDirectory, 0700));
        $source = $this->libraryDirectory . '/track.flac';
        $storageRoot = $_SERVER['MEDIA_STORAGE_PATH'] ?? $_ENV['MEDIA_STORAGE_PATH'] ?? getenv('MEDIA_STORAGE_PATH');
        self::assertIsString($storageRoot);
        self::assertNotSame('', $storageRoot);
        self::assertFalse(str_starts_with($source, rtrim($storageRoot, '/') . '/'));

        $encoder = new Process([
            'ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1',
            '-ac', '2', '-ar', '44100', '-c:a', 'flac',
            '-metadata', 'title=Scanned track', '-metadata', 'album=Scanned album ' . $suffix,
            $source,
        ]);
        $encoder->mustRun();
        $original = file_get_contents($source);
        self::assertIsString($original);
        self::assertStringStartsWith('fLaC', $original);

        $slug = new LibrarySlug('scanned-playback-' . $suffix);
        $library = Library::create(
            'Scanned playback', $slug, new LibraryPath($this->libraryDirectory),
            LibraryType::Music, FilesystemType::Local,
        );
        $container->get(LibraryPortInterface::class)->save($library);

        $transport = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();
        $scan = new CommandTester((new Application(self::$kernel))->find('app:library:scan'));
        self::assertSame(Command::SUCCESS, $scan->execute(['library' => $slug->toString()]), $scan->getDisplay());
        $container->get(RelayOutboxHandler::class)(new RelayOutboxCommand());
        $discovered = array_values(array_filter(
            $transport->getSent(),
            static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof FilesDiscovered,
        ));
        self::assertCount(1, $discovered);
        $message = $discovered[0]->getMessage();
        self::assertInstanceOf(FilesDiscovered::class, $message);
        self::assertCount(1, $message->files);
        self::assertSame($source, $message->files[0]->absolutePath);

        // Deliver the scanner's queued message synchronously; all catalog and
        // playback services retain their production wiring.
        $container->get(FilesDiscoveredHandler::class)($message);
        $song = $container->get(SongRepositoryInterface::class)->findByHash($message->files[0]->hash);
        self::assertNotNull($song);
        self::assertSame($source, $song->getPath());
        self::assertSame('Scanned track', $song->getTitle());

        $listener = $this->createTestUser();
        $container->get(LibraryAccessPortInterface::class)->grant($listener->getId(), $library->getId());
        $uri = '/api/stream/track?id=' . $song->getPublicId()->toString();
        $headers = [BaanderHeader::TestUserId->serverKey() => $listener->getId()->toString()];

        $this->client->request('GET', $uri, [], [], $headers);
        $response = $this->client->getInternalResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('audio/flac', $response->getHeader('Content-Type'));
        self::assertSame('bytes', $response->getHeader('Accept-Ranges'));
        self::assertSame($original, $response->getContent());

        $this->client->request('GET', $uri, [], [], $headers + ['HTTP_RANGE' => 'bytes=100-199']);
        $range = $this->client->getInternalResponse();
        self::assertSame(206, $range->getStatusCode());
        self::assertSame(sprintf('bytes 100-199/%d', strlen($original)), $range->getHeader('Content-Range'));
        self::assertSame(substr($original, 100, 100), $range->getContent());

        $songId = $song->getId()->toString();
        $publicId = $song->getPublicId()->toString();
        $songs = $container->get(SongRepositoryInterface::class);
        $index = $container->get(LibraryFileIndexRepositoryInterface::class);
        $song->lockField('title');
        $song->updateMetadata(comment: 'Keep my notes');
        $songs->save($song);
        $songUri = '/api/songs/' . $publicId;
        $this->client->request('GET', $songUri, [], [], $headers);
        self::assertTrue(json_decode($this->client->getInternalResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['data']['available']);

        // A temporarily absent root retains its index and original catalog identity.
        $hidden = $this->libraryDirectory . '-unmounted';
        self::assertTrue(rename($this->libraryDirectory, $hidden));
        try {
            clearstatcache(true);
            self::assertSame(Command::SUCCESS, $scan->execute(['library' => $slug->toString()]));
            self::assertSame([$source], $index->findIndexedPathsByLibrary($library->getId()));
            $this->client->request('GET', $songUri, [], [], $headers);
            $missing = json_decode($this->client->getInternalResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
            self::assertFalse($missing['available']);
            self::assertSame($publicId, $missing['publicId']);
            self::assertCount(1, $songs->findByAlbum($song->getAlbumId()));
        } finally {
            self::assertTrue(rename($hidden, $this->libraryDirectory));
            clearstatcache(true);
        }

        $transport->reset();
        self::assertSame(Command::SUCCESS, $scan->execute(['library' => $slug->toString()]));
        $this->deliverDiscovered($transport);
        $this->client->request('GET', $songUri, [], [], $headers);
        self::assertTrue(json_decode($this->client->getInternalResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['data']['available']);

        // Both incremental and forced scans repair a physical rename without replacing the song.
        foreach ([false, true] as $rescan) {
            $renamed = $this->libraryDirectory . ($rescan ? '/second-rename.flac' : '/first-rename.flac');
            self::assertTrue(rename($source, $renamed));
            clearstatcache(true);
            $transport->reset();
            $arguments = ['library' => $slug->toString()];
            if ($rescan) {
                $arguments['--rescan'] = true;
            }
            self::assertSame(Command::SUCCESS, $scan->execute($arguments), $scan->getDisplay());
            $this->deliverDiscovered($transport);
            $repaired = $songs->findFreshByUuid($song->getId());
            self::assertNotNull($repaired);
            self::assertSame($songId, $repaired->getId()->toString());
            self::assertSame($publicId, $repaired->getPublicId()->toString());
            self::assertSame($renamed, $repaired->getPath());
            self::assertSame('Keep my notes', $repaired->getComment());
            self::assertTrue($repaired->isFieldLocked('title'));
            self::assertCount(1, $songs->findByAlbum($song->getAlbumId()));
            self::assertSame([$source => false, $renamed => true], $index->findAvailabilityByPaths($library->getId(), [$source, $renamed]));
            $this->client->request('GET', $uri, [], [], $headers);
            self::assertSame(200, $this->client->getInternalResponse()->getStatusCode());
            self::assertSame($original, $this->client->getInternalResponse()->getContent());
            $this->client->request('GET', $uri, [], [], $headers + ['HTTP_RANGE' => 'bytes=100-199']);
            self::assertSame(206, $this->client->getInternalResponse()->getStatusCode());
            self::assertSame(substr($original, 100, 100), $this->client->getInternalResponse()->getContent());
            $source = $renamed;
        }

        $container->get(LibraryAccessPortInterface::class)->revoke($listener->getId(), $library->getId());
        $this->client->request('GET', $uri, [], [], $headers + ['HTTP_RANGE' => 'bytes=100-199']);
        self::assertSame(403, $this->client->getInternalResponse()->getStatusCode());
    }
    private function deliverDiscovered(InMemoryTransport $transport): void
    {
        self::getContainer()->get(RelayOutboxHandler::class)(new RelayOutboxCommand());
        $messages = array_values(array_filter(
            $transport->getSent(),
            static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof FilesDiscovered,
        ));
        self::assertCount(1, $messages);
        self::getContainer()->get(FilesDiscoveredHandler::class)($messages[0]->getMessage());
    }
}
