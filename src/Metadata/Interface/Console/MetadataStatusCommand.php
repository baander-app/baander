<?php

declare(strict_types=1);

namespace App\Metadata\Interface\Console;

use App\Metadata\Application\Port\MetadataAdminPortInterface;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of GET /api/admin/metadata/sync-status. */
#[AsCommand(
    name: 'app:metadata:status',
    description: 'Show the metadata sync status: track coverage, the last sync and the sync jobs by type.',
)]
final class MetadataStatusCommand extends Command
{
    public function __construct(
        private readonly MetadataAdminPortInterface $metadataAdmin,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $status = $this->metadataAdmin->getSyncStatus();
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $status);
        }

        $io->table(['Metric', 'Value'], [
            ['Total tracks', $status['totalTracks']],
            ['Tracks with genres', $status['syncedTracks']],
            ['Pending tracks', $status['pendingTracks']],
            ['Failed sync jobs', $status['failedTracks']],
            ['Last sync', $status['lastSyncAt'] ?? 'never'],
        ]);

        if ($status['sources'] === []) {
            $io->text('No metadata sync jobs are recorded.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Job', 'Finished', 'Failed'],
            array_map(static fn (array $source): array => [$source['name'], $source['synced'], $source['failed']], $status['sources']),
        );

        return Command::SUCCESS;
    }
}
