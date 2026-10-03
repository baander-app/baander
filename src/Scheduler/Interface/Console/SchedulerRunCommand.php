<?php

declare(strict_types=1);

namespace App\Scheduler\Interface\Console;

use App\Scheduler\Application\Exception\SchedulerOccurrenceConflict;

use App\Scheduler\Application\Port\SchedulerManualOccurrenceRecorderInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:scheduler:run', description: 'Record a durable manual request for a scheduled job')]
final class SchedulerRunCommand extends Command
{
    public function __construct(private readonly SchedulerManualOccurrenceRecorderInterface $manualOccurrences)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'The UUID of the scheduled job');
        $this->addOption('request-id', null, InputOption::VALUE_REQUIRED, 'Request UUID; reuse after an uncertain result');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $jobId = Uuid::fromString((string) $input->getArgument('id'));
            $provided = $input->getOption('request-id');
            $requestId = $provided === null ? Uuid::generate() : Uuid::fromString((string) $provided);
        } catch (\InvalidArgumentException) {
            $output->writeln('<error>Job and request identifiers must be UUIDs.</error>');
            return Command::INVALID;
        }

        // Print identity before recording: an uncertain commit is retried with this exact UUID.
        $output->writeln(sprintf('Request UUID: %s', $requestId->toString()));
        try {
            $occurrence = $this->manualOccurrences->record($jobId, $requestId);
        } catch (SchedulerOccurrenceConflict) {
            $output->writeln('<error>Request UUID already belongs to another job or origin.</error>');
            return Command::FAILURE;
        }
        if ($occurrence === null) {
            $output->writeln(sprintf('<error>Scheduled job "%s" not found.</error>', $jobId->toString()));
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Accepted occurrence %s for asynchronous execution.</info>', $occurrence->id->toString()));
        return Command::SUCCESS;
    }
}
