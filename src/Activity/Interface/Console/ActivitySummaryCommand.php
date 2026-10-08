<?php

declare(strict_types=1);

namespace App\Activity\Interface\Console;

use App\Activity\Application\Port\ActivityAnalyticsPortInterface;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of GET /api/admin/activity/summary. */
#[AsCommand(
    name: 'app:activity:summary',
    description: 'Show the plays, distinct tracks and artists, and listening time over a range of days.',
)]
final class ActivitySummaryCommand extends Command
{
    public function __construct(
        private readonly ActivityAnalyticsPortInterface $analytics,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        ActivityAnalyticsOptions::configure($this, withLimit: false);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $range = ActivityAnalyticsOptions::range($input);
            $summary = $this->analytics->getSummary($range->from, $range->to);
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $summary);
        }

        ActivityAnalyticsOptions::describe($io, $range);
        $io->table(['Metric', 'Value'], [
            ['Total plays', $summary['total_plays']],
            ['Unique tracks', $summary['unique_tracks']],
            ['Unique artists', $summary['unique_artists']],
            ['Listening time (seconds)', $summary['total_listening_time']],
        ]);

        return Command::SUCCESS;
    }
}
