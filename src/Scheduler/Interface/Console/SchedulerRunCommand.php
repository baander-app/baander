<?php

declare(strict_types=1);

namespace App\Scheduler\Interface\Console;

use App\Scheduler\Application\Exception\SchedulerOccurrenceConflict;

use App\Scheduler\Application\Port\SchedulerManualOccurrenceRecorderInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

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
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // With --json, stdout carries only the API's payload; the request UUID and errors go to stderr.
        $json = AdminCommandSupport::wantsJson($input);
        $messages = $json && $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        try {
            $jobId = Uuid::fromString((string) $input->getArgument('id'));
            $provided = $input->getOption('request-id');
            $requestId = $provided === null ? Uuid::generate() : Uuid::fromString((string) $provided);
        } catch (\InvalidArgumentException) {
            $messages->writeln('<error>Job and request identifiers must be UUIDs.</error>');
            return Command::INVALID;
        }

        // Print identity before recording: an uncertain commit is retried with this exact UUID.
        $messages->writeln(sprintf('Request UUID: %s', $requestId->toString()));
        try {
            $occurrence = $this->manualOccurrences->record($jobId, $requestId);
        } catch (SchedulerOccurrenceConflict) {
            $messages->writeln('<error>Request UUID already belongs to another job or origin.</error>');
            return Command::FAILURE;
        }
        if ($occurrence === null) {
            $messages->writeln(sprintf('<error>Scheduled job "%s" not found.</error>', $jobId->toString()));
            return Command::FAILURE;
        }

        if ($json) {
            return AdminCommandSupport::json(new SymfonyStyle($input, $output), [
                'occurrenceId' => $occurrence->id->toString(),
                'jobId' => $occurrence->jobId->toString(),
            ]);
        }

        $output->writeln(sprintf('<info>Accepted occurrence %s for asynchronous execution.</info>', $occurrence->id->toString()));
        return Command::SUCCESS;
    }
}
