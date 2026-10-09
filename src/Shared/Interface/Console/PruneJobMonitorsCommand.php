<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of POST /api/monitor/prune.
 */
#[AsCommand(
    name: 'app:monitor:prune',
    description: 'Prune completed job monitors older than a given age.',
)]
final class PruneJobMonitorsCommand extends Command
{
    public function __construct(
        private readonly JobMonitorAdministrationInterface $jobMonitor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Prune jobs older than this many days', '7')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show how many jobs would be pruned without deleting');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run') === true;

        try {
            $result = $this->jobMonitor->prune((int) $input->getOption('days'), $dryRun);
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, [
                'pruned' => $result->count,
                'olderThan' => $result->olderThan->format(\DateTimeInterface::ATOM),
            ]);
        }

        $cutoff = $result->olderThan->format('Y-m-d H:i:s');
        if ($dryRun) {
            $io->note(sprintf('Would prune %d completed job monitor(s) older than %s.', $result->count, $cutoff));
        } else {
            $io->success(sprintf('Pruned %d job monitor(s) older than %s.', $result->count, $cutoff));
        }

        return Command::SUCCESS;
    }
}
