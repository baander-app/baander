<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Interface\Resource\JobMonitorResource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of GET /api/monitor/status.
 */
#[AsCommand(
    name: 'app:monitor:status',
    description: 'Show background job counts by status and the jobs running now.',
)]
final class MonitorStatusCommand extends Command
{
    public function __construct(
        private readonly JobMonitorAdministrationInterface $jobMonitor,
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

        try {
            $status = JobMonitorResource::overview($this->jobMonitor->overview());
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $status);
        }

        $io->section('Jobs by status');
        if ($status['counts'] === []) {
            $io->text('No jobs are recorded.');
        } else {
            $io->table(
                ['Status', 'Jobs'],
                array_map(static fn (string $name, int $count): array => [$name, $count], array_keys($status['counts']), $status['counts']),
            );
        }

        $io->section('Running');

        return AdminCommandSupport::list(
            $input,
            $io,
            $status['running'],
            ['Job ID', 'Type', 'Queue', 'Started', 'Progress'],
            static fn (array $job): array => [
                $job['jobId'],
                $job['name'] ?? '-',
                $job['queue'] ?? '-',
                $job['startedAt'] ?? '-',
                $job['progress'] === null ? '-' : $job['progress'] . '%',
            ],
            'No jobs are running.',
        );
    }
}
