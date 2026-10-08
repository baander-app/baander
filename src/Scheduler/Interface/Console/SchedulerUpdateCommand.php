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

/**
 * The CLI counterpart of PUT /api/admin/scheduler/jobs/{id}.
 *
 * Like the scheduler page's edit dialog, it starts from the job's current values;
 * the options given replace them.
 */
#[AsCommand(
    name: 'app:scheduler:update',
    description: 'Change a scheduled job\'s name, schedule, command, description or parameters',
)]
final class SchedulerUpdateCommand extends Command
{
    public function __construct(
        private readonly ScheduledJobAdministrationInterface $jobs,
        private readonly ScheduledJobInputReader $reader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        ScheduledJobConsole::addIdArgument($this);
        ScheduledJobInputReader::addOptions($this, update: true);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $id = ScheduledJobConsole::id($input);
            $current = ScheduledJobResource::from($this->jobs->getById($id) ?? throw ScheduledJobConsole::notFound());
            $job = ScheduledJobResource::from(
                $this->jobs->updateJob($id, $this->reader->forUpdate($input, $current)) ?? throw ScheduledJobConsole::notFound(),
            );
        } catch (Throwable $failure) {
            return AdminCommandSupport::fail($io, $failure);
        }

        $io->success(sprintf('Updated scheduled job "%s".', $job['name']));
        ScheduledJobConsole::show($io, $job);

        return Command::SUCCESS;
    }
}
