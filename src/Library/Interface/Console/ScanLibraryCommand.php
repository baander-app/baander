<?php

declare(strict_types=1);

namespace App\Library\Interface\Console;

use App\Library\Application\Command\ClaimAllLibraryScansCommand;
use App\Library\Application\Command\ClaimLibraryScanCommand;
use App\Library\Application\Command\ReleaseLibraryScanClaimCommand;
use App\Library\Application\Command\ScanLibraryCommand as ScanLibraryCommandMessage;
use App\Library\Application\DTO\LibraryScanClaimResult;
use App\Library\Application\DTO\LibraryScanSummary;
use App\Library\Interface\Resource\LibraryResource;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\JobCancelledException;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of POST /api/libraries/{id}/scan and POST /api/libraries/scan-all.
 *
 * The web queues scans on the Swoole task workers, which a console process cannot reach,
 * so this command claims each library and runs its scan in this process, recorded in the
 * job monitor. A scan that fails, that is cancelled from the job monitor, or that SIGINT or
 * SIGTERM interrupts, releases its claim.
 */
#[AsCommand(
    name: 'app:library:scan',
    description: 'Scan a media library, or every library, in this process.',
)]
final class ScanLibraryCommand extends Command implements SignalableCommandInterface
{
    /** The UUID of the library whose scan runs now. */
    private ?string $scanning = null;
    private ?int $interruptedBy = null;

    public function __construct(
        private readonly AdminCommandSupport $support,
        private readonly JobMonitorAdministrationInterface $jobs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('library', InputArgument::OPTIONAL, 'Library UUID or slug')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Scan every library that is not already scanning, one after another')
            ->addOption('rescan', null, InputOption::VALUE_NONE, 'Re-read files the index already knows')
            ->addOption('release', null, InputOption::VALUE_NONE, 'Only release the scan claim a killed scan left behind, marking that scan failed');
    }

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        return [\SIGINT, \SIGTERM];
    }

    /**
     * Interrupts a running scan by throwing into it, so the scan and its job-monitor record
     * are marked failed before the claim is released. Between scans it stops the run.
     */
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->interruptedBy = $signal;
        if ($this->scanning !== null) {
            throw new LibraryScanInterrupted($signal);
        }

        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->scanning = null;
        $this->interruptedBy = null;
        $library = $input->getArgument('library');
        $all = $input->getOption('all') === true;
        $rescan = $input->getOption('rescan') === true;

        try {
            if ($all === is_string($library)) {
                throw new InvalidInputException('Name one library, or use --all.');
            }
            if ($input->getOption('release') === true) {
                if ($all || $rescan) {
                    throw new InvalidInputException('--release takes one library and no other option.');
                }

                return $this->release($io, (string) $library);
            }

            $claims = $all
                ? $this->support->dispatch(new ClaimAllLibraryScansCommand())
                : new LibraryScanClaimResult([$this->support->dispatch(new ClaimLibraryScanCommand((string) $library))], []);
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }
        assert($claims instanceof LibraryScanClaimResult);

        return $this->scanClaimed($io, LibraryResource::collection($claims->claimed), LibraryResource::collection($claims->skipped), $rescan, $all);
    }

    /**
     * @param list<array<string, mixed>> $claimed
     * @param list<array<string, mixed>> $skipped
     */
    private function scanClaimed(SymfonyStyle $io, array $claimed, array $skipped, bool $rescan, bool $all): int
    {
        if ($skipped !== []) {
            $io->text(sprintf('Skipped, already scanning: %s', self::names($skipped)));
        }
        if ($claimed === []) {
            $io->text('No library to scan.');

            return Command::SUCCESS;
        }

        $started = [];
        $failed = [];
        foreach ($claimed as $index => $library) {
            if ($this->interruptedBy !== null) {
                $this->releaseUnstarted($io, array_slice($claimed, $index));
                break;
            }

            $started[] = $library;
            if (!$this->scan($io, $library, $rescan)) {
                $failed[] = $library;
            }
        }

        if ($all) {
            $io->text(sprintf('Started: %s', self::names($started)));
        }
        if ($this->interruptedBy !== null) {
            $io->getErrorStyle()->error('Interrupted. The claims of unfinished scans are released.');

            return 128 + $this->interruptedBy;
        }

        return $failed === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /** @param array<string, mixed> $library */
    private function scan(SymfonyStyle $io, array $library, bool $rescan): bool
    {
        $io->text(sprintf('Claimed "%s" (%s); scanning%s...', $library['name'], $library['slug'], $rescan ? ' all files again' : ''));
        $this->scanning = $library['id'];

        try {
            if ($this->interruptedBy !== null) {
                throw new LibraryScanInterrupted($this->interruptedBy);
            }
            $run = $this->jobs->runInline(ScanLibraryCommandMessage::forSlug($library['slug'], $rescan));
            $this->scanning = null;
        } catch (Throwable $exception) {
            $this->scanning = null;
            $this->releaseAfterFailure($io, $library);
            $io->getErrorStyle()->error(sprintf(
                $exception instanceof JobCancelledException ? 'The scan of "%s" was cancelled: %s' : 'The scan of "%s" failed: %s',
                $library['slug'],
                $exception->getMessage(),
            ));

            return false;
        }

        $summary = $run->result;
        assert($summary instanceof LibraryScanSummary);
        $io->success(sprintf(
            'Scanned "%s": %d files discovered, %d processed, %d skipped; %d directories queued for ingestion. Job ID: %s',
            $summary->slug,
            $summary->filesDiscovered,
            $summary->filesProcessed,
            $summary->filesSkipped,
            $summary->directoriesQueued,
            $run->jobId,
        ));

        return true;
    }

    /** @param array<string, mixed> $library */
    private function releaseAfterFailure(SymfonyStyle $io, array $library): void
    {
        try {
            // A failed scan normally ends its claim itself; this covers a scan that could not.
            $this->support->dispatch(new ReleaseLibraryScanClaimCommand($library['id']));
        } catch (Throwable $exception) {
            $io->getErrorStyle()->error(sprintf(
                'The scan claim of "%s" could not be released: %s. Run app:library:scan %s --release.',
                $library['slug'],
                $exception->getMessage(),
                $library['slug'],
            ));
        }
    }

    /** @param list<array<string, mixed>> $libraries */
    private function releaseUnstarted(SymfonyStyle $io, array $libraries): void
    {
        foreach ($libraries as $library) {
            $this->releaseAfterFailure($io, $library);
        }
        $io->text(sprintf('Not started: %s', self::names($libraries)));
    }

    private function release(SymfonyStyle $io, string $library): int
    {
        try {
            $released = $this->support->dispatch(new ReleaseLibraryScanClaimCommand($library));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        $io->success($released
            ? sprintf('Released the scan claim of "%s" and marked its scan failed.', $library)
            : sprintf('"%s" has no scan claim; nothing changed.', $library));

        return Command::SUCCESS;
    }

    /** @param list<array<string, mixed>> $libraries */
    private static function names(array $libraries): string
    {
        return $libraries === [] ? 'none' : implode(', ', array_map(static fn (array $library): string => $library['slug'], $libraries));
    }
}
