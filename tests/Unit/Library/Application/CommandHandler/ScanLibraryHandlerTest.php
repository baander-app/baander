<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Application\CommandHandler;

use App\Library\Application\Command\ScanLibraryCommand;
use App\Library\Application\CommandHandler\ScanLibraryHandler;
use App\Library\Application\LibraryDiscovery;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\MovieScanner;
use App\Library\Application\MusicScanner;
use App\Library\Application\Port\DirectoryScannerPortInterface;
use App\Library\Domain\Event\LibraryScanCompleted;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryFileIndexRepositoryInterface;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Infrastructure\Scanner\MediaFile;
use App\Shared\Domain\ValueObject\FilesystemType;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ScanLibraryHandlerTest extends TestCase
{
    private string $directory;
    private Library $library;
    /** @var list<string> */
    private array $timeline = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-scan-handler-' . bin2hex(random_bytes(8));
        (new Filesystem())->dumpFile($this->directory . '/Fixture Movie/clip.mp4', 'fixture video bytes');
        $this->library = Library::create('Movies', new LibrarySlug('movies'), new LibraryPath($this->directory), LibraryType::Movie, FilesystemType::Local);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testPublishesDiscoveredDirectoriesBeforeMarkingTheScanCompleted(): void
    {
        $result = ($this->handler(publishFails: false))(new ScanLibraryCommand(new LibrarySlug('movies')));

        self::assertSame($this->library->getId()->toString(), $result->libraryId);
        self::assertSame('movies', $result->slug);
        self::assertSame(1, $result->filesDiscovered);
        self::assertSame(1, $result->directoriesQueued);
        self::assertSame(['saved scanning', 'published ' . $this->directory . '/Fixture Movie', 'saved completed', 'event completed'], $this->timeline);
    }

    public function testFailedPublicationMarksTheScanFailedWithoutCompletionEvent(): void
    {
        try {
            ($this->handler(publishFails: true))(new ScanLibraryCommand(new LibrarySlug('movies')));
            self::fail('The publication failure must propagate.');
        } catch (\RuntimeException $error) {
            self::assertSame('transport down', $error->getMessage());
        }

        self::assertSame(['saved scanning', 'saved failed'], $this->timeline);
    }

    private function handler(bool $publishFails): ScanLibraryHandler
    {
        $libraries = $this->createStub(LibraryRepositoryInterface::class);
        $libraries->method('findBySlug')->willReturn($this->library);
        $libraries->method('save')->willReturnCallback(function (Library $library): void {
            $this->timeline[] = 'saved ' . $library->getDiscoveryStatus();
        });
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message) use ($publishFails): Envelope {
            if ($publishFails) {
                throw new \RuntimeException('transport down');
            }
            self::assertInstanceOf(FilesDiscovered::class, $message);
            $this->timeline[] = 'published ' . $message->directory;

            return new Envelope($message);
        });
        $events = $this->createStub(EventDispatcherInterface::class);
        $events->method('dispatch')->willReturnCallback(function (object $event): object {
            self::assertInstanceOf(LibraryScanCompleted::class, $event);
            $this->timeline[] = 'event completed';

            return $event;
        });
        $path = $this->directory . '/Fixture Movie/clip.mp4';
        $directoryScanner = $this->createStub(DirectoryScannerPortInterface::class);
        $directoryScanner->method('scan')->willReturn([
            new MediaFile($path, 'Fixture Movie/clip.mp4', 'mp4', (int) filesize($path), (int) filemtime($path)),
        ]);
        $fileIndex = $this->createStub(LibraryFileIndexRepositoryInterface::class);
        $logger = new NullLogger();

        return new ScanLibraryHandler(
            $libraries,
            new LibraryDiscovery(
                $libraries,
                new MusicScanner($directoryScanner, $fileIndex, $logger),
                new MovieScanner($directoryScanner, $fileIndex, $logger),
                $events,
                $logger,
            ),
            $bus,
        );
    }
}
