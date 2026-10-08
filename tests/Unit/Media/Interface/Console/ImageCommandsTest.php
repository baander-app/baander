<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Interface\Console;

use App\Media\Application\Command\PruneMissingImagesCommand as PruneMissingImagesMessage;
use App\Media\Application\Port\MediaAdminPortInterface;
use App\Media\Interface\Console\ImageStatsCommand;
use App\Media\Interface\Console\PruneMissingImagesCommand;
use App\Shared\Application\DTO\InlineJobRun;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ImageCommandsTest extends TestCase
{
    private const array MISSING_REPORT = [
        'totalImages' => 3,
        'missingCount' => 1,
        'missingImages' => [
            ['id' => '0199bf3c-8a00-7000-8000-0000000000e5', 'path' => 'covers/ab/missing.jpg', 'type' => 'album'],
        ],
    ];

    public function testDryRunListsTheMissingImagesAndDeletesNothing(): void
    {
        $media = $this->createMock(MediaAdminPortInterface::class);
        $media->expects(self::once())->method('checkMissingImages')->willReturn(self::MISSING_REPORT);
        $media->expects(self::never())->method('pruneMissingImages');
        $jobs = $this->createMock(JobMonitorAdministrationInterface::class);
        $jobs->expects(self::never())->method('runInline');

        $tester = new CommandTester(new PruneMissingImagesCommand($media, $jobs));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true], ['interactive' => false]));
        $display = $tester->getDisplay(true);
        self::assertStringContainsString('covers/ab/missing.jpg', $display);
        self::assertStringContainsString('0199bf3c-8a00-7000-8000-0000000000e5', $display);
        self::assertStringContainsString('1 of 3 images have missing files. Nothing was deleted', preg_replace('/\s+/', ' ', $display) ?? '');
    }

    public function testDryRunJsonPrintsTheMissingCheckPayload(): void
    {
        $media = $this->createStub(MediaAdminPortInterface::class);
        $media->method('checkMissingImages')->willReturn(self::MISSING_REPORT);
        $jobs = $this->createMock(JobMonitorAdministrationInterface::class);
        $jobs->expects(self::never())->method('runInline');

        $tester = new CommandTester(new PruneMissingImagesCommand($media, $jobs));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true, '--json' => true]));
        self::assertSame(self::MISSING_REPORT, json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testPruneWithoutATerminalNeedsForce(): void
    {
        $jobs = $this->createMock(JobMonitorAdministrationInterface::class);
        $jobs->expects(self::never())->method('runInline');

        $tester = new CommandTester(new PruneMissingImagesCommand($this->createStub(MediaAdminPortInterface::class), $jobs));

        self::assertSame(Command::INVALID, $tester->execute([], ['interactive' => false]));
        self::assertStringContainsString('--force', $tester->getDisplay());
    }

    public function testDeclinedPruneDeletesNothing(): void
    {
        $jobs = $this->createMock(JobMonitorAdministrationInterface::class);
        $jobs->expects(self::never())->method('runInline');

        $tester = new CommandTester(new PruneMissingImagesCommand($this->createStub(MediaAdminPortInterface::class), $jobs));
        $tester->setInputs(['no']);

        self::assertSame(Command::FAILURE, $tester->execute([]));
    }

    public function testJsonWithoutDryRunIsRejectedBeforeAnythingIsDeleted(): void
    {
        $jobs = $this->createMock(JobMonitorAdministrationInterface::class);
        $jobs->expects(self::never())->method('runInline');

        $tester = new CommandTester(new PruneMissingImagesCommand($this->createStub(MediaAdminPortInterface::class), $jobs));

        self::assertSame(Command::INVALID, $tester->execute(['--json' => true, '--force' => true]));
    }

    public function testForcedPruneRunsThePruneMessageInlineAndReportsTheJob(): void
    {
        $media = $this->createMock(MediaAdminPortInterface::class);
        $media->expects(self::never())->method('pruneMissingImages');
        $jobs = $this->createMock(JobMonitorAdministrationInterface::class);
        $jobs->expects(self::once())
            ->method('runInline')
            ->with(self::isInstanceOf(PruneMissingImagesMessage::class))
            ->willReturn(new InlineJobRun('prune-job-1', 2));

        $tester = new CommandTester(new PruneMissingImagesCommand($media, $jobs));

        self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true], ['interactive' => false]));
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay(true)) ?? '';
        self::assertStringContainsString('Deleted 2 image record(s) with missing files.', $display);
        self::assertStringContainsString('prune-job-1', $display);
    }

    public function testFailedPruneExitsWithTheHandlersMessage(): void
    {
        $jobs = $this->createStub(JobMonitorAdministrationInterface::class);
        $jobs->method('runInline')->willThrowException(new RuntimeException('Storage unavailable'));

        $tester = new CommandTester(new PruneMissingImagesCommand($this->createStub(MediaAdminPortInterface::class), $jobs));

        self::assertSame(Command::FAILURE, $tester->execute(['--force' => true]));
        self::assertStringContainsString('Storage unavailable', $tester->getDisplay());
    }

    public function testStatsPrintsTheStoragePayloadAsJson(): void
    {
        $stats = [
            'totalImages' => 5,
            'totalSize' => 3072,
            'byType' => [
                ['type' => 'album', 'count' => 4, 'size' => 2048],
                ['type' => 'artist', 'count' => 1, 'size' => 1024],
            ],
        ];
        $media = $this->createStub(MediaAdminPortInterface::class);
        $media->method('getStorageStats')->willReturn($stats);

        $tester = new CommandTester(new ImageStatsCommand($media));

        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));
        self::assertSame($stats, json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testStatsTableShowsEachTypeAndTheTotal(): void
    {
        $media = $this->createStub(MediaAdminPortInterface::class);
        $media->method('getStorageStats')->willReturn([
            'totalImages' => 5,
            'totalSize' => 3072,
            'byType' => [
                ['type' => 'album', 'count' => 4, 'size' => 2048],
                ['type' => 'artist', 'count' => 1, 'size' => 1024],
            ],
        ]);

        $tester = new CommandTester(new ImageStatsCommand($media));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/album\s+4\s+2 KiB \(2048 bytes\)/', $display);
        self::assertMatchesRegularExpression('/artist\s+1\s+1 KiB \(1024 bytes\)/', $display);
        self::assertMatchesRegularExpression('/Total\s+5\s+3 KiB \(3072 bytes\)/', $display);
    }
}
