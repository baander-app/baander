<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\ResetUserTotpCommand;
use App\Auth\Application\DTO\RevokedSessions;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Service\CredentialChangeRevoker;
use App\Auth\Application\Service\UserLookup;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Port\TransactionPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Turns off a user's TOTP, forgets its last spent time step, advances the credential epoch
 * and ends every session the user has, in one transaction, so the user signs in again with
 * their password alone; the sessions' WebSocket connections close once it has committed. A
 * TOTP reset is a credential change: a login that checked the password and code before the
 * reset and waits on the account's row is refused, as after a password change. The stored
 * secret is dropped unread: a secret the configured key cannot open is the case this reset
 * exists for.
 */
final readonly class ResetUserTotpHandler
{
    public function __construct(
        private UserLookup $userLookup,
        private UserRepositoryInterface $userRepository,
        private CredentialChangeRevoker $credentialChangeRevoker,
        private TransactionPortInterface $transaction,
    ) {
    }

    /**
     * @return User the user without TOTP
     *
     * @throws UserNotFoundException when no user has the email address or public ID
     * @throws ConflictException     when the user has no TOTP to reset
     */
    #[AsMessageHandler]
    public function __invoke(ResetUserTotpCommand $command): User
    {
        $user = $this->userLookup->byIdentifier($command->identifier);
        if (!$user->hasTotp()) {
            throw new ConflictException(
                sprintf('TOTP is not on for "%s"; there is nothing to reset.', $command->identifier),
                ['reason' => 'totp_not_enabled'],
            );
        }

        $sessions = $this->transaction->run(fn (): RevokedSessions => $this->credentialChangeRevoker->revokeSessions(
            $user,
            null,
            fn () => $this->turnOffTotp($user),
        ));
        $this->credentialChangeRevoker->closeConnections($sessions);

        return $user;
    }

    private function turnOffTotp(User $user): void
    {
        $user->disableTotp();
        $this->userRepository->save($user);
        $this->userRepository->clearTotpStep($user->getId());
    }
}
