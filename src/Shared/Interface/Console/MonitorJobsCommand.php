<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use App\Shared\Application\DTO\JobMonitorQuery;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Interface\Resource\JobMonitorResource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of GET /api/monitor/jobs.
 */
#[AsCommand(
    name: 'app:monitor:jobs',
    description: 'List background jobs with the job monitor\'s filters, sorting and cursor pages.',
)]
final class MonitorJobsCommand extends Command
{
    public function __construct(
        private readonly JobMonitorAdministrationInterface $jobMonitor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Only jobs with this status: queued, running, finished, failed or cancelled')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Only jobs whose type name contains this text')
            ->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Only jobs from this queue (exact name)')
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'Sort by createdAt, startedAt, finishedAt or duration', 'createdAt')
            ->addOption('direction', null, InputOption::VALUE_REQUIRED, 'Sort direction: asc or desc', 'desc')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Jobs per page, 1-200', (string) JobMonitorQuery::DEFAULT_LIMIT)
            ->addOption('cursor', null, InputOption::VALUE_REQUIRED, 'Continue from the next cursor a previous page printed');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $page = JobMonitorResource::page($this->jobMonitor->jobs(new JobMonitorQuery(
                status: AdminCommandSupport::stringOption($input, 'status'),
                name: AdminCommandSupport::stringOption($input, 'type'),
                queue: AdminCommandSupport::stringOption($input, 'queue'),
                sort: (string) $input->getOption('sort'),
                direction: (string) $input->getOption('direction'),
                limit: AdminCommandSupport::integerOption($input, 'limit') ?? JobMonitorQuery::DEFAULT_LIMIT,
                cursor: AdminCommandSupport::stringOption($input, 'cursor'),
            )));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $page);
        }

        AdminCommandSupport::list(
            $input,
            $io,
            $page['items'],
            ['Job ID', 'Type', 'Queue', 'Status', 'Attempt', 'Created', 'Finished'],
            static fn (array $job): array => [
                $job['jobId'],
                $job['name'] ?? '-',
                $job['queue'] ?? '-',
                $job['retried'] === true ? $job['status'] . ' (retried)' : $job['status'],
                $job['attempt'],
                $job['createdAt'],
                $job['finishedAt'] ?? '-',
            ],
            'No jobs match.',
        );

        if ($page['next_cursor'] !== null) {
            $io->text(sprintf('More jobs follow. Continue with --cursor=%s', $page['next_cursor']));
        }

        return Command::SUCCESS;
    }
}
