<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\ChangeEmailCommand;
use App\Auth\Application\Exception\EmailAddressInUseException;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Service\EmailVerificationIssuer;
use App\Auth\Application\Service\UserLookup;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Email;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The single step behind every email change: the user's own, the admin panel's and the CLI's.
 *
 * The new address starts unverified. Saving the user ends the tokens issued for the old
 * address (UserRepositoryInterface::save), and a verification link goes to the new address
 * once the change has committed.
 */
final readonly class ChangeEmailHandler
{
    public function __construct(
        private UserLookup $users,
        private UserRepositoryInterface $userRepository,
        private EmailVerificationIssuer $verification,
        private TransactionPortInterface $transaction,
    ) {
    }

    /**
     * @return User the user with the new address
     *
     * @throws UserNotFoundException      when no user has the email address or UUID
     * @throws EmailAddressInUseException when another account uses the new address
     * @throws \InvalidArgumentException  when the new address is not a valid email address
     */
    #[AsMessageHandler]
    public function __invoke(ChangeEmailCommand $command): User
    {
        $user = $this->users->byIdentifier($command->identifier);
        $email = new Email($command->email);

        if ($email->toString() === $user->getEmail()) {
            return $user;
        }

        if ($this->userRepository->existsWithEmail($email)) {
            throw EmailAddressInUseException::create();
        }

        $issued = $this->transaction->run(function () use ($user, $email) {
            $user->changeEmail($email->toString());
            $this->userRepository->save($user);

            return $this->verification->issue($user);
        });
        $this->verification->deliver($user, $issued);

        return $user;
    }
}
