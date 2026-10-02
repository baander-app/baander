<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Event;

use App\Shared\Domain\Event\Outbox\RelayOutboxCommand;
use App\Shared\Domain\Event\Outbox\RelayOutboxHandler;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:outbox:consume', description: 'Relay durable notification events and delivery intents.')]
final class RelayOutboxWorkerCommand extends Command implements SignalableCommandInterface
{
    private bool $stopping = false;

    public function __construct(
        private readonly RelayOutboxHandler $events,
        private readonly RelayNotificationDeliveriesHandler $deliveries,
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Process one bounded batch and exit.');
        $this->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Maximum worker lifetime in seconds.', '3600');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('time-limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 86400]]);
        if ($limit === false) {
            $output->writeln('<error>Time limit must be between 1 and 86400 seconds.</error>');
            return Command::INVALID;
        }
        $this->stopping = false;
        $deadline = microtime(true) + $limit;
        do {
            $failed = false;
            // A poison event must not prevent already committed intents from being sent.
            foreach ([fn () => ($this->events)(new RelayOutboxCommand()), fn () => ($this->deliveries)()] as $relay) {
                try {
                    $relay();
                } catch (\Throwable $error) {
                    $failed = true;
                    $this->logger->error('Outbox batch failed; eligible records remain retryable.', ['exception' => $error]);
                }
            }
            $this->doctrine->getManager()->clear();
            if ($input->getOption('once')) {
                return $failed ? Command::FAILURE : Command::SUCCESS;
            }
            if (!$this->stopping && microtime(true) < $deadline) {
                usleep(1_000_000);
            }
        } while (!$this->stopping && microtime(true) < $deadline && memory_get_usage(true) < 256 * 1024 * 1024);

        return Command::SUCCESS;
    }

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        return [SIGTERM, SIGINT];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->stopping = true;
        return false;
    }
}
