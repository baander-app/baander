<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use App\Auth\Application\Command\User\CreateUserCommand as CreateUserMessage;
use App\Shared\Domain\Model\Email;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    name: 'app:user:create',
    description: 'Create a new user.',
)]
final class CreateUserCommand extends Command
{
    /** @var resource */
    private mixed $stdin;

    /**
     * @param resource $stdin Stream to read password from when --password is used
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
            ->addArgument('email', InputArgument::REQUIRED, 'User email address')
            ->addArgument('name', InputArgument::REQUIRED, 'Display name')
            ->addOption('password', null, InputOption::VALUE_NONE, 'Read password from stdin instead of prompting (for CI/scripting)')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'User role', 'user');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');
        $name = $input->getArgument('name');
        $useStdin = (bool) $input->getOption('password');
        $roleString = $input->getOption('role');

        try {
            new Email($email);
        } catch (\InvalidArgumentException $e) {
            $io->error('Invalid email: '.$e->getMessage());

            return Command::FAILURE;
        }

        try {
            $roles = $this->mapRoleToRoles($roleString);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($roles === ['ROLE_ADMIN'] && $input->isInteractive()) {
            if (!$io->confirm('Create user with admin privileges?', false)) {
                return Command::SUCCESS;
            }
        }

        if ($useStdin) {
            $password = $this->readPasswordFromStream($this->stdin);
            if ($password === '') {
                $io->error('No password provided via stdin.');

                return Command::FAILURE;
            }
        } else {
            $password = $io->askHidden('Password');
            if ($password === null || $password === '') {
                $io->error('Password is required.');

                return Command::FAILURE;
            }
        }

        if (\strlen($password) < 8) {
            $io->error('Password must be at least 8 characters.');

            return Command::FAILURE;
        }

        try {
            $envelope = $this->commandBus->dispatch(new CreateUserMessage(
                email: new Email($email),
                name: $name,
                plainPassword: $password,
                roles: $roles,
            ));

            $user = $envelope->last(HandledStamp::class)?->getResult();

            if (!$user instanceof \App\Auth\Domain\Model\User) {
                throw new \RuntimeException('Handler did not return a User instance.');
            }
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error('Failed to create user: '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success('User created successfully.');
        $io->table(
            ['Property', 'Value'],
            [
                ['Public ID', $user->getPublicId()->toString()],
                ['Name', $user->getName()],
                ['Email', $user->getEmail()],
                ['Role', implode(', ', $user->getRoles())],
            ],
        );

        return Command::SUCCESS;
    }

    /**
     * @return string[]
     */
    private function mapRoleToRoles(string $role): array
    {
        return match ($role) {
            'admin' => ['ROLE_ADMIN'],
            'user' => ['ROLE_USER'],
            default => throw new \InvalidArgumentException(sprintf(
                'Invalid role "%s". Allowed values: user, admin',
                $role,
            )),
        };
    }

    /**
     * @param resource $stream
     */
    private function readPasswordFromStream(mixed $stream): string
    {
        $input = '';

        while (($line = fgets($stream)) !== false) {
            $input .= $line;
        }

        return trim($input);
    }
}
