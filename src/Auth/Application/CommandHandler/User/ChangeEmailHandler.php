<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\ChangeEmailCommand;
use App\Auth\Application\Exception\EmailAddressInUseException;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Port\EmailChangeNoticeInterface;
use App\Auth\Application\Service\UserLookup;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Email;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * An operator's email change, from the admin panel or the CLI.
 *
 * The new address takes effect at once and counts as verified, and it replaces any change the
 * user had pending. Saving the user ends the reset and verification tokens issued for the old
 * address (UserRepositoryInterface::save). The old address is told of the change once it has
 * committed.
 */
final readonly class ChangeEmailHandler
{
    public function __construct(
        private UserLookup $users,
        private UserRepositoryInterface $userRepository,
        private EmailChangeNoticeInterface $notice,
        private TransactionPortInterface $transaction,
    ) {
    }

    /**
     * @return User the user with the new address
     *
     * @throws UserNotFoundException      when no user has the email address or public ID
     * @throws EmailAddressInUseException when another account uses the new address
     * @throws \InvalidArgumentException  when the new address is not a valid email address
     */
    #[AsMessageHandler]
    public function __invoke(ChangeEmailCommand $command): User
    {
        $user = $this->users->byIdentifier($command->identifier);
        $email = new Email($command->email);
        $previous = Email::fromString($user->getEmail());

        if ($email->equals($previous)) {
            return $user;
        }

        if ($this->userRepository->existsWithEmail($email)) {
            throw EmailAddressInUseException::create();
        }

        $this->transaction->run(function () use ($user, $email): void {
            $user->assignVerifiedEmail($email);
            $this->userRepository->save($user);
        });
        $this->notice->deliver($user, $previous);

        return $user;
    }
}
