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

/** The CLI counterpart of GET /api/admin/activity/engagement. */
#[AsCommand(
    name: 'app:activity:engagement',
    description: 'Show the active users and their average plays and listening time over a range of days.',
)]
final class ActivityEngagementCommand extends Command
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
            $engagement = $this->analytics->getEngagement($range->from, $range->to);
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $engagement);
        }

        ActivityAnalyticsOptions::describe($io, $range);
        $io->table(['Metric', 'Value'], [
            ['Active users', $engagement['active_users']],
            ['Average plays per user', sprintf('%.1f', $engagement['avg_plays_per_user'])],
            ['Average listening time per user (seconds)', sprintf('%.0f', $engagement['avg_session_length'])],
        ]);

        return Command::SUCCESS;
    }
}
