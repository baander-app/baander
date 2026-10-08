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

/** The CLI counterpart of DELETE /api/admin/scheduler/jobs/{id}. */
#[AsCommand(
    name: 'app:scheduler:delete',
    description: 'Delete a scheduled job',
)]
final class SchedulerDeleteCommand extends Command
{
    public function __construct(
        private readonly ScheduledJobAdministrationInterface $jobs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        ScheduledJobConsole::addIdArgument($this);
        AdminCommandSupport::addForceOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $id = ScheduledJobConsole::id($input);
            $job = ScheduledJobResource::from($this->jobs->getById($id) ?? throw ScheduledJobConsole::notFound());
        } catch (Throwable $failure) {
            return ScheduledJobConsole::fail($io, $failure);
        }

        $refused = AdminCommandSupport::confirm($input, $io, sprintf('Delete scheduled job "%s"?', $job['name']));
        if ($refused !== null) {
            return $refused;
        }

        try {
            if (!$this->jobs->deleteById($id)) {
                throw ScheduledJobConsole::notFound();
            }
        } catch (Throwable $failure) {
            return ScheduledJobConsole::fail($io, $failure);
        }

        $io->success(sprintf('Deleted scheduled job "%s".', $job['name']));

        return Command::SUCCESS;
    }
}
