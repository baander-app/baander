<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use App\Shared\Application\DTO\JobCancellation;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of POST /api/monitor/jobs/{jobId}/cancel.
 */
#[AsCommand(
    name: 'app:monitor:job:cancel',
    description: 'Cancel a running background job, or the work a finished job queued.',
)]
final class MonitorJobCancelCommand extends Command
{
    public function __construct(
        private readonly JobMonitorAdministrationInterface $jobMonitor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('jobId', InputArgument::REQUIRED, 'The job\'s ID');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $cancellation = $this->jobMonitor->cancel((string) $input->getArgument('jobId'));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, ['cancelled' => true]);
        }

        $io->success(match ($cancellation) {
            JobCancellation::Requested => 'Cancellation was requested. A job that checks for cancellation stops at its next checkpoint.',
            JobCancellation::QueuedWorkCancelled => 'The job had finished. The work it queued is cancelled and skipped when it comes due; the job is now cancelled.',
        });

        return Command::SUCCESS;
    }
}
