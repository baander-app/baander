<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use App\Auth\Application\Command\User\RenameUserCommand;
use App\Auth\Interface\Resource\AdminUserResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of changing a user's name in PATCH /api/admin/users/{id};
 * `app:user:change-email` changes the address.
 */
#[AsCommand(
    name: 'app:user:rename',
    description: 'Change a user\'s display name.',
)]
final class UserRenameCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('identifier', InputArgument::REQUIRED, 'User email or UUID')
            ->addArgument('name', InputArgument::REQUIRED, 'The new display name');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $identifier = (string) $input->getArgument('identifier');

        try {
            $user = AdminUserResource::from($this->support->dispatch(new RenameUserCommand($identifier, (string) $input->getArgument('name'))));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $user);
        }

        $io->success(sprintf('User %s is now named "%s".', $user['email'], $user['name']));

        return Command::SUCCESS;
    }
}
