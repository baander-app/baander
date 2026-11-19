<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\Totp;

use App\Auth\Application\Command\Totp\DisableTotpCommand;
use App\Auth\Application\Exception\TotpChangeRefusal;
use App\Auth\Application\Exception\TotpChangeRefusedException;
use App\Auth\Application\Exception\TotpSecretUnavailableException;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Port\TotpVerifierInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Turns TOTP off when a current, unspent code confirms it. */
final readonly class DisableTotpHandler
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private TotpVerifierInterface $totpVerifier,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(DisableTotpCommand $command): void
    {
        $userId = Uuid::fromString($command->getUserId());
        $user = $this->userRepository->findByUuid($userId) ?? throw UserNotFoundException::forIdentifier($userId->toString());
        if (!$user->hasTotp()) {
            throw new TotpChangeRefusedException(TotpChangeRefusal::NotEnabled);
        }

        try {
            $verified = $this->totpVerifier->verifyAndSpend($user, $command->getCode());
        } catch (TotpSecretUnavailableException $exception) {
            throw new TotpChangeRefusedException(TotpChangeRefusal::SecretUnavailable, $exception);
        }
        if (!$verified) {
            throw new TotpChangeRefusedException(TotpChangeRefusal::InvalidCode);
        }

        $user->disableTotp();
        $this->userRepository->save($user);
    }
}
