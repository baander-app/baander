<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use App\Shared\Application\Actor;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of POST /api/monitor/jobs/{jobId}/retry.
 */
#[AsCommand(
    name: 'app:monitor:job:retry',
    description: 'Dispatch a failed background job\'s message again under a new job ID.',
)]
final class MonitorJobRetryCommand extends Command
{
    public function __construct(
        private readonly JobMonitorAdministrationInterface $jobMonitor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('jobId', InputArgument::REQUIRED, 'The failed job\'s ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $newJobId = $this->jobMonitor->retry((string) $input->getArgument('jobId'), Actor::CLI);
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        $io->success(sprintf('The job was dispatched again as job %s.', $newJobId));

        return Command::SUCCESS;
    }
}
