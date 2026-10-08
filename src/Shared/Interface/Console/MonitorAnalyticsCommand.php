<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use App\Shared\Application\DTO\JobAnalyticsRange;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Interface\Exception\InvalidQueryParameter;
use App\Shared\Interface\Request\QueryParameters;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\InputBag;
use Throwable;

/**
 * The CLI counterpart of GET /api/monitor/analytics/summary, /timing and /failures.
 */
#[AsCommand(
    name: 'app:monitor:analytics',
    description: 'Show background job analytics for a time range: summary, timing or failures.',
)]
final class MonitorAnalyticsCommand extends Command
{
    private const array SECTIONS = ['summary', 'timing', 'failures'];

    public function __construct(
        private readonly JobMonitorAdministrationInterface $jobMonitor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('section', null, InputOption::VALUE_REQUIRED, 'summary, timing or failures', 'summary')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Inclusive start of the job creation range, RFC 3339 with a timezone (default: 24 hours ago)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Exclusive end of the range, RFC 3339 with a timezone (default: now); at most 90 days after the start')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Recent failures to list with --section=failures, 1-200', '50');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $section = (string) $input->getOption('section');

        try {
            if (!in_array($section, self::SECTIONS, true)) {
                throw new InvalidInputException(sprintf('--section must be one of: %s.', implode(', ', self::SECTIONS)));
            }

            $range = $this->range($input);
            $data = match ($section) {
                'summary' => $this->jobMonitor->analyticsSummary($range),
                'timing' => $this->jobMonitor->analyticsTiming($range),
                default => $this->jobMonitor->analyticsFailures($range, (int) $input->getOption('limit')),
            };
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $data);
        }

        $io->text(sprintf(
            'Jobs created from %s up to %s',
            $range->from->format(\DateTimeInterface::ATOM),
            $range->to->format(\DateTimeInterface::ATOM),
        ));

        match ($section) {
            'summary' => $this->renderSummary($io, $data),
            'timing' => $this->renderTiming($io, $data),
            default => $this->renderFailures($io, $data),
        };

        return Command::SUCCESS;
    }

    /**
     * Reads --from and --to with the API's rules for its from and to query parameters.
     *
     * @throws InvalidInputException
     */
    private function range(InputInterface $input): JobAnalyticsRange
    {
        $values = array_filter(
            ['from' => $input->getOption('from'), 'to' => $input->getOption('to')],
            static fn (mixed $value): bool => is_string($value),
        );
        $options = new InputBag($values);

        try {
            return JobAnalyticsRange::resolve(
                QueryParameters::optionalDateTime($options, 'from'),
                QueryParameters::optionalDateTime($options, 'to'),
            );
        } catch (InvalidQueryParameter $exception) {
            throw new InvalidInputException(sprintf('--%s: %s', $exception->parameter, $exception->getMessage()));
        }
    }

    /** @param array<string, mixed> $summary */
    private function renderSummary(SymfonyStyle $io, array $summary): void
    {
        $io->definitionList(
            ['Success rate' => sprintf('%.2f%%', $summary['successRate'] * 100)],
            ['Completed per hour' => (string) $summary['throughputPerHour']],
        );
        $io->table(
            ['Status', 'Jobs'],
            array_map(static fn (string $status, int $count): array => [$status, $count], array_keys($summary['statusCounts']), $summary['statusCounts']),
        );
        $io->table(['Type', 'Jobs'], array_map(static fn (array $type): array => [$type['name'], $type['count']], $summary['jobTypeBreakdown']));
    }

    /** @param array<string, mixed> $timing */
    private function renderTiming(SymfonyStyle $io, array $timing): void
    {
        $io->section('Execution time of finished jobs (seconds)');
        $io->table(
            ['Type', 'Average', 'Median', 'P95'],
            array_map(static fn (array $row): array => [$row['name'], $row['avg'], $row['median'], $row['p95']], $timing['executionTimes']),
        );
        $io->section('Queue latency (seconds)');
        $io->table(['Type', 'Average'], array_map(static fn (array $row): array => [$row['name'], $row['avg']], $timing['queueLatency']));
    }

    /** @param array<string, mixed> $failures */
    private function renderFailures(SymfonyStyle $io, array $failures): void
    {
        $retries = $failures['retryFrequency'];
        $io->text(sprintf('%d of %d failed jobs were retried.', $retries['retried'], $retries['total']));
        $io->section('Most failing job types');
        $io->table(['Type', 'Failures'], array_map(static fn (array $row): array => [$row['name'], $row['count']], $failures['topFailingTypes']));
        $io->section('Most frequent errors');
        $io->table(['Error class', 'Failures'], array_map(static fn (array $row): array => [$row['class'], $row['count']], $failures['topExceptionClasses']));
        $io->section('Recent failures');
        $io->table(
            ['Job ID', 'Type', 'Error class', 'Message', 'Failed'],
            array_map(
                static fn (array $row): array => [$row['jobId'], $row['name'] ?? '-', $row['exceptionClass'] ?? '-', $row['exceptionMessage'] ?? '-', $row['failedAt'] ?? '-'],
                $failures['recentFailures'],
            ),
        );
    }
}
