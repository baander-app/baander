<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use App\Shared\Application\FailureTransportUnavailableException;
use App\Shared\Application\Port\FailedMessageAdministrationInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The CLI counterpart of POST /api/monitor/transport/failed/flush.
 */
#[AsCommand(
    name: 'app:failed-message:flush',
    description: 'Remove every message from the failure transport, including those waiting out a retry delay.',
)]
final class FailedMessageFlushCommand extends Command
{
    public function __construct(
        private readonly FailedMessageAdministrationInterface $failedMessages,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        AdminCommandSupport::addForceOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $refused = AdminCommandSupport::confirm($input, $io, 'Remove every message from the failure transport? This cannot be undone.');
        if ($refused !== null) {
            return $refused;
        }

        try {
            $flushed = $this->failedMessages->removeAll();
        } catch (FailureTransportUnavailableException $e) {
            $io->getErrorStyle()->error(sprintf('Failure transport unavailable: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $io->success(sprintf('Removed %d failed %s.', $flushed, $flushed === 1 ? 'message' : 'messages'));

        return Command::SUCCESS;
    }
}
