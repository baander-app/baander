<?php

declare(strict_types=1);

namespace App\Library\Interface\Console;

use App\Library\Application\Command\ClaimAllLibraryScansCommand;
use App\Library\Application\Command\ClaimLibraryScanCommand;
use App\Library\Application\Command\EndLibraryScanClaimCommand;
use App\Library\Application\Command\ReleaseLibraryScanClaimCommand;
use App\Library\Application\Command\ScanLibraryCommand as ScanLibraryCommandMessage;
use App\Library\Application\DTO\LibraryScanClaim;
use App\Library\Application\DTO\LibraryScanClaimResult;
use App\Library\Application\DTO\LibraryScanSummary;
use App\Library\Application\Exception\LibraryScanClaimLiveException;
use App\Library\Application\Query\GetLibraryQuery;
use App\Library\Interface\Resource\LibraryResource;
use App\Library\Interface\Resource\LibraryScanAllResource;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\JobCancelledException;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Domain\ValueObject\LibraryReadScope;
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
 * SIGTERM interrupts, ends its claim. A process killed outright leaves its claim to lapse
 * with its lease.
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
            ->addOption('release', null, InputOption::VALUE_NONE, 'Only release the claim of a scan or delete with files that is gone; a scan claim marks that scan failed; refused while the claim is live')
            ->addOption('force', null, InputOption::VALUE_NONE, 'With --release, release a live claim too, whose scan or delete may still be running');
        AdminCommandSupport::addJsonOption($this);
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
        $json = AdminCommandSupport::wantsJson($input);
        $this->scanning = null;
        $this->interruptedBy = null;
        $library = $input->getArgument('library');
        $all = $input->getOption('all') === true;
        $rescan = $input->getOption('rescan') === true;
        $force = $input->getOption('force') === true;

        try {
            if ($all === is_string($library)) {
                throw new InvalidInputException('Name one library, or use --all.');
            }
            if ($input->getOption('release') === true) {
                if ($all || $rescan || $json) {
                    throw new InvalidInputException('--release takes one library and no other option than --force.');
                }

                return $this->release($io, (string) $library, $force);
            }
            if ($force) {
                throw new InvalidInputException('--force only applies to --release.');
            }

            $claims = $all
                ? $this->support->dispatch(new ClaimAllLibraryScansCommand())
                : new LibraryScanClaimResult([$this->support->dispatch(new ClaimLibraryScanCommand((string) $library))], []);
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }
        assert($claims instanceof LibraryScanClaimResult);

        // With --json, stdout carries only the JSON; the progress report goes to stderr.
        $started = $this->scanClaimed($json ? $io->getErrorStyle() : $io, $claims, $rescan, $all);
        $exitCode = match (true) {
            $this->interruptedBy !== null => 128 + $this->interruptedBy,
            in_array(false, $started, true) => Command::FAILURE,
            default => Command::SUCCESS,
        };

        if (!$json) {
            return $exitCode;
        }

        try {
            AdminCommandSupport::json($io, $all
                ? LibraryScanAllResource::from(new LibraryScanClaimResult(
                    array_values(array_filter($claims->claimed, static fn (LibraryScanClaim $claim): bool => isset($started[$claim->claimId->toString()]))),
                    $claims->skipped,
                ))
                : LibraryResource::from($this->support->dispatch(new GetLibraryQuery(
                    LibraryResource::from($claims->claimed[0]->library)['id'],
                    LibraryReadScope::unrestricted(),
                ))));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        return $exitCode;
    }

    /**
     * Runs the claimed scans; a signal can interrupt the run, which sets $interruptedBy.
     *
     * @return array<string, bool> claim ID => whether the scan completed, for every scan started
     *
     * @phpstan-impure
     */
    private function scanClaimed(SymfonyStyle $io, LibraryScanClaimResult $claims, bool $rescan, bool $all): array
    {
        if ($claims->skipped !== []) {
            $io->text(sprintf('Skipped, already scanning: %s', self::names(LibraryResource::collection($claims->skipped))));
        }
        if ($claims->claimed === []) {
            $io->text('No library to scan.');

            return [];
        }

        $started = [];
        foreach ($claims->claimed as $index => $claim) {
            if ($this->interruptedBy !== null) {
                $this->releaseUnstarted($io, array_slice($claims->claimed, $index));
                break;
            }

            $started[$claim->claimId->toString()] = $this->scan($io, $claim, $rescan);
        }

        if ($all) {
            $io->text(sprintf('Started: %s', self::names(array_map(
                static fn (LibraryScanClaim $claim): array => LibraryResource::from($claim->library),
                array_values(array_filter($claims->claimed, static fn (LibraryScanClaim $claim): bool => isset($started[$claim->claimId->toString()]))),
            ))));
        }
        if ($this->interruptedBy !== null) {
            $io->getErrorStyle()->error('Interrupted. The claims of unfinished scans are released.');
        }

        return $started;
    }

    private function scan(SymfonyStyle $io, LibraryScanClaim $claim, bool $rescan): bool
    {
        $library = LibraryResource::from($claim->library);
        $io->text(sprintf('Claimed "%s" (%s); scanning%s...', $library['name'], $library['slug'], $rescan ? ' all files again' : ''));
        $this->scanning = $library['id'];

        try {
            if ($this->interruptedBy !== null) {
                throw new LibraryScanInterrupted($this->interruptedBy);
            }
            $run = $this->jobs->runInline(ScanLibraryCommandMessage::forSlug($library['slug'], $rescan, $claim->claimId));
            $this->scanning = null;
        } catch (Throwable $exception) {
            $this->scanning = null;
            $this->endClaim($io, $claim);
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

    private function endClaim(SymfonyStyle $io, LibraryScanClaim $claim): void
    {
        try {
            // A failed scan normally ends its claim itself; this covers a scan that could not.
            $this->support->dispatch(new EndLibraryScanClaimCommand($claim->claimId->toString()));
        } catch (Throwable $exception) {
            $slug = LibraryResource::from($claim->library)['slug'];
            $io->getErrorStyle()->error(sprintf(
                'The scan claim of "%s" could not be released: %s. It lapses with its lease, or run app:library:scan %s --release --force.',
                $slug,
                $exception->getMessage(),
                $slug,
            ));
        }
    }

    /** @param list<LibraryScanClaim> $claims */
    private function releaseUnstarted(SymfonyStyle $io, array $claims): void
    {
        foreach ($claims as $claim) {
            $this->endClaim($io, $claim);
        }
        $io->text(sprintf('Not started: %s', self::names(array_map(
            static fn (LibraryScanClaim $claim): array => LibraryResource::from($claim->library),
            $claims,
        ))));
    }

    private function release(SymfonyStyle $io, string $library, bool $force): int
    {
        try {
            $released = $this->support->dispatch(new ReleaseLibraryScanClaimCommand($library, $force));
        } catch (LibraryScanClaimLiveException $exception) {
            $io->getErrorStyle()->error($exception->getMessage() . ' Release it anyway with --force once you know that process is gone.');

            return Command::FAILURE;
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        $io->success(match ($released) {
            'scan' => sprintf('Released the scan claim of "%s" and marked its scan failed.', $library),
            'delete' => sprintf('Released the delete claim of "%s"; its scan status is unchanged.', $library),
            default => sprintf('"%s" has no claim; nothing changed.', $library),
        });

        return Command::SUCCESS;
    }

    /** @param list<array<string, mixed>> $libraries */
    private static function names(array $libraries): string
    {
        return $libraries === [] ? 'none' : implode(', ', array_map(static fn (array $library): string => $library['slug'], $libraries));
    }
}
