<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\VerifyEmailCommand;
use App\Auth\Application\Exception\EmailVerificationException;
use App\Auth\Application\Port\EmailVerificationTokenRepositoryInterface;
use App\Auth\Domain\Event\EmailVerified;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Email;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Verifies an email address with a single-use token.
 *
 * Redemption deletes the token in the same transaction that marks the address verified and
 * records EmailVerified, so a failed commit leaves the token redeemable. A token verifies only
 * the address it was issued for, and only while the account still has that address.
 */
final readonly class VerifyEmailHandler
{
    public function __construct(
        private EmailVerificationTokenRepositoryInterface $emailVerificationTokenRepository,
        private UserRepositoryInterface $userRepository,
        private EventDispatcherInterface $eventDispatcher,
        private TransactionPortInterface $transaction,
    ) {
    }

    /**
     * @throws EmailVerificationException for an unknown, expired or used token, a token for an
     *                                    address the account no longer has, or a disabled account
     */
    #[AsMessageHandler]
    public function __invoke(VerifyEmailCommand $command): void
    {
        $token = trim($command->getToken());
        if ($token === '') {
            throw EmailVerificationException::invalid();
        }

        // An unusable token is still deleted: the transaction commits and the caller is told
        // only that the token is invalid.
        $verified = $this->transaction->run(function () use ($token): bool {
            $redeemed = $this->emailVerificationTokenRepository->redeem($token, new \DateTimeImmutable());
            $user = $redeemed === null ? null : $this->userRepository->findByUuid($redeemed->userId);

            if ($redeemed === null || $user === null || $user->isDisabled()
                || !Email::fromString($user->getEmail())->equals($redeemed->email)) {
                return false;
            }

            if (!$user->isEmailVerified()) {
                $user->verifyEmail();
                $this->userRepository->save($user);
                $this->eventDispatcher->dispatch(new EmailVerified(
                    userId: $user->getId(),
                    email: $redeemed->email,
                ));
            }

            return true;
        });

        if (!$verified) {
            throw EmailVerificationException::invalid();
        }
    }
}
