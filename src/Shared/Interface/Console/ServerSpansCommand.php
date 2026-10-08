<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use App\Shared\Application\Port\ServerDiagnosticsInterface;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of GET /api/debug/spans and, with --clear, DELETE /api/debug/spans.
 */
#[AsCommand(
    name: 'app:server:spans',
    description: 'List the web server\'s recent request spans, or empty the span buffer with --clear.',
)]
final class ServerSpansCommand extends Command
{
    private const int DEFAULT_LIMIT = 100;

    public function __construct(
        private readonly ServerDiagnosticsInterface $diagnostics,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, sprintf('Number of spans to list; more than %1$d lists %1$d', ServerDiagnosticsInterface::MAX_SPANS), (string) self::DEFAULT_LIMIT)
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Empty the span buffer of every worker instead of listing it');
        AdminCommandSupport::addJsonOption($this);
        AdminCommandSupport::addForceOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('clear') === true) {
            return $this->clear($input, $io);
        }

        $limit = $input->getOption('limit');
        if (!is_string($limit) || preg_match('/^\d+$/', $limit) !== 1) {
            $io->getErrorStyle()->error('--limit must be a whole number.');

            return Command::INVALID;
        }

        try {
            $spans = $this->diagnostics->spans((int) $limit);
        } catch (Throwable $e) {
            return AdminCommandSupport::fail($io, $e);
        }

        return AdminCommandSupport::list(
            $input,
            $io,
            $spans,
            ['Started (UTC)', 'Operation', 'Status', 'Duration (ms)', 'Trace ID'],
            static fn (array $span): array => [
                self::started($span['start_time_us'] ?? null),
                is_string($span['operation_name'] ?? null) ? $span['operation_name'] : '-',
                self::status($span['attributes'] ?? null),
                is_int($span['duration_us'] ?? null) ? number_format($span['duration_us'] / 1000, 1, '.', '') : '-',
                is_string($span['trace_id'] ?? null) ? $span['trace_id'] : '-',
            ],
            'No spans recorded.',
        );
    }

    private function clear(InputInterface $input, SymfonyStyle $io): int
    {
        $declined = AdminCommandSupport::confirm($input, $io, 'Empty the span buffer of every web server worker?');
        if ($declined !== null) {
            return $declined;
        }

        try {
            $this->diagnostics->clearSpans();
        } catch (Throwable $e) {
            return AdminCommandSupport::fail($io, $e);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, ['status' => 'cleared']);
        }
        $io->success('Emptied the span buffer of every worker.');

        return Command::SUCCESS;
    }

    private static function started(mixed $startTimeUs): string
    {
        if (!is_int($startTimeUs)) {
            return '-';
        }
        $started = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', intdiv($startTimeUs, 1_000_000), $startTimeUs % 1_000_000));

        return $started === false ? '-' : $started->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    private static function status(mixed $attributes): string
    {
        $status = is_array($attributes) ? ($attributes['http.response.status_code'] ?? null) : null;

        return is_int($status) ? (string) $status : '-';
    }
}
