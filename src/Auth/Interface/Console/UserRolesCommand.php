<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use App\Auth\Application\Command\User\SetUserRolesCommand;
use App\Auth\Interface\Resource\AdminUserResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of POST /api/admin/users/{id}/roles. */
#[AsCommand(
    name: 'app:user:roles',
    description: 'Replace a user\'s roles with the given ones.',
)]
final class UserRolesCommand extends Command
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
            ->addArgument('roles', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'The complete set of roles: ROLE_USER, ROLE_ADMIN and/or ROLE_SUPER_ADMIN');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $roles = array_values(array_map(strval(...), (array) $input->getArgument('roles')));

        try {
            $user = AdminUserResource::from($this->support->dispatch(new SetUserRolesCommand((string) $input->getArgument('identifier'), $roles)));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        $io->success(sprintf('User %s now has the roles %s.', $user['email'], implode(', ', $user['roles'])));

        return Command::SUCCESS;
    }
}
