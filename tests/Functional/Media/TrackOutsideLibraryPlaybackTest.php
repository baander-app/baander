<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Catalog\Domain\Repository\SongRepositoryInterface;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Application\Port\LibraryPortInterface;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Application\Http\BaanderHeader;
use App\Shared\Domain\Event\Outbox\RelayOutboxCommand;
use App\Shared\Domain\Event\Outbox\RelayOutboxHandler;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Tests\Functional\TestCase;
use App\Transcode\Application\Port\StreamAuthPortInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\BrowserKit\Response;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Process\Process;

/**
 * A track imported through a link that stayed inside its music library, and retargeted outside
 * it afterwards, is refused with one answer on every playback route, while a missing file and a
 * listener without access keep the answers each route gives them.
 */
final class TrackOutsideLibraryPlaybackTest extends TestCase
{
    private string $directory;
    private string $link;
    private string $publicId;
    private Uuid $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/baander-track-outside-' . (random_bytes(6) |> bin2hex(...));
        mkdir($this->directory . '/library/store', 0700, true);
        mkdir($this->directory . '/library/Album', 0700, true);
        mkdir($this->directory . '/elsewhere', 0700, true);
        foreach (['/library/store/track.bin', '/elsewhere/private.flac'] as $file) {
            new Process(['ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
                '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1', '-c:a', 'flac',
                '-f', 'flac', $this->directory . $file])->mustRun();
        }
        $this->link = $this->directory . '/library/Album/Track.flac';
        self::assertTrue(symlink('../store/track.bin', $this->link));
        $library = $this->importMusicLibrary();
        $song = self::getContainer()->get(SongRepositoryInterface::class)->findByHash((string) hash_file('xxh3', $this->link));
        self::assertNotNull($song, 'A link that stays inside its library is imported.');
        $this->publicId = $song->getPublicId()->toString();
        $this->member = $this->createTestUser()->getId();
        self::getContainer()->get(LibraryAccessPortInterface::class)->grant($this->member, $library->getId());
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
        parent::tearDown();
    }

    public function testATrackWhoseLinkWasRetargetedOutsideItsLibraryIsUnavailableOnEveryRoute(): void
    {
        self::assertTrue(unlink($this->link));
        self::assertTrue(symlink($this->directory . '/elsewhere/private.flac', $this->link));

        foreach ($this->playbackRoutes($this->member) as $route => $response) {
            self::assertSame(409, $response->getStatusCode(), $route . ': ' . $response->getContent());
            self::assertSame(
                ['message' => 'Playback media is unavailable.', 'code' => 409, 'details' => ['reason' => 'media_unavailable']],
                json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)['error'],
                $route,
            );
        }
    }

    public function testATrackWhoseFileIsMissingKeepsEachRoutesAnswer(): void
    {
        self::assertTrue(unlink($this->link));

        self::assertSame(
            ['delivery' => 500, 'signed original' => 403, 'legacy stream' => 404],
            array_map(static fn (Response $response): int => $response->getStatusCode(), $this->playbackRoutes($this->member)),
        );
    }

    public function testAListenerWithoutAccessIsRefusedTheSignedOriginal(): void
    {
        $stranger = $this->createTestUser()->getId();

        $this->client->request('GET', $this->signedOriginal($stranger));
        $response = $this->client->getInternalResponse();

        self::assertSame(403, $response->getStatusCode(), $response->getContent());
    }

    /** @return array{delivery: Response, 'signed original': Response, 'legacy stream': Response} */
    private function playbackRoutes(Uuid $listener): array
    {
        $headers = [BaanderHeader::TestUserId->serverKey() => $listener->toString()];
        $this->client->request('POST', '/api/stream/delivery', [], [], $headers + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'kind' => 'track',
            'id' => $this->publicId,
            'containers' => ['flac'],
            'audioCodecs' => ['flac'],
        ], JSON_THROW_ON_ERROR));
        $delivery = $this->client->getInternalResponse();
        $this->client->request('GET', $this->signedOriginal($listener));
        $original = $this->client->getInternalResponse();
        $this->client->request('GET', '/api/stream/track?id=' . $this->publicId, [], [], $headers);

        return ['delivery' => $delivery, 'signed original' => $original, 'legacy stream' => $this->client->getInternalResponse()];
    }

    private function signedOriginal(Uuid $owner): string
    {
        return self::getContainer()->get(StreamAuthPortInterface::class)->signUrl('/api/stream/original/track?' . http_build_query([
            'id' => $this->publicId,
            'owner' => $owner->toString(),
        ]))['url'];
    }

    private function importMusicLibrary(): Library
    {
        $container = self::getContainer();
        $slug = new LibrarySlug('track-outside-' . (random_bytes(6) |> bin2hex(...)));
        $library = Library::create('Track outside', $slug, new LibraryPath($this->directory . '/library'), LibraryType::Music, FilesystemType::Local);
        $container->get(LibraryPortInterface::class)->save($library);
        $transport = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();
        $scan = new CommandTester(new Application(self::$kernel)->find('app:library:scan'));
        self::assertSame(Command::SUCCESS, $scan->execute(['library' => $slug->toString()]), $scan->getDisplay());
        $container->get(RelayOutboxHandler::class)(new RelayOutboxCommand());
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof FilesDiscovered) {
                $container->get(FilesDiscoveredHandler::class)($message);
            }
        }

        return $library;
    }
}
