<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Interface\Resource\JobMonitorResource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of GET /api/monitor/jobs/{jobId}.
 */
#[AsCommand(
    name: 'app:monitor:job:show',
    description: 'Show one background job with its error, stored message and run time.',
)]
final class MonitorJobShowCommand extends Command
{
    public function __construct(
        private readonly JobMonitorAdministrationInterface $jobMonitor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('jobId', InputArgument::REQUIRED, 'The job ID, as app:monitor:jobs lists it');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $job = JobMonitorResource::detail($this->jobMonitor->job((string) $input->getArgument('jobId')));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $job);
        }

        $exception = is_array($job['exception']) ? $job['exception'] : null;
        $io->definitionList(
            ['Job ID' => $job['jobId']],
            ['Type' => $job['name'] ?? '-'],
            ['Queue' => $job['queue'] ?? '-'],
            ['Status' => $job['status']],
            ['Progress' => $job['progress'] === null ? '-' : $job['progress'] . '%'],
            ['Attempt' => (string) $job['attempt']],
            ['Retried' => $job['retried'] === true ? 'yes' : 'no'],
            ['Created' => $job['createdAt']],
            ['Started' => $job['startedAt'] ?? '-'],
            ['Finished' => $job['finishedAt'] ?? '-'],
            ['Duration' => $job['duration'] === null ? '-' : sprintf('%.3f s', $job['duration'])],
            ['Error' => $exception === null ? '-' : sprintf('%s: %s', $job['exceptionClass'] ?? '-', $exception['message'] ?? '')],
            ['Message' => $job['data'] ?? ($job['dataTruncated'] === true ? '(too large to store)' : '-')],
        );

        return Command::SUCCESS;
    }
}
