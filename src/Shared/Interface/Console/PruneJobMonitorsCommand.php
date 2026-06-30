<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use App\Shared\Infrastructure\Messenger\JobMonitorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:monitor:prune',
    description: 'Prune completed job monitors older than a given age.',
)]
final class PruneJobMonitorsCommand extends Command
{
    public function __construct(
        private readonly JobMonitorService $jobMonitorService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Prune jobs older than this many days', '7')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show how many jobs would be pruned without deleting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = (int) $input->getOption('days');

        if ($days < 1) {
            $io->error('Days must be at least 1.');

            return Command::FAILURE;
        }

        $olderThan = new \DateTimeImmutable(sprintf('-%d days', $days));

        if ($input->getOption('dry-run')) {
            $counts = $this->jobMonitorService->countByStatus();
            $prunable = ($counts['finished'] ?? 0) + ($counts['failed'] ?? 0) + ($counts['cancelled'] ?? 0);
            $io->note(sprintf('Would prune up to %d completed job monitors older than %s.', $prunable, $olderThan->format('Y-m-d H:i:s')));

            return Command::SUCCESS;
        }

        $count = $this->jobMonitorService->prune($olderThan);

        $io->success(sprintf('Pruned %d job monitor(s) older than %s.', $count, $olderThan->format('Y-m-d H:i:s')));

        return Command::SUCCESS;
    }
}
