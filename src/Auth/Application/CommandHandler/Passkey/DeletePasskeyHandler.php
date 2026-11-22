<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\Passkey;

use App\Auth\Application\Command\Passkey\DeletePasskeyCommand;
use App\Auth\Application\DTO\RevokedSessions;
use App\Auth\Application\Exception\CredentialsChangedException;
use App\Auth\Application\Exception\StepUpFailedException;
use App\Auth\Application\Exception\StepUpRateLimitedException;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Service\CredentialChangeRevoker;
use App\Auth\Application\Service\StepUpVerifier;
use App\Auth\Domain\Event\Passkey\PasskeyDeleted;
use App\Auth\Domain\Model\Passkey\Passkey;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A signed-in user removes one of their own passkeys after step-up, and PasskeyDeleted is
 * recorded with the removal.
 *
 * Removing a passkey is a credential change: the session that asked stays signed in and every
 * other session ends, paired devices and third-party apps included, their WebSocket connections
 * closed once the change has committed.
 */
final readonly class DeletePasskeyHandler
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private PasskeyRepositoryInterface $passkeyRepository,
        private StepUpVerifier $stepUp,
        private CredentialChangeRevoker $credentialChangeRevoker,
        private TransactionPortInterface $transaction,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @return list<string> the credential IDs of the passkeys the user keeps
     *
     * @throws NotFoundException           when the user has no passkey with that public ID, before any step-up attempt is spent
     * @throws StepUpFailedException       when the password or the code does not match
     * @throws StepUpRateLimitedException  when the account has used up its step-up attempts
     * @throws CredentialsChangedException when another change ended the requesting session first
     */
    #[AsMessageHandler]
    public function __invoke(DeletePasskeyCommand $command): array
    {
        $user = $this->userRepository->findByUuid(Uuid::fromString($command->userId))
            ?? throw UserNotFoundException::forIdentifier($command->userId);
        $passkeys = $this->passkeyRepository->forUser($user->getId());
        $target = array_find(
            $passkeys,
            static fn (Passkey $passkey): bool => $passkey->getPublicId()->toString() === $command->passkeyPublicId,
        ) ?? throw new NotFoundException('Passkey not found.');
        $this->stepUp->verify($user, $command->currentPassword, $command->totpCode);

        $sessions = $this->transaction->run(fn (): RevokedSessions => $this->credentialChangeRevoker->revokeSessions(
            $user,
            $command->currentAccessTokenId,
            fn () => $this->remove($user, $target),
        ));
        $this->credentialChangeRevoker->closeConnections($sessions);

        $kept = array_filter($passkeys, static fn (Passkey $passkey): bool => $passkey !== $target);

        return array_map(static fn (Passkey $passkey): string => $passkey->getCredentialId(), $kept)
            |> array_values(...);
    }

    private function remove(User $user, Passkey $passkey): void
    {
        $this->passkeyRepository->remove($passkey);
        $this->eventDispatcher->dispatch(new PasskeyDeleted($user->getId(), $passkey->getId()));
    }
}
