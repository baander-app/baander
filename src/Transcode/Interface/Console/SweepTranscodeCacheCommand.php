<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Console;

use App\Scheduler\Domain\Model\SchedulableConsoleCommandInterface;
use App\Transcode\Application\CommandHandler\SweepTranscodeCacheHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sweep the transcode segment cache.
 *
 * Deletes idle video cache directories older than the TTL (default 24h) and
 * evicts least-recently-accessed directories when the cache exceeds the size
 * budget (default 50GB). Safe by construction: a directory whose job is still
 * encoding, or whose session was touched within the active-playback window
 * (default 30 min), is always retained.
 *
 * Usage:
 *   php bin/console app:transcode:cache-sweep
 *   php bin/console app:transcode:cache-sweep --dry-run
 *   php bin/console app:transcode:cache-sweep --ttl-hours=12 --max-gb=20
 */
#[AsCommand(
    name: 'app:transcode:cache-sweep',
    description: 'Sweep old transcode segment cache directories (TTL + LRU size budget).',
)]
final class SweepTranscodeCacheCommand extends Command implements SchedulableConsoleCommandInterface
{
    public function __construct(
        private readonly SweepTranscodeCacheHandler $handler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'ttl-hours',
                null,
                InputOption::VALUE_REQUIRED,
                'Delete video cache directories whose newest file is older than this many hours.',
                (string) SweepTranscodeCacheHandler::DEFAULT_TTL_HOURS,
            )
            ->addOption(
                'max-gb',
                null,
                InputOption::VALUE_REQUIRED,
                'Cache size budget in gigabytes; evict least-recently-accessed directories beyond this.',
                (string) SweepTranscodeCacheHandler::DEFAULT_MAX_GB,
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Report what would be deleted without deleting anything.',
            )
            ->addOption(
                'active-window-seconds',
                null,
                InputOption::VALUE_REQUIRED,
                'A session updated within this many seconds counts as actively watching (protects the directory).',
                (string) SweepTranscodeCacheHandler::DEFAULT_ACTIVE_WINDOW_SECONDS,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->handler->sweep([
            'ttl_hours' => (int) $input->getOption('ttl-hours'),
            'max_gb' => (float) $input->getOption('max-gb'),
            'dry_run' => (bool) $input->getOption('dry-run'),
            'active_window_seconds' => (int) $input->getOption('active-window-seconds'),
        ]);

        $verb = $result->dryRun ? 'Would delete' : 'Deleted';

        $io->section(sprintf(
            'Cache sweep (%s, before=%s, after=%s, freed=%s)',
            $result->dryRun ? 'DRY RUN' : 'applied',
            $this->formatBytes($result->totalCacheBytesBefore),
            $this->formatBytes($result->totalCacheBytesAfter),
            $this->formatBytes($result->bytesFreed),
        ));

        if ($result->deletedCount() > 0) {
            $rows = array_map(
                static fn (string $id) => ['deleted', $id],
                $result->deletedVideoIds,
            );
            (new Table($output))->setHeaders(['Action', 'Video directory'])->setRows($rows)->render();
            $io->text(sprintf('<info>%s %d director%s.</info>', $verb, $result->deletedCount(), $result->deletedCount() === 1 ? 'y' : 'ies'));
        } else {
            $io->text('<info>No directories matched the sweep policy.</info>');
        }

        if (count($result->skippedActive) > 0) {
            $io->text(sprintf('<comment>Protected %d active director%s.</comment>', count($result->skippedActive), count($result->skippedActive) === 1 ? 'y' : 'ies'));
            $io->listing($result->skippedActive);
        }

        return Command::SUCCESS;
    }

    public static function schedulerParameters(): array
    {
        return [
            'ttl-hours' => [
                'type' => 'int',
                'required' => false,
                'description' => 'Hours of idle age before a video cache directory is swept.',
                'default' => SweepTranscodeCacheHandler::DEFAULT_TTL_HOURS,
            ],
            'max-gb' => [
                'type' => 'float',
                'required' => false,
                'description' => 'Cache size budget in GB; LRU-evicts beyond this.',
                'default' => SweepTranscodeCacheHandler::DEFAULT_MAX_GB,
            ],
            'dry-run' => [
                'type' => 'bool',
                'required' => false,
                'description' => 'Report only; do not delete.',
                'default' => false,
            ],
        ];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return sprintf('%.2f GB', $bytes / 1024 / 1024 / 1024);
        }
        if ($bytes >= 1024 * 1024) {
            return sprintf('%.2f MB', $bytes / 1024 / 1024);
        }
        if ($bytes >= 1024) {
            return sprintf('%.2f KB', $bytes / 1024);
        }

        return $bytes . ' B';
    }
}
