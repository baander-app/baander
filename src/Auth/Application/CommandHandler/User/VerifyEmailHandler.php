<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\VerifyEmailCommand;
use App\Auth\Application\DTO\RevokedSessions;
use App\Auth\Application\Exception\CredentialsChangedException;
use App\Auth\Application\Exception\EmailVerificationException;
use App\Auth\Application\Exception\VerificationLinkOtherAccountException;
use App\Auth\Application\Port\EmailChangeNoticeInterface;
use App\Auth\Application\Port\EmailVerificationTokenRepositoryInterface;
use App\Auth\Application\Service\CredentialChangeRevoker;
use App\Auth\Domain\Event\EmailVerified;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Verifies an email address with a single-use token, for the signed-in user it was sent to.
 *
 * Holding the link is not enough: only a session of the account the token was issued to
 * redeems it. Another account's valid token is refused with an answer of its own and left in
 * place, so its own account can still use the link.
 *
 * Redemption deletes the token in the same transaction that marks the address verified and
 * records EmailVerified, so a failed commit leaves the token redeemable. A token verifies only
 * the address it was issued for: the account's current address, or the pending address of a
 * self-service email change. Redeeming the second kind makes the pending address the
 * account's verified address, unless another account uses it by then; that redemption fails
 * like an invalid token and drops the pending change. A confirmed change ends every other
 * session of the user through CredentialChangeRevoker and keeps the one that opened the link;
 * once it has committed, the ended sessions' connections close and the previous address is
 * told of the change.
 */
final readonly class VerifyEmailHandler
{
    public function __construct(
        private EmailVerificationTokenRepositoryInterface $emailVerificationTokenRepository,
        private UserRepositoryInterface $userRepository,
        private EventDispatcherInterface $eventDispatcher,
        private TransactionPortInterface $transaction,
        private EmailChangeNoticeInterface $emailChangeNotice,
        private CredentialChangeRevoker $credentialChangeRevoker,
    ) {
    }

    /**
     * @return bool true when the link confirmed a pending email change, which ended every
     *              other session of the user
     *
     * @throws EmailVerificationException            for an unknown, expired or used token, a
     *                                               token for an address the account no longer
     *                                               has or no longer asks for, a pending
     *                                               address another account took, or a
     *                                               disabled account
     * @throws VerificationLinkOtherAccountException for another account's valid token
     * @throws CredentialsChangedException           when another change ended the requesting
     *                                               session before this one could confirm a
     *                                               pending address
     */
    #[AsMessageHandler]
    public function __invoke(VerifyEmailCommand $command): bool
    {
        $token = trim($command->token);
        if ($token === '') {
            throw EmailVerificationException::invalid();
        }

        $userId = Uuid::fromString($command->userId);
        // An unusable token is still deleted: the transaction commits and the caller is told
        // only that the token is invalid.
        /** @var array{User, Email, RevokedSessions}|null $changed the user whose pending address took effect, their previous address and the sessions the change ended */
        $changed = null;
        $verified = $this->transaction->run(function () use ($token, $userId, $command, &$changed): bool {
            $now = new \DateTimeImmutable();
            $address = $this->emailVerificationTokenRepository->redeem($token, $userId, $now);
            if ($address === null && $this->emailVerificationTokenRepository->isLiveForAnotherUser($token, $userId, $now)) {
                throw new VerificationLinkOtherAccountException();
            }

            $user = $address === null ? null : $this->userRepository->findByUuid($userId);
            if ($address === null || $user === null || $user->isDisabled()) {
                return false;
            }

            if (Email::fromString($user->getEmail())->equals($address)) {
                $this->verifyCurrentAddress($user, $address);

                return true;
            }

            if ($user->getPendingEmail() === null || !Email::fromString($user->getPendingEmail())->equals($address)) {
                return false;
            }

            if ($this->userRepository->existsWithEmail($address)) {
                $user->discardPendingEmail();
                $this->userRepository->save($user);

                return false;
            }

            $previousAddress = Email::fromString($user->getEmail());
            $sessions = $this->credentialChangeRevoker->revokeSessions(
                $user,
                $command->currentAccessTokenId,
                fn () => $this->confirmPendingAddress($user, $address),
            );
            $changed = [$user, $previousAddress, $sessions];

            return true;
        });

        if (!$verified) {
            throw EmailVerificationException::invalid();
        }

        if ($changed === null) {
            return false;
        }

        [$user, $previousAddress, $sessions] = $changed;
        $this->credentialChangeRevoker->closeConnections($sessions);
        $this->emailChangeNotice->deliver($user, $previousAddress);

        return true;
    }

    private function verifyCurrentAddress(User $user, Email $address): void
    {
        if ($user->isEmailVerified()) {
            return;
        }

        $user->verifyEmail();
        $this->userRepository->save($user);
        $this->eventDispatcher->dispatch(new EmailVerified(userId: $user->getId(), email: $address));
    }

    private function confirmPendingAddress(User $user, Email $address): void
    {
        $user->confirmPendingEmail();
        $this->userRepository->save($user);
        $this->eventDispatcher->dispatch(new EmailVerified(userId: $user->getId(), email: $address));
    }
}
