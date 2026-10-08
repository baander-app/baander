<?php

declare(strict_types=1);

namespace App\Lyrics\Interface\Console;

use App\Lyrics\Application\Port\LyricsAdminPortInterface;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of GET /api/admin/lyrics/sync-status. */
#[AsCommand(
    name: 'app:lyrics:status',
    description: 'Show the lyrics fetch jobs: the last one, those of the past 7 days, and how many finished or failed.',
)]
final class LyricsStatusCommand extends Command
{
    public function __construct(
        private readonly LyricsAdminPortInterface $lyricsAdmin,
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
            $status = $this->lyricsAdmin->getSyncStatus();
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $status);
        }

        $io->table(['Metric', 'Value'], [
            ['Last job', $status['lastSyncAt'] ?? 'never'],
            ['Jobs in the past 7 days', $status['recentJobs']],
            ['Finished jobs', $status['completedJobs']],
            ['Failed jobs', $status['failedJobs']],
        ]);

        return Command::SUCCESS;
    }
}
