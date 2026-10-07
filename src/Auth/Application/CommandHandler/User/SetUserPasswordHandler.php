<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\SetUserPasswordCommand;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Service\PasswordChanger;
use App\Auth\Application\Service\UserLookup;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class SetUserPasswordHandler
{
    public function __construct(
        private UserLookup $users,
        private PasswordChanger $passwordChanger,
    ) {
    }

    /**
     * @throws UserNotFoundException when no user has the email address or UUID
     */
    #[AsMessageHandler]
    public function __invoke(SetUserPasswordCommand $command): void
    {
        $this->passwordChanger->change($this->users->byIdentifier($command->identifier), $command->password);
    }
}
