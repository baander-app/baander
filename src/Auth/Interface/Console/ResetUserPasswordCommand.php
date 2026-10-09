<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use App\Auth\Application\Command\User\SetUserPasswordCommand;
use App\Auth\Application\Exception\PasswordPolicyException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of POST /api/admin/users/{id}/reset-password. */
#[AsCommand(
    name: 'app:user:reset-password',
    description: 'Set a new password for a user and sign them out of every session.',
)]
final class ResetUserPasswordCommand extends Command
{
    /** The `data` of the API's response. */
    private const string RESET_MESSAGE = 'Password reset successfully.';

    /** @var resource */
    private mixed $stdin;

    /**
     * @param resource $stdin Stream to read the password from when --password is used
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
            ->addArgument('identifier', InputArgument::REQUIRED, 'User email or UUID')
            ->addOption('password', null, InputOption::VALUE_NONE, 'Read the password from stdin instead of prompting (for CI/scripting)');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $identifier = (string) $input->getArgument('identifier');

        // The question goes to stderr, so that stdout carries only the JSON with --json.
        $password = $input->getOption('password') === true
            ? trim((string) stream_get_contents($this->stdin))
            : (string) $io->getErrorStyle()->askHidden('New password');

        try {
            if ($password === '') {
                throw new InvalidInputException('A password is required.');
            }
            $this->support->dispatch(new SetUserPasswordCommand($identifier, $password));
        } catch (PasswordPolicyException $error) {
            return AdminCommandSupport::fail($io, new InvalidInputException($error->getMessage(), previous: $error));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, ['message' => self::RESET_MESSAGE]);
        }

        $io->success(sprintf('The password of "%s" has been reset and all of their sessions signed out.', $identifier));

        return Command::SUCCESS;
    }
}
