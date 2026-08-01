<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\Passkey;

use App\Auth\Application\Command\Passkey\RegisterPasskeyCommand;
use App\Auth\Domain\Event\Passkey\PasskeyRegistered;
use App\Auth\Domain\Model\Passkey\Passkey;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class RegisterPasskeyHandler
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly PasskeyRepositoryInterface $passkeyRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(RegisterPasskeyCommand $command): Passkey
    {
        $userId = Uuid::fromString($command->getUserId());

        $user = $this->userRepository->findByUuid($userId);
        if ($user === null) {
            throw new RuntimeException(sprintf('User "%s" not found.', $userId->toString()));
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

        $this->passkeyRepository->save($passkey, $userId);

        $this->eventDispatcher->dispatch(new PasskeyRegistered(
            userId: $userId,
            passkeyId: $passkey->getId(),
            credentialId: $command->getCredentialId(),
            name: $command->getName(),
        ));

        return $passkey;
    }
}
