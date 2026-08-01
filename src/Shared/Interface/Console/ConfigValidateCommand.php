<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use App\Shared\Infrastructure\Health\HealthCheckResult;
use App\Shared\Infrastructure\Health\HealthCheckService;
use App\Shared\Infrastructure\Health\HealthStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:config:validate',
    description: 'Validate application configuration and check for common misconfigurations.',
)]
final class ConfigValidateCommand extends Command
{
    private const array CATEGORIES = ['all', 'env', 'connectivity', 'framework'];

    public function __construct(
        private readonly HealthCheckService $healthCheckService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'category',
                'c',
                InputOption::VALUE_REQUIRED,
                sprintf('Check category to run (%s).', implode(', ', self::CATEGORIES)),
                'all',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $category = $input->getOption('category');

        if (!in_array($category, self::CATEGORIES, true)) {
            $io->error(sprintf('Invalid category "%s". Must be one of: %s', $category, implode(', ', self::CATEGORIES)));

            return Command::FAILURE;
        }

        $results = $this->runChecks($category);

        $rows = [];
        $hasErrors = false;
        $warningCount = 0;

        foreach ($results as $result) {
            $severity = $result->details['severity'] ?? null;
            $isWarning = $severity === 'warning';
            $isError = $severity === 'error' || $result->status === HealthStatus::Unhealthy;

            if ($isError) {
                $hasErrors = true;
            } elseif ($isWarning) {
                $warningCount++;
            }

            $rows[] = [
                $result->component,
                $this->formatStatus($result),
                $this->formatSeverity($result),
                $this->formatDetails($result),
            ];
        }

        $io->table(['Component', 'Status', 'Severity', 'Details'], $rows);

        $errorCount = count(array_filter($results, fn (HealthCheckResult $r) => ($r->details['severity'] ?? null) === 'error' || $r->status === HealthStatus::Unhealthy));
        $passCount = count($results) - $errorCount - $warningCount;

        if ($hasErrors) {
            $io->error(sprintf('%d error(s), %d warning(s), %d passed.', $errorCount, $warningCount, $passCount));

            return Command::FAILURE;
        }

        if ($warningCount > 0) {
            $io->warning(sprintf('%d warning(s), %d passed. No errors found.', $warningCount, $passCount));
        } else {
            $io->success(sprintf('All %d checks passed.', count($results)));
        }

        return Command::SUCCESS;
    }

    /**
     * @return HealthCheckResult[]
     */
    private function runChecks(string $category): array
    {
        return match ($category) {
            'env' => $this->healthCheckService->checkConfiguration(),
            'connectivity' => [
                $this->healthCheckService->checkPostgreSQL(),
                $this->healthCheckService->checkRedis(),
            ],
            'framework' => array_filter(
                $this->healthCheckService->checkConfiguration(),
                fn (HealthCheckResult $r) => !str_starts_with($r->component, 'env') && $r->component !== 'app_secret' && $r->component !== 'oauth_keys' && $r->component !== 'oauth_encryption_key' && $r->component !== 'api_keys',
            ),
            default => [
                ...$this->healthCheckService->checkConfiguration(),
                $this->healthCheckService->checkPostgreSQL(),
                $this->healthCheckService->checkRedis(),
            ],
        };
    }

    private function formatStatus(HealthCheckResult $result): string
    {
        $severity = $result->details['severity'] ?? null;

        return match (true) {
            $result->status === HealthStatus::Healthy => '<info>OK</info>',
            $severity === 'warning' => '<comment>WARN</comment>',
            $result->status === HealthStatus::NotAvailable => '<fg=gray>N/A</>',
            default => '<error>FAIL</error>',
        };
    }

    private function formatSeverity(HealthCheckResult $result): string
    {
        $severity = $result->details['severity'] ?? null;

        return match ($severity) {
            'error' => '<error>error</error>',
            'warning' => '<comment>warning</comment>',
            'ok' => '<info>ok</info>',
            default => '-',
        };
    }

    private function formatDetails(HealthCheckResult $result): string
    {
        $parts = [];

        if (isset($result->details['message'])) {
            $parts[] = $result->details['message'];
        }

        if (isset($result->details['suggestion'])) {
            $parts[] = sprintf('→ %s', $result->details['suggestion']);
        }

        if ($parts === []) {
            foreach ($result->details as $key => $value) {
                if (in_array($key, ['severity', 'message', 'suggestion'], true)) {
                    continue;
                }
                $parts[] = sprintf('%s=%s', $key, is_scalar($value) ? (string) $value : json_encode($value));
            }
        }

        return implode("\n", $parts) ?: '-';
    }
}
