<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Console;

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\MetadataContentReaderPortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Application\Service\MovieLibraryIngest;
use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Catalog\Interface\Console\IngestTestVideoCommand;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Library\Application\Port\LibraryProvisioningInterface;
use App\Library\Application\Port\ProvisionedLibraryScan;
use App\Lyrics\Application\Port\LyricsFetchRequestInterface;
use App\Metadata\Application\Port\AlbumMetadataSyncRequestInterface;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Infrastructure\FFmpeg\FFprobeAdapter;
use App\Tests\Fixtures\Catalog\PassThroughTransaction;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class IngestTestVideoCommandTest extends TestCase
{
    private const string DIRECTORY = '/srv/baander-e2e/Fixture Movie';
    private const string HASH = 'fixture-hash';

    public function testPathIsTheFirstRequiredArgumentFollowedByOptionalDefaults(): void
    {
        $provisioning = $this->createMock(LibraryProvisioningInterface::class);
        $provisioning->expects($this->never())->method('provisionMovieLibrary');
        $command = $this->command($provisioning, $this->createStub(VideoRepositoryInterface::class));

        $arguments = array_values($command->getDefinition()->getArguments());
        self::assertSame(['path', 'libraryName', 'slug'], array_map(static fn ($argument) => $argument->getName(), $arguments));
        self::assertTrue($arguments[0]->isRequired());
        self::assertSame('E2E Test Movies', $arguments[1]->getDefault());
        self::assertSame('e2e-test-movies', $arguments[2]->getDefault());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Not enough arguments (missing: "path")');
        (new CommandTester($command))->execute([]);
    }

    public function testFirstRunCreatesLibraryAtTheGivenPathAndPrintsTheVideoId(): void
    {
        $libraryId = Uuid::v7();
        $video = Video::create(self::DIRECTORY . '/clip.mp4', self::HASH);
        $provisioning = $this->createMock(LibraryProvisioningInterface::class);
        $provisioning->expects($this->once())->method('provisionMovieLibrary')
            ->with('E2E Test Movies', 'e2e-test-movies', '/srv/baander-e2e')
            ->willReturn($this->scan($libraryId, created: true));
        $tester = new CommandTester($this->command($provisioning, $this->videos($video)));

        $exitCode = $tester->execute(['path' => '/srv/baander-e2e']);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString(sprintf('Created library "E2E Test Movies" (%s)', $libraryId->toString()), $display);
        self::assertStringContainsString('Ingested 1 video(s)', $display);
        self::assertStringContainsString($video->getId()->toString(), $display);
    }

    public function testRunAgainstAnExistingLibraryPrintsTheSameVideoIdWithoutCreationNotice(): void
    {
        $video = Video::create(self::DIRECTORY . '/clip.mp4', self::HASH);
        $provisioning = $this->createStub(LibraryProvisioningInterface::class);
        $provisioning->method('provisionMovieLibrary')->willReturn($this->scan(Uuid::v7(), created: false));
        $tester = new CommandTester($this->command($provisioning, $this->videos($video)));

        $exitCode = $tester->execute(['path' => '/srv/baander-e2e', 'libraryName' => 'Movie fixture', 'slug' => 'movie-fixture']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringNotContainsString('Created library', $tester->getDisplay());
        self::assertStringContainsString($video->getId()->toString(), $tester->getDisplay());
    }

    public function testDirectoryWithoutVideoFilesFails(): void
    {
        $provisioning = $this->createStub(LibraryProvisioningInterface::class);
        $provisioning->method('provisionMovieLibrary')->willReturn(
            new ProvisionedLibraryScan(Uuid::v7(), 'E2E Test Movies', false, []),
        );
        $tester = new CommandTester($this->command($provisioning, $this->createStub(VideoRepositoryInterface::class)));

        $exitCode = $tester->execute(['path' => '/srv/baander-empty']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('No video files were ingested. Is the path correct?', $tester->getDisplay());
    }

    private function command(LibraryProvisioningInterface $provisioning, VideoRepositoryInterface $videos): IngestTestVideoCommand
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $handler = new FilesDiscoveredHandler(
            $this->createStub(AlbumPortInterface::class), $this->createStub(GenrePortInterface::class),
            $this->createStub(SongPortInterface::class), $this->createStub(MoviePortInterface::class),
            $videos, $this->createStub(MetadataContentReaderPortInterface::class), new FFprobeAdapter(new JsonEncoder()), $bus,
            $this->createStub(LyricsFetchRequestInterface::class), $this->createStub(AlbumMetadataSyncRequestInterface::class), new NullLogger(),
            $this->createStub(LibraryMediaFilesInterface::class),
            new PassThroughTransaction(),
        );

        return new IngestTestVideoCommand(new MovieLibraryIngest($provisioning, $handler, $videos));
    }

    private function scan(Uuid $libraryId, bool $created): ProvisionedLibraryScan
    {
        $file = new DiscoveredFile(self::DIRECTORY . '/clip.mp4', 'Fixture Movie/clip.mp4', 'mp4', 1024, 1_700_000_000, self::HASH);

        return new ProvisionedLibraryScan($libraryId, 'E2E Test Movies', $created, [
            new FilesDiscovered($libraryId, 'movie', self::DIRECTORY, [$file]),
        ]);
    }

    private function videos(Video $video): VideoRepositoryInterface&Stub
    {
        $videos = $this->createStub(VideoRepositoryInterface::class);
        $videos->method('findByHash')->willReturnMap([[self::HASH, $video]]);

        return $videos;
    }
}
