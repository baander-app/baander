<?php

declare(strict_types=1);

namespace App\QoL\Interface\Console;

use App\QoL\Application\Port\QoLAdminPortInterface;
use App\Shared\Interface\Console\AdminCommandSupport;
use App\Shared\Interface\Console\ServerWorkerReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of GET /api/admin/qol/status.
 */
#[AsCommand(
    name: 'app:qol:status',
    description: 'Show the stream governor status of every web server worker.',
)]
final class QoLStatusCommand extends Command
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
            $report = $this->qol->getStatus();
        } catch (Throwable $e) {
            return AdminCommandSupport::fail($io, $e);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            AdminCommandSupport::json($io, $report);
        } else {
            QoLStatusTable::render($io, $report);
        }

        return ServerWorkerReport::finish($io, $report['missing_workers'], $report['worker_errors']);
    }
}
