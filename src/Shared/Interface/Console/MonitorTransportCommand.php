<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use App\Shared\Application\FailureTransportUnavailableException;
use App\Shared\Application\Port\TransportStatusInterface;
use App\Shared\Interface\Resource\TransportStatusResource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of GET /api/monitor/transport/status.
 */
#[AsCommand(
    name: 'app:monitor:transport',
    description: 'Show the async queue depth, the failed message count and whether the consumer is registered.',
)]
final class MonitorTransportCommand extends Command
{
    public function __construct(
        private readonly TransportStatusInterface $transportStatus,
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
            $status = $this->transportStatus->status();
        } catch (FailureTransportUnavailableException $e) {
            $io->getErrorStyle()->error(sprintf('Failure transport unavailable: %s', $e->getMessage()));

            return Command::FAILURE;
        } catch (Throwable $e) {
            return AdminCommandSupport::fail($io, $e);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, TransportStatusResource::from($status));
        }

        $io->definitionList(
            ['Async queue' => $status->asyncQueueDepth],
            ['Failed queue' => $status->failedQueueDepth],
            ['Consumer' => $status->consumerName],
            ['Consumer running' => AdminCommandSupport::yesNo($status->consumerRunning)],
        );

        return Command::SUCCESS;
    }
}
