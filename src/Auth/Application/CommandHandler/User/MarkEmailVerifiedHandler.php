<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\MarkEmailVerifiedCommand;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Service\UserLookup;
use App\Auth\Domain\Event\EmailVerified;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Email;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Marks a user's current address verified on an operator's word, as redeeming its link would.
 * A pending email change is left alone: it still takes effect only through its own link.
 * Marking a verified address succeeds without change.
 */
final readonly class MarkEmailVerifiedHandler
{
    public function __construct(
        private UserLookup $userLookup,
        private UserRepositoryInterface $userRepository,
        private EventDispatcherInterface $eventDispatcher,
        private TransactionPortInterface $transaction,
    ) {
    }

    /**
     * @return User the user with a verified address
     *
     * @throws UserNotFoundException when no user has the email address or public ID
     */
    #[AsMessageHandler]
    public function __invoke(MarkEmailVerifiedCommand $command): User
    {
        $user = $this->userLookup->byIdentifier($command->identifier);
        if ($user->isEmailVerified()) {
            return $user;
        }

        $this->transaction->run(function () use ($user): void {
            $user->verifyEmail();
            $this->userRepository->save($user);
            $this->eventDispatcher->dispatch(new EmailVerified(userId: $user->getId(), email: Email::fromString($user->getEmail())));
        });

        return $user;
    }
}
