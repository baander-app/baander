<?php

declare(strict_types=1);

namespace App\QoL\Interface\Console;

use App\QoL\Application\Port\QoLAdminPortInterface;
use App\Shared\Interface\Console\AdminCommandSupport;
use App\Shared\Interface\Console\ServerWorkerReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of GET /api/admin/qol/streams.
 */
#[AsCommand(
    name: 'app:qol:streams',
    description: 'List the streams every web server worker has admitted, with their predicted cost.',
)]
final class QoLStreamsCommand extends Command
{
    public function __construct(
        private readonly QoLAdminPortInterface $qol,
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
            $report = $this->qol->getActiveStreams();
        } catch (Throwable $e) {
            return AdminCommandSupport::fail($io, $e);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            AdminCommandSupport::json($io, $report);
        } else {
            $rows = array_map(static fn (array $worker): array => [
                $worker['worker_id'],
                $worker['active_streams'],
                implode("\n", array_map(
                    static fn (array $stream): string => sprintf('%s %s %.2f', $stream['job_id'], $stream['quality_tier'], $stream['predicted_cost']),
                    $worker['streams'],
                )),
            ], $report['workers']);
            $rows[] = new TableSeparator();
            $rows[] = ['Total', $report['total']['active_streams'], sprintf('predicted cost %.2f', $report['total']['predicted_cost'])];
            $io->table(['Worker', 'Active streams', 'Streams (job, tier, predicted cost)'], $rows);
        }

        return ServerWorkerReport::finish($io, $report['missing_workers'], $report['worker_errors']);
    }
}
