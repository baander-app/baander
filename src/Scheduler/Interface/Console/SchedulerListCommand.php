<?php

declare(strict_types=1);

namespace App\Scheduler\Interface\Console;

use App\Scheduler\Application\Port\ScheduledJobPortInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:scheduler:list',
    description: 'List all scheduled jobs',
)]
final class SchedulerListCommand extends Command
{
    public function __construct(
        private readonly ScheduledJobPortInterface $scheduledJobService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobs = $this->scheduledJobService->findAll();

        if ($jobs === []) {
            $output->writeln('<comment>No scheduled jobs found.</comment>');

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Name', 'Expression', 'Type', 'Command', 'Status', 'Last Run', 'Next Run']);
        $table->setRows(array_map(function ($job) {
            return [
                $job->getId()->toString(),
                $job->getName(),
                $job->getExpression(),
                $job->getJobType()->value,
                $job->getCommand(),
                $job->getStatus()->value,
                $job->getLastRunAt()?->format('Y-m-d H:i') ?? '-',
                $job->getNextRunAt()?->format('Y-m-d H:i') ?? '-',
            ];
        }, $jobs));

        $table->render();

        return Command::SUCCESS;
    }
}
