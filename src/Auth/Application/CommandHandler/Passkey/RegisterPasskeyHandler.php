<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\Passkey;

use App\Auth\Application\Command\Passkey\RegisterPasskeyCommand;
use App\Auth\Application\DTO\RevokedSessions;
use App\Auth\Application\Exception\CredentialsChangedException;
use App\Auth\Application\Service\CredentialChangeRevoker;
use App\Auth\Domain\Event\Passkey\PasskeyRegistered;
use App\Auth\Domain\Model\Passkey\Passkey;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Stores the passkey a registration ceremony created and records PasskeyRegistered with it.
 *
 * A new passkey is a credential change: the session that completed the ceremony stays signed in
 * and every other session ends, paired devices and third-party apps included, their WebSocket
 * connections closed once the change has committed. A ceremony whose session another change
 * ended in the meantime stores nothing.
 */
final readonly class RegisterPasskeyHandler
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private PasskeyRepositoryInterface $passkeyRepository,
        private EventDispatcherInterface $eventDispatcher,
        private TransactionPortInterface $transaction,
        private CredentialChangeRevoker $credentialChangeRevoker,
    ) {
    }

    /** @throws CredentialsChangedException when another change ended the requesting session first */
    #[AsMessageHandler]
    public function __invoke(RegisterPasskeyCommand $command): Passkey
    {
        $userId = Uuid::fromString($command->getUserId());

        $user = $this->userRepository->findByUuid($userId);
        if ($user === null) {
            throw new RuntimeException(sprintf('User "%s" not found.', $userId->toString()));
        }

        $userHandle = $command->getCredentialRecordData()['userHandle'] ?? null;
        if (!is_string($userHandle) || !hash_equals($user->getPublicId()->toString(), $userHandle)) {
            throw new RuntimeException('Credential owner does not match its registered user handle.');
        }

        $passkeyId = Uuid::generate();
        $credentialId = $command->getCredentialId();

        // Check for duplicate credential ID
        if ($this->passkeyRepository->ofCredentialId($credentialId) !== null) {
            throw new RuntimeException('A passkey with this credential ID already exists.');
        }

        $passkey = Passkey::create(
            $passkeyId,
            $command->getName(),
            $credentialId,
            $command->getCredentialRecordData(),
            $command->getCounter(),
        );

        $sessions = $this->transaction->run(fn (): RevokedSessions => $this->credentialChangeRevoker->revokeSessions(
            $user,
            $command->getCurrentAccessTokenId(),
            fn () => $this->store($passkey, $userId, $command),
        ));
        $this->credentialChangeRevoker->closeConnections($sessions);

        return $passkey;
    }

    private function store(Passkey $passkey, Uuid $userId, RegisterPasskeyCommand $command): void
    {
        $this->passkeyRepository->save($passkey, $userId);
        $this->eventDispatcher->dispatch(new PasskeyRegistered(
            userId: $userId,
            passkeyId: $passkey->getId(),
            credentialId: $command->getCredentialId(),
            name: $command->getName(),
        ));
    }
}
