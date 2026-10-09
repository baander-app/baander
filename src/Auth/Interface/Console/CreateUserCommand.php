<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use App\Auth\Application\Command\User\CreateUserCommand as CreateUserMessage;
use App\Auth\Application\Exception\PasswordPolicyException;
use App\Auth\Application\Service\PasswordPolicy;
use App\Auth\Interface\Resource\AdminUserResource;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Domain\Model\Email;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of POST /api/admin/users.
 *
 * Acts with full authority: any combination of roles, without the admin panel's role
 * checks or admin.can_create_users. Granting an admin role is confirmed on a terminal
 * and otherwise needs `--force`.
 */
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
        private readonly AdminCommandSupport $support,
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
            ->addOption('role', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Role to assign: user, admin or super-admin; repeat for several', ['user']);
        AdminCommandSupport::addForceOption($this);
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        // Questions go to stderr, so that stdout carries only the JSON with --json.
        $prompts = $io->getErrorStyle();

        try {
            $email = self::email((string) $input->getArgument('email'));
            // An array input may pass a single role as a string.
            $roles = self::rolesFor(array_values(array_map(strval(...), (array) $input->getOption('role'))));
        } catch (InvalidInputException $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (array_diff($roles, ['ROLE_USER']) !== []) {
            $refused = AdminCommandSupport::confirm($input, $prompts, sprintf('Create user with the roles %s?', implode(', ', $roles)));
            if ($refused !== null) {
                return $refused;
            }
        }

        try {
            $user = $this->support->dispatch(new CreateUserMessage(
                email: $email,
                name: (string) $input->getArgument('name'),
                plainPassword: $this->password($input, $prompts),
                roles: $roles,
            ));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }
        $created = AdminUserResource::from($user);

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $created);
        }

        $io->success('User created successfully.');
        $io->table(
            ['Property', 'Value'],
            [
                ['Public ID', $created['publicId']],
                ['Name', $created['name']],
                ['Email', $created['email']],
                ['Role', implode(', ', $created['roles'])],
            ],
        );

        return Command::SUCCESS;
    }

    /** @throws InvalidInputException when the value is not an email address */
    private static function email(string $value): Email
    {
        try {
            return new Email($value);
        } catch (\InvalidArgumentException $error) {
            throw new InvalidInputException('Invalid email: ' . $error->getMessage(), previous: $error);
        }
    }

    /**
     * @param list<string> $names
     *
     * @return list<string> the roles, each once, in the order first named
     *
     * @throws InvalidInputException when a name is not a role
     */
    private static function rolesFor(array $names): array
    {
        $roles = array_map(static fn (string $name): string => match ($name) {
            'user' => 'ROLE_USER',
            'admin' => 'ROLE_ADMIN',
            'super-admin' => 'ROLE_SUPER_ADMIN',
            default => throw new InvalidInputException(sprintf(
                'Invalid role "%s". Allowed values: user, admin, super-admin',
                $name,
            )),
        }, $names);

        return array_values(array_unique($roles));
    }

    /**
     * The password from stdin with `--password`, otherwise asked for on the terminal.
     *
     * @throws InvalidInputException when the password is missing or outside the password policy
     */
    private function password(InputInterface $input, SymfonyStyle $prompts): string
    {
        if ($input->getOption('password') === true) {
            $password = $this->readPasswordFromStream($this->stdin);
            if ($password === '') {
                throw new InvalidInputException('No password provided via stdin.');
            }
        } else {
            $password = (string) $prompts->askHidden('Password');
            if ($password === '') {
                throw new InvalidInputException('Password is required.');
            }
        }

        try {
            PasswordPolicy::assertAcceptable($password);
        } catch (PasswordPolicyException $error) {
            throw new InvalidInputException($error->getMessage(), previous: $error);
        }

        return $password;
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
