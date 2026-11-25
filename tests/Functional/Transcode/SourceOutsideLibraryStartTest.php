<?php

declare(strict_types=1);

namespace App\Tests\Functional\Transcode;

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
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
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Tests\Functional\TestCase;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/**
 * A film imported through a link that stayed inside its library, and retargeted outside it
 * afterwards, starts no transcode: both start routes answer as delivery does.
 */
final class SourceOutsideLibraryStartTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/baander-source-outside-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/library/store', 0700, true);
        mkdir($this->directory . '/library/Film', 0700, true);
        mkdir($this->directory . '/elsewhere', 0700, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
        parent::tearDown();
    }

    public function testAFilmWhoseLinkWasRetargetedOutsideItsLibraryStartsNoSession(): void
    {
        $container = self::getContainer();
        foreach (['/library/store/film.bin', '/elsewhere/private.mkv'] as $file) {
            (new Process(['ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
                '-f', 'lavfi', '-i', 'testsrc2=size=64x64:rate=10:duration=2', '-c:v', 'libx264', '-pix_fmt', 'yuv420p',
                '-f', 'matroska', $this->directory . $file]))->mustRun();
        }
        $link = $this->directory . '/library/Film/Film.mkv';
        self::assertTrue(symlink('../store/film.bin', $link));
        $library = $this->importMovieLibrary();
        $video = $container->get(VideoRepositoryInterface::class)->findByHash((string) hash_file('xxh3', $link));
        self::assertNotNull($video, 'A link that stays inside its library is imported.');
        $member = $this->createTestUser();
        $container->get(LibraryAccessPortInterface::class)->grant($member->getId(), $library->getId());
        self::assertTrue(unlink($link));
        self::assertTrue(symlink($this->directory . '/elsewhere/private.mkv', $link));
        $headers = [BaanderHeader::TestUserId->serverKey() => $member->getId()->toString(), 'CONTENT_TYPE' => 'application/json'];
        $publicId = $video->getPublicId()->toString();

        foreach ([
            '/api/stream/sign' => ['path' => '/api/transcode/' . $publicId . '/master.m3u8', 'requestId' => Uuid::v4()->toRfc4122()],
            '/api/transcode/sessions/' => ['videoId' => $publicId, 'qualityTier' => '360p', 'requestId' => Uuid::v4()->toRfc4122()],
        ] as $route => $payload) {
            $this->client->request('POST', $route, [], [], $headers, json_encode($payload, JSON_THROW_ON_ERROR));
            $response = $this->client->getInternalResponse();

            self::assertSame(409, $response->getStatusCode(), $route . ': ' . $response->getContent());
            self::assertSame(
                'Playback media is unavailable.',
                json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)['error']['message'],
            );
        }
        self::assertSame(0, (int) $container->get(Connection::class)->fetchOne(
            'SELECT count(*) FROM transcode_sessions WHERE video_id = :video',
            ['video' => $video->getId()->toString()],
        ));
    }

    private function importMovieLibrary(): Library
    {
        $container = self::getContainer();
        $slug = new LibrarySlug('source-outside-' . bin2hex(random_bytes(6)));
        $library = Library::create('Source outside', $slug, new LibraryPath($this->directory . '/library'), LibraryType::Movie, FilesystemType::Local);
        $container->get(LibraryPortInterface::class)->save($library);
        $transport = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();
        $scan = new CommandTester((new Application(self::$kernel))->find('app:library:scan'));
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
