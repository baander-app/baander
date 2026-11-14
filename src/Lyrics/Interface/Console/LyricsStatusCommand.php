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
    description: 'Show the lyrics fetch jobs (the last one, those of the past 7 days, how many finished or failed) and the outcomes of the latest bulk fetch run.',
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

        $run = $status['latestRun'];
        if ($run === null) {
            $io->text('Latest bulk run: no recent run');

            return Command::SUCCESS;
        }

        $io->table(['Latest bulk run', 'Value'], [
            ['Finished queueing', $run['finishedAt']],
            ['Job ID', $run['jobId'] ?? 'none'],
            ['Queued', $run['queued']],
            ['Found', $run['found']],
            ['No match', $run['noMatch']],
            ['Skipped for a missing artist', $run['skippedNoArtist']],
            ['Skipped for a missing length', $run['skippedNoLength']],
            ['Rejected', $run['rejected']],
            ['Unavailable', $run['unavailable']],
        ]);

        return Command::SUCCESS;
    }
}
