<?php

declare(strict_types=1);

namespace App\Metadata\Interface\Console;

use App\Metadata\Application\Command\SyncMetadataCommand;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of POST /api/admin/metadata/trigger-sync.
 *
 * Runs SyncMetadataCommand in this process and records the run in the job monitor. The
 * run queues the album, song and library syncs; the workers' consumer performs them, since
 * they only call the metadata providers and write to the database.
 */
#[AsCommand(
    name: 'app:metadata:sync',
    description: 'Queue a metadata sync for every library, or with --source=genres only the genre sync.',
)]
final class MetadataSyncCommand extends Command
{
    public function __construct(
        private readonly JobMonitorAdministrationInterface $jobs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'source',
            null,
            InputOption::VALUE_REQUIRED,
            sprintf('"%s" for the genre sync only; leave it out to sync every library', SyncMetadataCommand::SOURCE_GENRES),
        );
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $source = $input->getOption('source');
        $source = is_string($source) ? $source : null;

        $json = AdminCommandSupport::wantsJson($input);

        if (!$json && $source === null) {
            $io->text('Queuing a metadata sync for every library...');
        } elseif (!$json && $source === SyncMetadataCommand::SOURCE_GENRES) {
            $io->text('Queuing a forced sync of every album and song for the genre sync...');
        }

        try {
            $run = $this->jobs->runInline(new SyncMetadataCommand($source));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        $queued = is_int($run->result) ? $run->result : 0;
        if ($json) {
            return AdminCommandSupport::json($io, ['jobsDispatched' => $queued, 'jobId' => $run->jobId]);
        }

        $io->success(sprintf('Queued %d metadata sync job(s). Job ID: %s', $queued, $run->jobId));

        return Command::SUCCESS;
    }
}
