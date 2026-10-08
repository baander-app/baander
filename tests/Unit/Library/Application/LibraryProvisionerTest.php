<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Application;

use App\Library\Application\CommandHandler\CreateLibraryHandler;
use App\Library\Application\LibraryDiscovery;
use App\Library\Application\LibraryProvisioner;
use App\Library\Application\MovieScanner;
use App\Library\Application\MusicScanner;
use App\Library\Application\Port\DirectoryScannerPortInterface;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Domain\Model\Library;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Library\Domain\Repository\LibraryFileIndexRepositoryInterface;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Infrastructure\Scanner\MediaFile;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Tests\Fixtures\Messaging\CancelAtCheckpoint;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;

final class LibraryProvisionerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-provisioning-' . bin2hex(random_bytes(8));
        (new Filesystem())->dumpFile($this->directory . '/Fixture Movie/clip.mp4', 'fixture video bytes');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testCreatesLocalMovieLibraryBeforeScanning(): void
    {
        $libraries = $this->createMock(LibraryRepositoryInterface::class);
        $libraries->method('findBySlug')->willReturn(null);
        $libraries->expects($this->once())->method('save')->willReturnCallback(function (Library $library): void {
            $this->assertSame(FilesystemType::Local, $library->getFilesystemType());
            $this->assertSame(LibraryType::Movie, $library->getType());
            $this->assertSame('Movie fixture', $library->getName());
            $this->assertSame('/tmp/baander-video-fixture', $library->getPath()->toString());
            throw new \RuntimeException('Creation observed before scan');
        });
        $directoryScanner = $this->createMock(DirectoryScannerPortInterface::class);
        $directoryScanner->expects($this->never())->method('scan');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Creation observed before scan');
        $this->provisioner($libraries, $directoryScanner)
            ->provisionMovieLibrary('Movie fixture', 'movie-fixture', '/tmp/baander-video-fixture');
    }

    public function testSecondProvisionReusesTheLibraryAndReturnsDiscoveredDirectories(): void
    {
        /** @var list<Library> $saved */
        $saved = [];
        $libraries = $this->createStub(LibraryRepositoryInterface::class);
        $libraries->method('findBySlug')->willReturnCallback(static function () use (&$saved): ?Library {
            return $saved[0] ?? null;
        });
        $libraries->method('save')->willReturnCallback(static function (Library $library) use (&$saved): void {
            $saved[] = $library;
        });
        $provisioner = $this->provisioner($libraries, $this->directoryScanner());

        $first = $provisioner->provisionMovieLibrary('E2E Test Movies', 'e2e-test-movies', $this->directory);
        $second = $provisioner->provisionMovieLibrary('E2E Test Movies', 'e2e-test-movies', $this->directory);

        self::assertTrue($first->created);
        self::assertFalse($second->created);
        self::assertTrue($first->libraryId->equals($second->libraryId));
        self::assertCount(1, array_unique(array_map(spl_object_id(...), $saved)), 'The second run must not create another library.');
        self::assertSame('E2E Test Movies', $second->libraryName);
        self::assertCount(1, $second->discoveries);
        $discovery = $second->discoveries[0];
        self::assertTrue($discovery->libraryId->equals($second->libraryId));
        self::assertSame('movie', $discovery->libraryType);
        self::assertSame($this->directory . '/Fixture Movie', $discovery->directory);
        self::assertCount(1, $discovery->files);
        self::assertSame(hash_file('xxh3', $this->directory . '/Fixture Movie/clip.mp4'), $discovery->files[0]->hash);
    }

    public function testScanningAMissingExistingLibraryReturnsNullWithoutScanning(): void
    {
        $libraries = $this->createStub(LibraryRepositoryInterface::class);
        $libraries->method('findBySlug')->willReturn(null);
        $directoryScanner = $this->createMock(DirectoryScannerPortInterface::class);
        $directoryScanner->expects($this->never())->method('scan');

        self::assertNull($this->provisioner($libraries, $directoryScanner)->scanExistingLibrary('e2e-test-movies'));
    }

    private function provisioner(LibraryRepositoryInterface $libraries, DirectoryScannerPortInterface $directoryScanner): LibraryProvisioner
    {
        $fileIndex = $this->createStub(LibraryFileIndexRepositoryInterface::class);
        $logger = new NullLogger();
        $movieScanner = new MovieScanner($directoryScanner, $fileIndex, $logger, new CancelAtCheckpoint());
        $events = $this->createStub(EventDispatcherInterface::class);
        $events->method('dispatch')->willReturnArgument(0);

        return new LibraryProvisioner(
            $libraries,
            new CreateLibraryHandler($libraries, $this->createStub(LibraryAccessPortInterface::class), new class () implements TransactionPortInterface {
                public function run(callable $operation): mixed
                {
                    return $operation();
                }
            }),
            new LibraryDiscovery($libraries, new MusicScanner($directoryScanner, $fileIndex, $logger, new CancelAtCheckpoint()), $movieScanner, $events, $logger),
            $movieScanner,
        );
    }

    private function directoryScanner(): DirectoryScannerPortInterface
    {
        $path = $this->directory . '/Fixture Movie/clip.mp4';
        $scanner = $this->createStub(DirectoryScannerPortInterface::class);
        $scanner->method('scan')->willReturn([
            new MediaFile($path, 'Fixture Movie/clip.mp4', 'mp4', (int) filesize($path), (int) filemtime($path)),
        ]);

        return $scanner;
    }
}
