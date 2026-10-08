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

/** The CLI counterpart of GET /api/admin/scheduler/jobs/commands. */
#[AsCommand(
    name: 'app:scheduler:commands',
    description: 'List the commands a scheduled job can run, with their parameters',
)]
final class SchedulerCommandsCommand extends Command
{
    public function __construct(
        private readonly ScheduledJobAdministrationInterface $jobs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $catalog = $this->jobs->availableCommands();
        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $catalog);
        }

        $rows = [];
        foreach ($catalog as $type => $commands) {
            foreach ($commands as $name => $command) {
                $parameters = [];
                foreach ($command['parameters'] as $parameter => $schema) {
                    $parameters[] = sprintf('%s (%s, %s)', $parameter, $schema['type'], $schema['required'] ? 'required' : 'optional');
                }
                $rows[] = [$type, $name, $command['description'], $parameters === [] ? '-' : implode("\n", $parameters)];
            }
        }

        if ($rows === []) {
            $io->text('No schedulable commands are registered.');

            return Command::SUCCESS;
        }
        $io->table(['Type', 'Command', 'Description', 'Parameters'], $rows);

        return Command::SUCCESS;
    }
}
