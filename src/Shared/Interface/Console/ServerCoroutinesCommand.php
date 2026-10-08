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
 * The CLI counterpart of GET /api/debug/coroutines.
 */
#[AsCommand(
    name: 'app:server:coroutines',
    description: 'Show coroutine and channel statistics of every web server worker.',
)]
final class ServerCoroutinesCommand extends Command
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
            $coroutines = $this->diagnostics->coroutines();
        } catch (Throwable $e) {
            return AdminCommandSupport::fail($io, $e);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            AdminCommandSupport::json($io, $coroutines);

            return ServerWorkerReport::finish($io, $coroutines['missing_workers'], $coroutines['worker_errors']);
        }

        $io->table(
            ['Worker', 'Coroutines', 'Peak', 'Last CID', 'Active CIDs', 'Channels'],
            array_map(static function (array $worker): array {
                $stats = is_array($worker['coroutines'] ?? null) ? $worker['coroutines'] : [];

                return [
                    $worker['worker_id'],
                    ServerWorkerReport::scalar($stats['coroutine_num'] ?? null),
                    ServerWorkerReport::scalar($stats['coroutine_peak_num'] ?? null),
                    ServerWorkerReport::scalar($stats['coroutine_last_cid'] ?? null),
                    is_array($worker['active_cids'] ?? null) ? count($worker['active_cids']) : '-',
                    is_array($worker['channels'] ?? null) ? count($worker['channels']) : '-',
                ];
            }, $coroutines['workers']),
        );

        $channels = [];
        foreach ($coroutines['workers'] as $worker) {
            foreach (is_array($worker['channels'] ?? null) ? $worker['channels'] : [] as $channel) {
                if (is_array($channel)) {
                    $channels[] = [
                        $worker['worker_id'],
                        ServerWorkerReport::scalar($channel['name'] ?? null),
                        ServerWorkerReport::scalar($channel['queue_num'] ?? null),
                        ServerWorkerReport::scalar($channel['capacity'] ?? null),
                        ServerWorkerReport::scalar($channel['consumer_num'] ?? null),
                        ServerWorkerReport::scalar($channel['producer_num'] ?? null),
                        AdminCommandSupport::yesNo(($channel['closed'] ?? false) === true),
                    ];
                }
            }
        }
        if ($channels !== []) {
            $io->table(['Worker', 'Channel', 'Queued', 'Capacity', 'Consumers', 'Producers', 'Closed'], $channels);
        }

        return ServerWorkerReport::finish($io, $coroutines['missing_workers'], $coroutines['worker_errors']);
    }
}
