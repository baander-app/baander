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
 * The CLI counterpart of POST /api/admin/qol/reset.
 */
#[AsCommand(
    name: 'app:qol:reset',
    description: 'Discard the stream governor\'s learning data in every web server worker and return each to the Learning state.',
)]
final class QoLResetCommand extends Command
{
    public function __construct(
        private readonly QoLAdminPortInterface $qol,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        AdminCommandSupport::addForceOption($this);
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $refused = AdminCommandSupport::confirm($input, $io, 'Discard the learning data of every web server worker?');
        if ($refused !== null) {
            return $refused;
        }

        try {
            $report = $this->qol->resetLearning();
        } catch (Throwable $e) {
            return AdminCommandSupport::fail($io, $e);
        }

        $json = AdminCommandSupport::wantsJson($input);
        if ($json) {
            AdminCommandSupport::json($io, $report);
        } else {
            QoLStatusTable::render($io, $report);
        }
        $exitCode = ServerWorkerReport::finish($io, $report['missing_workers'], $report['worker_errors']);
        if ($exitCode === Command::SUCCESS && !$json) {
            $io->success('Every worker is back in the Learning state; the reset is saved.');
        }

        return $exitCode;
    }
}
