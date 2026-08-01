<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\VerifyEmailCommand;
use App\Auth\Application\Port\EmailVerificationTokenRepositoryInterface;
use App\Auth\Domain\Event\EmailVerified;
use App\Auth\Domain\Exception\EmailVerificationException;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Email;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Verifies an email address using a single-use token.
 *
 * Looks the token up in the persistent store, enforces expiry and single-use,
 * marks the associated user's email as verified, and deletes the token.
 */
final class VerifyEmailHandler
{
    public function __construct(
        private readonly EmailVerificationTokenRepositoryInterface $emailVerificationTokenRepository,
        private readonly UserRepositoryInterface $userRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(VerifyEmailCommand $command): bool
    {
        $token = trim($command->getToken());

        if ($token === '') {
            throw EmailVerificationException::missing();
        }

        $entity = $this->emailVerificationTokenRepository->findByToken($token);

        if ($entity === null) {
            throw EmailVerificationException::invalid();
        }

        if ($entity->isExpired()) {
            throw EmailVerificationException::expired();
        }

        if ($entity->getUsedAt() !== null) {
            throw EmailVerificationException::alreadyUsed();
        }

        $user = $this->userRepository->findByUuid($entity->getUser()->getId());

        if ($user === null) {
            throw EmailVerificationException::invalid();
        }

        $user->verifyEmail();
        $this->userRepository->save($user);

        $this->eventDispatcher->dispatch(new EmailVerified(
            userId: $user->getId(),
            email: Email::fromString($user->getEmail()),
        ));

        $this->emailVerificationTokenRepository->delete($entity);

        return true;
    }
}
