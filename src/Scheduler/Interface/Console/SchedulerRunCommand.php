<?php

declare(strict_types=1);

namespace App\Scheduler\Interface\Console;

use App\Scheduler\Application\Command\ExecuteScheduledJobCommand;
use App\Scheduler\Application\Port\ScheduledJobPortInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:scheduler:run',
    description: 'Manually trigger a scheduled job by ID',
)]
final class SchedulerRunCommand extends Command
{
    public function __construct(
        private readonly ScheduledJobPortInterface $scheduledJobService,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'The UUID of the scheduled job');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('id');
        $job = $this->scheduledJobService->getById(Uuid::fromString($id));

        if ($job === null) {
            $output->writeln(sprintf('<error>Scheduled job "%s" not found.</error>', $id));

            return Command::FAILURE;
        }

        $this->messageBus->dispatch(new ExecuteScheduledJobCommand(
            jobId: $job->getId()->toString(),
            jobType: $job->getJobType()->value,
            command: $job->getCommand(),
            parameters: $job->getParameters(),
        ));

        $output->writeln(sprintf('<info>Dispatched job "%s" (%s) for execution.</info>', $job->getName(), $job->getCommand()));

        return Command::SUCCESS;
    }
}
