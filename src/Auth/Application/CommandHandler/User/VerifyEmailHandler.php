<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\VerifyEmailCommand;
use App\Auth\Application\DTO\RedeemedEmailVerification;
use App\Auth\Application\Exception\EmailVerificationException;
use App\Auth\Application\Port\EmailChangeNoticeInterface;
use App\Auth\Application\Port\EmailVerificationTokenRepositoryInterface;
use App\Auth\Domain\Event\EmailVerified;
use App\Auth\Domain\Model\User;
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
 * the address it was issued for: the account's current address, or the pending address of a
 * self-service email change. Redeeming the second kind makes the pending address the
 * account's verified address, unless another account uses it by then; that redemption fails
 * like an invalid token and drops the pending change. The previous address is told of the
 * change once it has committed.
 */
final readonly class VerifyEmailHandler
{
    public function __construct(
        private EmailVerificationTokenRepositoryInterface $emailVerificationTokenRepository,
        private UserRepositoryInterface $userRepository,
        private EventDispatcherInterface $eventDispatcher,
        private TransactionPortInterface $transaction,
        private EmailChangeNoticeInterface $emailChangeNotice,
    ) {
    }

    /**
     * @throws EmailVerificationException for an unknown, expired or used token, a token for an
     *                                    address the account no longer has or no longer asks
     *                                    for, a pending address another account took, or a
     *                                    disabled account
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
        /** @var array{User, Email}|null $changed the user whose pending address took effect, and their previous address */
        $changed = null;
        $verified = $this->transaction->run(function () use ($token, &$changed): bool {
            $redeemed = $this->emailVerificationTokenRepository->redeem($token, new \DateTimeImmutable());
            $user = $redeemed === null ? null : $this->userRepository->findByUuid($redeemed->userId);

            if ($redeemed === null || $user === null || $user->isDisabled()) {
                return false;
            }

            if (Email::fromString($user->getEmail())->equals($redeemed->email)) {
                $this->verifyCurrentAddress($user, $redeemed);

                return true;
            }

            if ($user->getPendingEmail() === null || !Email::fromString($user->getPendingEmail())->equals($redeemed->email)) {
                return false;
            }

            if ($this->userRepository->existsWithEmail($redeemed->email)) {
                $user->discardPendingEmail();
                $this->userRepository->save($user);

                return false;
            }

            $previousAddress = Email::fromString($user->getEmail());
            $user->confirmPendingEmail();
            $this->userRepository->save($user);
            $this->eventDispatcher->dispatch(new EmailVerified(userId: $user->getId(), email: $redeemed->email));
            $changed = [$user, $previousAddress];

            return true;
        });

        if (!$verified) {
            throw EmailVerificationException::invalid();
        }

        if ($changed !== null) {
            $this->emailChangeNotice->deliver(...$changed);
        }
    }

    private function verifyCurrentAddress(User $user, RedeemedEmailVerification $redeemed): void
    {
        if ($user->isEmailVerified()) {
            return;
        }

        $user->verifyEmail();
        $this->userRepository->save($user);
        $this->eventDispatcher->dispatch(new EmailVerified(userId: $user->getId(), email: $redeemed->email));
    }
}
