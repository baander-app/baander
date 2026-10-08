<?php

declare(strict_types=1);

namespace App\Scheduler\Interface\Console;

use App\Scheduler\Application\Port\ScheduledJobAdministrationInterface;
use App\Scheduler\Interface\Resource\ScheduledJobResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of POST /api/admin/scheduler/jobs. */
#[AsCommand(
    name: 'app:scheduler:create',
    description: 'Create a scheduled job that runs a schedulable command on a cron schedule',
)]
final class SchedulerCreateCommand extends Command
{
    public function __construct(
        private readonly ScheduledJobAdministrationInterface $jobs,
        private readonly ScheduledJobInputReader $reader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        ScheduledJobInputReader::addOptions($this, update: false);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $job = ScheduledJobResource::from($this->jobs->createJob($this->reader->forCreate($input)));
        } catch (Throwable $failure) {
            return AdminCommandSupport::fail($io, $failure);
        }

        $io->success(sprintf('Created scheduled job "%s".', $job['name']));
        ScheduledJobConsole::show($io, $job);

        return Command::SUCCESS;
    }
}
