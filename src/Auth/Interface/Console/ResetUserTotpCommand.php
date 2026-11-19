<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use App\Auth\Application\Command\User\ResetUserTotpCommand as ResetUserTotpMessage;
use App\Auth\Interface\Resource\AdminUserResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of POST /api/admin/users/{id}/reset-totp. */
#[AsCommand(
    name: 'app:user:reset-totp',
    description: 'Turn off a user\'s TOTP and sign them out of every session.',
)]
final class ResetUserTotpCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('identifier', InputArgument::REQUIRED, 'User email or public ID');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $identifier = (string) $input->getArgument('identifier');

        try {
            $user = AdminUserResource::from($this->support->dispatch(new ResetUserTotpMessage($identifier)));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $user);
        }

        $io->success(sprintf('TOTP is off for "%s", and all of their sessions are signed out. They sign in with their password alone.', $identifier));

        return Command::SUCCESS;
    }
}
