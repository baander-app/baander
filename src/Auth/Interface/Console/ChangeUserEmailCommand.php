<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use App\Auth\Application\Command\User\ChangeEmailCommand;
use App\Auth\Application\Exception\EmailAddressInUseException;
use App\Auth\Application\Exception\UserNotFoundException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/** The CLI counterpart of changing a user's email in PATCH /api/admin/users/{id}. */
#[AsCommand(
    name: 'app:user:change-email',
    description: 'Change a user\'s email address and send a verification link to the new address.',
)]
final class ChangeUserEmailCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('identifier', InputArgument::REQUIRED, 'User email or UUID')
            ->addArgument('email', InputArgument::REQUIRED, 'The new email address');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $identifier = (string) $input->getArgument('identifier');
        $email = (string) $input->getArgument('email');

        try {
            $this->commandBus->dispatch(new ChangeEmailCommand($identifier, $email));
        } catch (\Throwable $e) {
            $cause = $e instanceof HandlerFailedException ? ($e->getPrevious() ?? $e) : $e;
            $io->error(match (true) {
                $cause instanceof UserNotFoundException, $cause instanceof EmailAddressInUseException => $cause->getMessage(),
                $cause instanceof \InvalidArgumentException => sprintf('"%s" is not a valid email address.', $email),
                default => 'Failed to change the email address: ' . $cause->getMessage(),
            });

            return Command::FAILURE;
        }

        $io->success(sprintf('The email address of "%s" is now %s. It stays unverified until the user opens the link sent to it.', $identifier, strtolower($email)));

        return Command::SUCCESS;
    }
}
