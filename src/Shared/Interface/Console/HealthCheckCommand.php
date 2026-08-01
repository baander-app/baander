<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use App\Shared\Infrastructure\Health\HealthCheckService;
use App\Shared\Infrastructure\Health\HealthStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:health:check',
    description: 'Check the health of all system components.',
)]
final class HealthCheckCommand extends Command
{
    public function __construct(
        private readonly HealthCheckService $healthCheckService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $results = $this->healthCheckService->check();

        $rows = [];
        $allHealthy = true;
        foreach ($results as $result) {
            if ($result->status === HealthStatus::Unhealthy) {
                $allHealthy = false;
            }

            $detailStrs = [];
            foreach ($result->details as $key => $value) {
                $detailStrs[] = sprintf('%s=%s', $key, is_scalar($value) ? (string) $value : json_encode($value));
            }

            $rows[] = [
                $result->component,
                match ($result->status->value) {
                    'healthy' => '<info>OK</info>',
                    'not_available' => '<comment>N/A</comment>',
                    default => '<error>FAIL</error>',
                },
                sprintf('%.2f ms', $result->responseTimeMs),
                implode(', ', $detailStrs),
            ];
        }

        $io->table(['Component', 'Status', 'Latency', 'Details'], $rows);

        if ($allHealthy) {
            $io->success('All systems healthy.');

            return Command::SUCCESS;
        }

        $io->error('One or more components are unhealthy.');

        return Command::FAILURE;
    }
}
