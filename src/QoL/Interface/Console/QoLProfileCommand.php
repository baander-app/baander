<?php

declare(strict_types=1);

namespace App\QoL\Interface\Console;

use App\QoL\Application\Port\QoLAdminPortInterface;
use App\Shared\Interface\Console\AdminCommandSupport;
use App\Shared\Interface\Console\ServerWorkerReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of PATCH /api/admin/qol/profile.
 */
#[AsCommand(
    name: 'app:qol:profile',
    description: 'Set the stream governor\'s algorithm profile in every web server worker.',
)]
final class QoLProfileCommand extends Command
{
    public function __construct(
        private readonly QoLAdminPortInterface $qol,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('profile', InputArgument::REQUIRED, 'conservative, balanced or aggressive');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $profile = $input->getArgument('profile');
        try {
            $report = $this->qol->setProfile(is_string($profile) ? $profile : '');
        } catch (Throwable $e) {
            return AdminCommandSupport::fail($io, $e);
        }

        QoLStatusTable::render($io, $report);
        $exitCode = ServerWorkerReport::finish($io, $report['missing_workers'], $report['worker_errors']);
        if ($exitCode === Command::SUCCESS) {
            $io->success(sprintf('Every worker uses the %s profile.', $profile));
        }

        return $exitCode;
    }
}
