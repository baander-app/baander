<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Interface\Console;

use App\Library\Application\Command\ClaimAllLibraryScansCommand;
use App\Library\Application\Command\ClaimLibraryScanCommand;
use App\Library\Application\Command\ReleaseLibraryScanClaimCommand;
use App\Library\Application\Command\ScanLibraryCommand as ScanLibraryCommandMessage;
use App\Library\Application\DTO\LibraryScanClaimResult;
use App\Library\Application\DTO\LibraryScanSummary;
use App\Library\Application\Exception\LibraryScanAlreadyRunningException;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Interface\Console\ScanLibraryCommand;
use App\Shared\Application\DTO\InlineJobRun;
use App\Shared\Application\JobCancelledException;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Shared\Interface\Console\AdminCommandSupport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/** A console scan holds its claim only while it runs (KTD13, R7). */
final class ScanLibraryCommandTest extends TestCase
{
    /** @var list<string> */
    private array $released = [];
    /** @var list<string> */
    private array $scanned = [];
    private ScanLibraryCommand $command;

    public function testAFailedScanReleasesItsClaim(): void
    {
        $music = $this->library('music');
        $tester = $this->tester([$music], fn () => throw new \RuntimeException('disk gone'));

        self::assertSame(Command::FAILURE, $tester->execute(['library' => 'music']));

        self::assertSame([$music->getId()->toString()], $this->released);
        self::assertStringContainsString('The scan of "music" failed: disk gone', $this->display($tester));
    }

    public function testAScanCancelledFromTheJobMonitorReleasesItsClaimAndSaysSo(): void
    {
        $music = $this->library('music');
        $tester = $this->tester([$music], fn () => throw JobCancelledException::forJob('job-1'));

        self::assertSame(Command::FAILURE, $tester->execute(['library' => 'music']));

        self::assertSame([$music->getId()->toString()], $this->released);
        self::assertStringContainsString('The scan of "music" was cancelled: Job "job-1" has been cancelled.', $this->display($tester));
    }

    public function testAnInterruptedScanFailsItsJobAndReleasesItsClaim(): void
    {
        $music = $this->library('music');
        // The signal arrives while the handler runs; it unwinds through runInline, which marks the job failed.
        $tester = $this->tester([$music], fn () => $this->command->handleSignal(\SIGINT));

        self::assertSame(128 + \SIGINT, $tester->execute(['library' => 'music']));

        self::assertSame([$music->getId()->toString()], $this->released);
        self::assertStringContainsString('Interrupted by signal ' . \SIGINT, $this->display($tester));
    }

    public function testAnInterruptedScanAllStopsAndReleasesEveryClaimItHasNotFinished(): void
    {
        [$first, $second, $third] = [$this->library('first'), $this->library('second'), $this->library('third')];
        $tester = $this->tester([$first, $second, $third], fn () => $this->command->handleSignal(\SIGTERM), all: true);

        self::assertSame(128 + \SIGTERM, $tester->execute(['--all' => true]));

        self::assertSame(['first'], $this->scanned);
        self::assertSame([$first->getId()->toString(), $second->getId()->toString(), $third->getId()->toString()], $this->released);
        self::assertStringContainsString('Not started: second, third', $this->display($tester));
    }

    public function testASignalWhileNoScanRunsIsRecordedWithoutThrowing(): void
    {
        $this->tester([], fn () => null);

        self::assertFalse($this->command->handleSignal(\SIGTERM));
    }

    public function testScanAllReportsSkippedAndStartedLibraries(): void
    {
        $idle = $this->library('idle');
        $busy = $this->library('busy');
        $tester = $this->tester([$idle], fn () => new LibraryScanSummary('id', 'Idle', 'idle', 3, 3, 0, 1), all: true, skipped: [$busy]);

        self::assertSame(Command::SUCCESS, $tester->execute(['--all' => true]));

        $display = $this->display($tester);
        self::assertStringContainsString('Skipped, already scanning: busy', $display);
        self::assertStringContainsString('Started: idle', $display);
        self::assertStringContainsString('Scanned "idle": 3 files discovered, 3 processed, 0 skipped; 1 directories queued for ingestion. Job ID: job-1', $display);
        self::assertSame([], $this->released);
    }

    public function testAClaimConflictFailsWithoutScanning(): void
    {
        $tester = $this->tester([], fn () => self::fail('Nothing may scan.'), conflict: true);

        self::assertSame(Command::FAILURE, $tester->execute(['library' => 'music']));
        self::assertStringContainsString('A scan is already in progress for the library "Music".', $this->display($tester));
        self::assertSame([], $this->scanned);
    }

    public function testNamingALibraryAndAllIsInvalid(): void
    {
        $tester = $this->tester([], fn () => self::fail('Nothing may scan.'));

        self::assertSame(Command::INVALID, $tester->execute(['library' => 'music', '--all' => true]));
        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertSame(Command::INVALID, $tester->execute(['library' => 'music', '--release' => true, '--rescan' => true]));
    }

    /**
     * @param list<Library>      $claimed
     * @param callable(): mixed $handler runs as the scan's handler
     * @param list<Library>      $skipped
     */
    private function tester(array $claimed, callable $handler, bool $all = false, array $skipped = [], bool $conflict = false): CommandTester
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message) use ($claimed, $skipped, $conflict): Envelope {
            $result = match (true) {
                $message instanceof ClaimLibraryScanCommand => $conflict
                    ? throw LibraryScanAlreadyRunningException::forLibrary('Music')
                    : $claimed[0],
                $message instanceof ClaimAllLibraryScansCommand => new LibraryScanClaimResult($claimed, $skipped),
                $message instanceof ReleaseLibraryScanClaimCommand => $this->released[] = $message->library,
                default => self::fail('Unexpected message ' . $message::class),
            };

            return new Envelope($message, [new HandledStamp($result, 'handler')]);
        });
        $jobs = $this->createStub(JobMonitorAdministrationInterface::class);
        $jobs->method('runInline')->willReturnCallback(function (object $message) use ($handler): InlineJobRun {
            self::assertInstanceOf(ScanLibraryCommandMessage::class, $message);
            $this->scanned[] = $message->getLibrarySlug()->toString();

            return new InlineJobRun('job-' . count($this->scanned), $handler());
        });
        $this->command = new ScanLibraryCommand(new AdminCommandSupport($bus), $jobs);

        return new CommandTester($this->command);
    }

    private function library(string $slug): Library
    {
        return Library::create(ucfirst($slug), new LibrarySlug($slug), new LibraryPath('/media/' . $slug), LibraryType::Music, FilesystemType::Local);
    }

    private function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
