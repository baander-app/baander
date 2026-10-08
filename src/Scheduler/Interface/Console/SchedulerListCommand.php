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

/** The CLI counterpart of GET /api/admin/scheduler/jobs. */
#[AsCommand(
    name: 'app:scheduler:list',
    description: 'List all scheduled jobs',
)]
final class SchedulerListCommand extends Command
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
        return AdminCommandSupport::list(
            $input,
            new SymfonyStyle($input, $output),
            ScheduledJobResource::collection($this->jobs->findAll()),
            ['ID', 'Name', 'Expression', 'Type', 'Command', 'Status', 'Last Run', 'Next Run'],
            static fn (array $job): array => [
                $job['id'],
                $job['name'],
                $job['expression'],
                $job['jobType'],
                $job['command'],
                $job['status'],
                self::minutes($job['lastRunAt']),
                self::minutes($job['nextRunAt']),
            ],
            'No scheduled jobs found.',
        );
    }

    private static function minutes(mixed $instant): string
    {
        return is_string($instant) ? (new \DateTimeImmutable($instant))->format('Y-m-d H:i') : '-';
    }
}
