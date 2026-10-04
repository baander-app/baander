<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command\E2E;

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\MetadataContentReaderPortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Command\E2E\IngestTestVideoCommand;
use App\Library\Application\CommandHandler\CreateLibraryHandler;
use App\Library\Application\CommandHandler\ScanLibraryHandler;
use App\Library\Application\MovieScanner;
use App\Library\Application\MusicScanner;
use App\Library\Application\Port\DirectoryScannerPortInterface;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryFileIndexRepositoryInterface;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Infrastructure\Doctrine\Repository\LibraryRepository;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Transcode\Infrastructure\FFmpeg\FFprobeAdapter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Console\Tester\CommandTester;

final class IngestTestVideoCommandTest extends TestCase
{
    public function testCreatesLocalMovieLibraryBeforeScanning(): void
    {
        $entityRepository = $this->createStub(EntityRepository::class);
        $entityRepository->method('findOneBy')->willReturn(null);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturn($entityRepository);
        $libraryRepository = $this->createMock(LibraryRepositoryInterface::class);
        $libraryRepository->method('findBySlug')->willReturn(null);
        $libraryRepository->expects($this->once())->method('save')->willReturnCallback(function (Library $library): void {
            $this->assertSame(FilesystemType::Local, $library->getFilesystemType());
            $this->assertSame('Movie fixture', $library->getName());
            $this->assertSame('/tmp/baander-video-fixture', $library->getPath()->toString());
            throw new \RuntimeException('Creation observed before scan');
        });
        $directoryScanner = $this->createMock(DirectoryScannerPortInterface::class);
        $directoryScanner->expects($this->never())->method('scan');
        $fileIndex = $this->createStub(LibraryFileIndexRepositoryInterface::class);
        $logger = new NullLogger();
        $movieScanner = new MovieScanner($directoryScanner, $fileIndex, $logger);
        $musicScanner = new MusicScanner($directoryScanner, $fileIndex, $logger);
        $bus = $this->createStub(MessageBusInterface::class);
        $videos = $this->createStub(VideoRepositoryInterface::class);
        $command = new IngestTestVideoCommand(
            new LibraryRepository($manager),
            new CreateLibraryHandler($libraryRepository),
            new ScanLibraryHandler($libraryRepository, $musicScanner, $movieScanner, $this->createStub(EventDispatcherInterface::class), $bus, $logger),
            $movieScanner,
            new FilesDiscoveredHandler(
                $this->createStub(AlbumPortInterface::class), $this->createStub(GenrePortInterface::class),
                $this->createStub(SongPortInterface::class), $this->createStub(MoviePortInterface::class),
                $videos, $this->createStub(MetadataContentReaderPortInterface::class), new FFprobeAdapter(new JsonEncoder()), $bus, $logger,
            ),
            $videos,
        );
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Creation observed before scan');
        (new CommandTester($command))->execute(['libraryName' => 'Movie fixture', 'path' => '/tmp/baander-video-fixture', 'slug' => 'movie-fixture']);
    }
}
