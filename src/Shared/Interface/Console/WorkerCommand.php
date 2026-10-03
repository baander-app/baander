<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use App\Shared\Application\DTO\WorkerRuntimeConfiguration;
use App\Shared\Application\Port\WorkerSupervisorRunnerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:worker', description: 'Run the container worker supervisor for Redis async delivery, outbox relay and scheduler polling.')]
final class WorkerCommand extends Command
{
    public function __construct(private readonly WorkerSupervisorRunnerInterface $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('deployment', null, InputOption::VALUE_REQUIRED, 'Deployment namespace; defaults to BAANDER_WORKER_NAMESPACE.')
            ->addOption('boot-id', null, InputOption::VALUE_REQUIRED, 'Fresh 32-hex boot identity; defaults to BAANDER_WORKER_BOOT_ID.')
            ->addOption('memory-mib', null, InputOption::VALUE_REQUIRED, 'Required total admission ceiling in MiB.')
            ->addOption('management-mib', null, InputOption::VALUE_REQUIRED, 'Required supervisor/helper reservation in MiB (at least 128).')
            ->addOption('consumer-mib', null, InputOption::VALUE_REQUIRED, 'Required Redis consumer reservation in MiB (at least 320).')
            ->addOption('relay-mib', null, InputOption::VALUE_REQUIRED, 'Required outbox relay reservation in MiB (at least 320).')
            ->addOption('scheduler-mib', null, InputOption::VALUE_REQUIRED, 'Required scheduler reservation in MiB (at least 320).')
            ->addOption('scheduled-console-mib', null, InputOption::VALUE_REQUIRED, 'Reservation for one synchronous scheduled console child in MiB (0 disables, otherwise at least 192).', '0')
            ->addOption('lock-dir', null, InputOption::VALUE_REQUIRED, 'Private local lock directory.', '/tmp/baander-worker-locks');
        $this->setHelp('This initial fixed-set supervisor requires container PID 1. Child exit or lost authority drains the deployment. Scheduled console execution is disabled unless explicitly reserved; its child reservation is additional to the consumer budget. Media ownership and autoscaling are not yet part of this command. Reservations are admission limits, not measured capacity or OS enforcement.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $configuration = new WorkerRuntimeConfiguration(
                $this->identity($input, 'deployment', 'BAANDER_WORKER_NAMESPACE'),
                $this->identity($input, 'boot-id', 'BAANDER_WORKER_BOOT_ID'),
                $this->bytes($input, 'memory-mib'), $this->bytes($input, 'management-mib'),
                $this->bytes($input, 'consumer-mib'), $this->bytes($input, 'relay-mib'),
                $this->stringOption($input, 'lock-dir'),
                $input->getOption('scheduled-console-mib') === '0' ? 0 : $this->bytes($input, 'scheduled-console-mib'),
                $this->bytes($input, 'scheduler-mib'),
            );
        } catch (\InvalidArgumentException $error) {
            $io->error($error->getMessage());
            return Command::INVALID;
        }
        try {
            return $this->runner->run($configuration);
        } catch (\Throwable) {
            // Environment, transport and database diagnostics can contain secrets.
            $io->error('Worker supervisor failed; the deployment must be reconciled before replacement.');
            return Command::FAILURE;
        }
    }

    private function identity(InputInterface $input, string $option, string $environment): string
    {
        $value = $input->getOption($option);
        $configured = $_SERVER[$environment] ?? $_ENV[$environment] ?? getenv($environment);
        if (is_string($configured) && $configured !== '' && $value !== null && $value !== $configured) {
            throw new \InvalidArgumentException('Worker options must match the container deployment and boot identity.');
        }
        if ($value === null) {
            $value = $configured;
        }
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException('Worker deployment and boot identity are required.');
        }
        return $value;
    }

    private function bytes(InputInterface $input, string $option): int
    {
        $value = $this->stringOption($input, $option);
        if (!preg_match('/\A[1-9][0-9]{0,6}\z/D', $value) || (int) $value > 1_048_576) {
            throw new \InvalidArgumentException('Worker memory options require positive integer MiB values of at most 1048576.');
        }
        return (int) $value * 1024 * 1024;
    }

    private function stringOption(InputInterface $input, string $option): string
    {
        $value = $input->getOption($option);
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException('All worker memory budgets and the lock directory are required.');
        }
        return $value;
    }
}
