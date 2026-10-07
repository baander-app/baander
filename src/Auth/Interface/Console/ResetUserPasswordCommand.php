<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use App\Auth\Application\Command\User\SetUserPasswordCommand;
use App\Auth\Application\Exception\PasswordPolicyException;
use App\Auth\Application\Exception\UserNotFoundException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/** The CLI counterpart of POST /api/admin/users/{id}/reset-password. */
#[AsCommand(
    name: 'app:user:reset-password',
    description: 'Set a new password for a user and sign them out of every session.',
)]
final class ResetUserPasswordCommand extends Command
{
    /** @var resource */
    private mixed $stdin;

    /**
     * @param resource $stdin Stream to read the password from when --password is used
     */
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        mixed $stdin = STDIN,
    ) {
        parent::__construct();
        $this->stdin = $stdin;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('identifier', InputArgument::REQUIRED, 'User email or UUID')
            ->addOption('password', null, InputOption::VALUE_NONE, 'Read the password from stdin instead of prompting (for CI/scripting)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $identifier = (string) $input->getArgument('identifier');

        $password = $input->getOption('password')
            ? trim((string) stream_get_contents($this->stdin))
            : (string) $io->askHidden('New password');
        if ($password === '') {
            $io->error('A password is required.');

            return Command::FAILURE;
        }

        try {
            $this->commandBus->dispatch(new SetUserPasswordCommand($identifier, $password));
        } catch (\Throwable $e) {
            $cause = $e instanceof HandlerFailedException ? ($e->getPrevious() ?? $e) : $e;
            $io->error($cause instanceof UserNotFoundException || $cause instanceof PasswordPolicyException
                ? $cause->getMessage()
                : 'Failed to reset the password: ' . $cause->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('The password of "%s" has been reset and all of their sessions signed out.', $identifier));

        return Command::SUCCESS;
    }
}
