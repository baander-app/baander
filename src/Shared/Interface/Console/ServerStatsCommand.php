<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use App\Shared\Application\Port\ServerDiagnosticsInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of GET /api/debug/stats.
 */
#[AsCommand(
    name: 'app:server:stats',
    description: 'Show memory, process and coroutine figures of every web server worker, with the shared Redis and SSE figures.',
)]
final class ServerStatsCommand extends Command
{
    public function __construct(
        private readonly ServerDiagnosticsInterface $diagnostics,
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
            $stats = $this->diagnostics->stats();
        } catch (Throwable $e) {
            return AdminCommandSupport::fail($io, $e);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            AdminCommandSupport::json($io, $stats);

            return ServerWorkerReport::finish($io, $stats['missing_workers'], $stats['worker_errors']);
        }

        $io->section('Workers');
        $io->table(
            ['Worker', 'PID', 'Memory (MB)', 'Peak (MB)', 'Real (MB)', 'Real peak (MB)', 'Coroutines', 'Coroutine peak'],
            array_map(static fn (array $worker): array => [
                $worker['worker_id'],
                self::value($worker, 'process', 'pid'),
                self::value($worker, 'memory', 'usage'),
                self::value($worker, 'memory', 'peak'),
                self::value($worker, 'memory', 'real'),
                self::value($worker, 'memory', 'real_peak'),
                self::value($worker, 'coroutines', 'coroutine_num'),
                self::value($worker, 'coroutines', 'coroutine_peak_num'),
            ], $stats['workers']),
        );

        $io->section('Redis');
        $redis = $stats['redis'];
        if (($redis['connected'] ?? false) !== true) {
            $io->text(sprintf('Disconnected: %s', self::scalar($redis['error'] ?? 'unknown error')));
        } else {
            $io->definitionList(
                ['Ping' => ($redis['ping'] ?? false) === true ? 'PONG' : 'failed'],
                ['DB size' => self::scalar($redis['db_size'] ?? null)],
                ['Connected clients' => self::scalar($redis['connected_clients'] ?? null)],
                ['Used memory (MB)' => self::scalar($redis['used_memory'] ?? null)],
                ['Max memory (MB)' => self::scalar($redis['maxmemory'] ?? null)],
            );
        }

        $io->section('SSE');
        $io->definitionList(['Active connections' => $stats['sse']['active_connections']]);

        return ServerWorkerReport::finish($io, $stats['missing_workers'], $stats['worker_errors']);
    }

    /** @param array<string, mixed> $worker */
    private static function value(array $worker, string $group, string $key): string
    {
        $values = $worker[$group] ?? null;

        return self::scalar(is_array($values) ? ($values[$key] ?? null) : null);
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '-';
    }
}
