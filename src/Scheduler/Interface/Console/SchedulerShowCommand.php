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

/** The CLI counterpart of GET /api/admin/scheduler/jobs/{id}. */
#[AsCommand(
    name: 'app:scheduler:show',
    description: 'Show a scheduled job with its schedule, parameters and last run',
)]
final class SchedulerShowCommand extends Command
{
    public function __construct(
        private readonly ScheduledJobAdministrationInterface $jobs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        ScheduledJobConsole::addIdArgument($this);
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $job = ScheduledJobResource::from(
                $this->jobs->getById(ScheduledJobConsole::id($input)) ?? throw ScheduledJobConsole::notFound(),
            );
        } catch (Throwable $failure) {
            return ScheduledJobConsole::fail($io, $failure);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $job);
        }
        ScheduledJobConsole::show($io, $job);

        return Command::SUCCESS;
    }
}
