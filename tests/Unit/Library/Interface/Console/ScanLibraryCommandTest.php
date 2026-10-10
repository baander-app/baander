<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Interface\Console;

use App\Library\Application\Command\ClaimAllLibraryScansCommand;
use App\Library\Application\Command\ClaimLibraryScanCommand;
use App\Library\Application\Command\EndLibraryScanClaimCommand;
use App\Library\Application\Command\ReleaseLibraryScanClaimCommand;
use App\Library\Application\Command\ScanLibraryCommand as ScanLibraryCommandMessage;
use App\Library\Application\DTO\LibraryScanClaim;
use App\Library\Application\DTO\LibraryScanClaimResult;
use App\Library\Application\DTO\LibraryScanSummary;
use App\Library\Application\Exception\LibraryBusyException;
use App\Library\Application\Exception\LibraryScanClaimLiveException;
use App\Library\Application\Query\GetLibraryQuery;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryClaimKind;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Interface\Console\ScanLibraryCommand;
use App\Library\Interface\Resource\LibraryResource;
use App\Shared\Application\DTO\InlineJobRun;
use App\Shared\Application\JobCancelledException;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Shared\Interface\Console\AdminCommandSupport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/** A console scan holds its claim only while it runs. */
final class ScanLibraryCommandTest extends TestCase
{
    /** @var array<string, Library> claim ID => library */
    private array $claimed = [];
    /** @var list<string> the libraries whose claims the command ended */
    private array $released = [];
    /** The kind of the claim the release stub finds. */
    private LibraryClaimKind $claimKind = LibraryClaimKind::Scan;
    /** @var list<ReleaseLibraryScanClaimCommand> */
    private array $releaseRequests = [];
    /** @var list<string> */
    private array $scanned = [];
    private ScanLibraryCommand $command;

    public function testTheScanRunsUnderTheClaimTheCommandTook(): void
    {
        $music = $this->library('music');
        $tester = $this->tester([$music], fn () => new LibraryScanSummary('id', 'Music', 'music', 1, 1, 0, 1));

        self::assertSame(Command::SUCCESS, $tester->execute(['library' => 'music']));

        self::assertSame(['music'], $this->scanned);
        self::assertSame([], $this->released);
    }

    public function testAFailedScanReleasesItsClaim(): void
    {
        $music = $this->library('music');
        $tester = $this->tester([$music], fn () => throw new \RuntimeException('disk gone'));

        self::assertSame(Command::FAILURE, $tester->execute(['library' => 'music']));

        self::assertSame(['music'], $this->released);
        self::assertStringContainsString('The scan of "music" failed: disk gone', $this->display($tester));
    }

    public function testAScanCancelledFromTheJobMonitorReleasesItsClaimAndSaysSo(): void
    {
        $music = $this->library('music');
        $tester = $this->tester([$music], fn () => throw JobCancelledException::forJob('job-1'));

        self::assertSame(Command::FAILURE, $tester->execute(['library' => 'music']));

        self::assertSame(['music'], $this->released);
        self::assertStringContainsString('The scan of "music" was cancelled: Job "job-1" has been cancelled.', $this->display($tester));
    }

    public function testAnInterruptedScanFailsItsJobAndReleasesItsClaim(): void
    {
        $music = $this->library('music');
        // The signal arrives while the handler runs; it unwinds through runInline, which marks the job failed.
        $tester = $this->tester([$music], fn () => $this->command->handleSignal(\SIGINT));

        self::assertSame(128 + \SIGINT, $tester->execute(['library' => 'music']));

        self::assertSame(['music'], $this->released);
        self::assertStringContainsString('Interrupted by signal ' . \SIGINT, $this->display($tester));
    }

