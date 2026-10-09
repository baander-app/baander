<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Console;

use App\Catalog\Application\Command\BatchExtractCoversCommand;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Interface\Console\ExtractAlbumCoversCommand;
use App\Shared\Application\DTO\InlineJobRun;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ExtractAlbumCoversCommandTest extends TestCase
{
    public function testRunsOneBatchMessageInlineAndReportsTheQueuedJobs(): void
    {
        $albums = $this->createMock(AlbumPortInterface::class);
        $albums->expects(self::once())->method('countCoverlessAlbums')->willReturn(3);
        $jobs = $this->createMock(JobMonitorAdministrationInterface::class);
        $jobs->expects(self::once())
            ->method('runInline')
            ->with(self::isInstanceOf(BatchExtractCoversCommand::class))
            ->willReturn(new InlineJobRun('batch-job-1', 3));

        $tester = new CommandTester(new ExtractAlbumCoversCommand($albums, $jobs));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay(true)) ?? '';
        self::assertStringContainsString('3 album(s) have no cover art.', $display);
        self::assertStringContainsString('Queued 3 cover extraction job(s)', $display);
        self::assertStringContainsString('batch-job-1', $display);
    }

    public function testJsonPrintsTheApiPayloadWithTheBatchJobIdAndNothingElse(): void
    {
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('countCoverlessAlbums')->willReturn(3);
        $jobs = $this->createStub(JobMonitorAdministrationInterface::class);
        $jobs->method('runInline')->willReturn(new InlineJobRun('batch-job-3', 3));

        $tester = new CommandTester(new ExtractAlbumCoversCommand($albums, $jobs));

        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));
        self::assertSame(['albums' => 3, 'jobId' => 'batch-job-3'], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testRunsTheBatchAlsoWhenNoAlbumLacksACoverLikeTheApiRoute(): void
    {
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('countCoverlessAlbums')->willReturn(0);
        $jobs = $this->createMock(JobMonitorAdministrationInterface::class);
        $jobs->expects(self::once())->method('runInline')->willReturn(new InlineJobRun('batch-job-2', 0));

        $tester = new CommandTester(new ExtractAlbumCoversCommand($albums, $jobs));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Queued 0 cover extraction job(s)', preg_replace('/\s+/', ' ', $tester->getDisplay(true)) ?? '');
    }

    public function testFailedBatchExitsWithTheHandlersMessage(): void
    {
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('countCoverlessAlbums')->willReturn(2);
        $jobs = $this->createStub(JobMonitorAdministrationInterface::class);
        $jobs->method('runInline')->willThrowException(new RuntimeException('Cover queue unavailable'));

        $tester = new CommandTester(new ExtractAlbumCoversCommand($albums, $jobs));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Cover queue unavailable', $tester->getDisplay());
        self::assertStringNotContainsString('Queued', $tester->getDisplay());
    }
}
