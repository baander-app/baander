<?php

declare(strict_types=1);

namespace App\Scheduler\Interface\Console;

use App\Scheduler\Application\Port\ScheduledJobAdministrationInterface;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** The CLI counterpart of POST /api/admin/scheduler/jobs/{id}/pause. */
#[AsCommand(
    name: 'app:scheduler:pause',
    description: 'Pause a scheduled job; it stops running until resumed',
)]
final class SchedulerPauseCommand extends Command
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
        return ScheduledJobConsole::changeStatus(
            $input,
            new SymfonyStyle($input, $output),
            $this->jobs->pause(...),
            'Paused',
        );
    }
}
