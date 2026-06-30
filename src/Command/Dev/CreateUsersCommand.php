<?php

declare(strict_types=1);

namespace App\Command\Dev;

use App\Auth\Application\Command\User\CreateUserCommand;
use App\Shared\Domain\Model\Email;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Creates the standard dev users (admin + user).
 *
 * Runs as an isolated subprocess from app:dev:setup so that the Messenger bus
 * and compiled container are booted fresh — avoiding corruption when dev:setup
 * clears the cache mid-run (--fresh). Safe to run repeatedly: existing users
 * are skipped.
 */
#[AsCommand(
    name: 'app:dev:create-users',
    description: 'Create the standard development users.',
)]
final class CreateUsersCommand extends Command
{
    private const DEV_USERS = [
        [
            'email' => 'admin@baander.test',
            'name' => 'Admin User',
            'password' => 'admin',
            'roles' => ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'],
        ],
        [
            'email' => 'user@baander.test',
            'name' => 'Test User',
            'password' => 'user',
            'roles' => ['ROLE_USER'],
        ],
    ];

    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach (self::DEV_USERS as $user) {
            try {
                $this->bus->dispatch(new CreateUserCommand(
                    Email::fromString($user['email']),
                    $user['name'],
                    $user['password'],
                    $user['roles'],
                ));
                $io->text(sprintf('<info>✓</info> Created user: %s', $user['email']));
            } catch (\RuntimeException $e) {
                if (str_contains($e->getMessage(), 'already exists')) {
                    $io->text(sprintf('<comment>•</comment> User exists: %s', $user['email']));
                } else {
                    $io->error(sprintf('Failed to create user %s: %s', $user['email'], $e->getMessage()));

                    return Command::FAILURE;
                }
            }
        }

        $io->table(['Email', 'Password', 'Roles'], array_map(
            fn ($u) => [$u['email'], $u['password'], implode(', ', $u['roles'])],
            self::DEV_USERS,
        ));

        return Command::SUCCESS;
    }
}
