<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use App\Auth\Application\Command\User\ChangeEmailCommand;
use App\Auth\Interface\Resource\AdminUserResource;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of changing a user's email in PATCH /api/admin/users/{id}. */
#[AsCommand(
    name: 'app:user:change-email',
    description: 'Change a user\'s email address and send a verification link to the new address.',
)]
final class ChangeUserEmailCommand extends Command
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
            ->addArgument('email', InputArgument::REQUIRED, 'The new email address');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $identifier = (string) $input->getArgument('identifier');
        $email = (string) $input->getArgument('email');

        try {
            $user = AdminUserResource::from($this->support->dispatch(new ChangeEmailCommand($identifier, $email)));
        } catch (\InvalidArgumentException $error) {
            // The use case rejects a malformed new address; the API answers it with 422.
            return AdminCommandSupport::fail($io, new InvalidInputException(sprintf('"%s" is not a valid email address.', $email), previous: $error));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $user);
        }

        $io->success(sprintf('The email address of "%s" is now %s. It stays unverified until the user opens the link sent to it.', $identifier, $user['email']));

        return Command::SUCCESS;
    }
}