    public function testAnInterruptedScanAllStopsAndReleasesEveryClaimItHasNotFinished(): void
    {
        [$first, $second, $third] = [$this->library('first'), $this->library('second'), $this->library('third')];
        $tester = $this->tester([$first, $second, $third], fn () => $this->command->handleSignal(\SIGTERM), all: true);

        self::assertSame(128 + \SIGTERM, $tester->execute(['--all' => true]));

        self::assertSame(['first'], $this->scanned);
        self::assertSame(['first', 'second', 'third'], $this->released);
        self::assertStringContainsString('Not started: second, third', $this->display($tester));
    }

    public function testASigtermBetweenTwoScansOfScanAllReleasesTheUnstartedClaimsAndExits143(): void
    {
        [$first, $second, $third] = [$this->library('first'), $this->library('second'), $this->library('third')];
        $this->tester([$first, $second, $third], fn () => new LibraryScanSummary('id', 'First', 'first', 1, 1, 0, 1), all: true);
        // SIGTERM arrives after the first scan finished, while the command reports it.
        $output = new class ($this->command) extends BufferedOutput {
            private bool $signalled = false;

            public function __construct(private readonly ScanLibraryCommand $command)
            {
                parent::__construct();
            }

            protected function doWrite(string $message, bool $newline): void
            {
                parent::doWrite($message, $newline);
                if (!$this->signalled && str_contains($message, 'Scanned "first"')) {
                    $this->signalled = true;
                    TestCase::assertFalse($this->command->handleSignal(\SIGTERM), 'No scan runs, so the signal only stops the run.');
                }
            }
        };

        $input = new ArrayInput(['--all' => true]);
        $input->setInteractive(false);
        self::assertSame(128 + \SIGTERM, $this->command->run($input, $output));

        self::assertSame(['first'], $this->scanned);
        self::assertSame(['second', 'third'], $this->released);
        $display = (string) preg_replace('/\s+/', ' ', $output->fetch());
        self::assertStringContainsString('Not started: second, third', $display);
        self::assertStringContainsString('Started: first', $display);
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

    public function testReleaseRefusesALiveClaimWithoutForce(): void
    {
        $tester = $this->tester([], fn () => self::fail('Nothing may scan.'), liveClaim: true);

        self::assertSame(Command::FAILURE, $tester->execute(['library' => 'music', '--release' => true]));

        self::assertFalse($this->releaseRequests[0]->force);
        self::assertStringContainsString('A scan holds a live claim on the library "Music"', $this->display($tester));
        self::assertStringContainsString('--force', $this->display($tester));
    }

    public function testReleaseWithForceReleasesALiveClaim(): void
    {
        $tester = $this->tester([], fn () => self::fail('Nothing may scan.'), liveClaim: true);

        self::assertSame(Command::SUCCESS, $tester->execute(['library' => 'music', '--release' => true, '--force' => true]));

        self::assertTrue($this->releaseRequests[0]->force);
        self::assertStringContainsString('Released the scan claim of "music" and marked its scan failed.', $this->display($tester));
    }

    public function testReleaseNamesADeleteClaimItRefusesOrReleases(): void
    {
        $this->claimKind = LibraryClaimKind::Delete;
        $tester = $this->tester([], fn () => self::fail('Nothing may scan.'), liveClaim: true);

        self::assertSame(Command::FAILURE, $tester->execute(['library' => 'music', '--release' => true]));
        self::assertStringContainsString('A delete with files holds a live claim on the library "Music"', $this->display($tester));

        self::assertSame(Command::SUCCESS, $tester->execute(['library' => 'music', '--release' => true, '--force' => true]));
        self::assertStringContainsString('Released the delete claim of "music"; its scan status is unchanged.', $this->display($tester));
    }

    public function testJsonPrintsTheScannedLibraryAsTheApiRendersIt(): void
    {
        $music = $this->library('music');
        $tester = $this->tester([$music], fn () => new LibraryScanSummary('id', 'Music', 'music', 1, 1, 0, 1));

        self::assertSame(Command::SUCCESS, $tester->execute(['library' => 'music', '--json' => true], ['capture_stderr_separately' => true]));

        self::assertSame(LibraryResource::from($music), json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
        self::assertStringContainsString('Scanned "music"', $tester->getErrorOutput());
    }

    public function testJsonForScanAllPrintsTheCountsTheScanAllEndpointReturns(): void
    {
        $idle = $this->library('idle');
        $busy = $this->library('busy');
        $tester = $this->tester([$idle], fn () => new LibraryScanSummary('id', 'Idle', 'idle', 3, 3, 0, 1), all: true, skipped: [$busy]);

        self::assertSame(Command::SUCCESS, $tester->execute(['--all' => true, '--json' => true], ['capture_stderr_separately' => true]));

        self::assertSame(['dispatched' => 1, 'skipped' => 1], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testNamingALibraryAndAllIsInvalid(): void
    {
        $tester = $this->tester([], fn () => self::fail('Nothing may scan.'));

        self::assertSame(Command::INVALID, $tester->execute(['library' => 'music', '--all' => true]));
        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertSame(Command::INVALID, $tester->execute(['library' => 'music', '--release' => true, '--rescan' => true]));
        self::assertSame(Command::INVALID, $tester->execute(['library' => 'music', '--release' => true, '--json' => true]));
        self::assertSame(Command::INVALID, $tester->execute(['library' => 'music', '--force' => true]));
    }

    /**
     * @param list<Library>      $claimed
     * @param callable(): mixed $handler runs as the scan's handler
     * @param list<Library>      $skipped
     */
    private function tester(array $claimed, callable $handler, bool $all = false, array $skipped = [], bool $conflict = false, bool $liveClaim = false): CommandTester
    {
        $claims = array_map(fn (Library $library): LibraryScanClaim => $this->claim($library), $claimed);
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message) use ($claims, $skipped, $conflict, $liveClaim): Envelope {
            $result = match (true) {
                $message instanceof ClaimLibraryScanCommand => $conflict
                    ? throw LibraryBusyException::heldBy('Music', LibraryClaimKind::Scan)
                    : $claims[0],
                $message instanceof ClaimAllLibraryScansCommand => new LibraryScanClaimResult($claims, $skipped),
                $message instanceof EndLibraryScanClaimCommand => $this->released[] = $this->claimed[$message->claimId]->getSlug()->toString(),
                $message instanceof ReleaseLibraryScanClaimCommand => $this->release($message, $liveClaim),
                $message instanceof GetLibraryQuery => array_find($this->claimed, static fn (Library $library): bool => $library->getId()->toString() === $message->library),
                default => self::fail('Unexpected message ' . $message::class),
            };

            return new Envelope($message, [new HandledStamp($result, 'handler')]);
        });
        $jobs = $this->createStub(JobMonitorAdministrationInterface::class);
        $jobs->method('runInline')->willReturnCallback(function (object $message) use ($handler): InlineJobRun {
            self::assertInstanceOf(ScanLibraryCommandMessage::class, $message);
            $claimId = $message->getClaimId()?->toString();
            self::assertNotNull($claimId);
            self::assertSame($message->getLibrarySlug()->toString(), $this->claimed[$claimId]->getSlug()->toString(), 'The scan runs under its own claim.');
            $this->scanned[] = $message->getLibrarySlug()->toString();

            return new InlineJobRun('job-' . count($this->scanned), $handler());
        });
        $this->command = new ScanLibraryCommand(new AdminCommandSupport($bus), $jobs);

        return new CommandTester($this->command);
    }

    private function release(ReleaseLibraryScanClaimCommand $message, bool $liveClaim): ?string
    {
        $this->releaseRequests[] = $message;
        if ($liveClaim && !$message->force) {
            throw LibraryScanClaimLiveException::heldBy('Music', $this->claimKind);
        }

        return $liveClaim ? $this->claimKind->value : null;
    }

    private function claim(Library $library): LibraryScanClaim
    {
        $claim = new LibraryScanClaim($library, new Uuid());
        $this->claimed[$claim->claimId->toString()] = $library;

        return $claim;
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
