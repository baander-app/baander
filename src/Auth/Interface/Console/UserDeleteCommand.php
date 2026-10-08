<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use App\Auth\Application\Command\User\DeleteUserCommand;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of DELETE /api/admin/users/{id}. */
#[AsCommand(
    name: 'app:user:delete',
    description: 'Delete a user account.',
)]
final class UserDeleteCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('identifier', InputArgument::REQUIRED, 'User email or UUID');
        AdminCommandSupport::addForceOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $identifier = (string) $input->getArgument('identifier');

        $notConfirmed = AdminCommandSupport::confirm($input, $io, sprintf('Delete the user "%s"? This cannot be undone.', $identifier));
        if ($notConfirmed !== null) {
            return $notConfirmed;
        }

        try {
            $this->support->dispatch(new DeleteUserCommand($identifier));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        $io->success(sprintf('User "%s" has been deleted.', $identifier));

        return Command::SUCCESS;
    }
}
