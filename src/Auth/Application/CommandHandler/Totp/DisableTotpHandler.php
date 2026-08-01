<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\Totp;

use App\Auth\Application\Command\Totp\DisableTotpCommand;
use App\Auth\Application\Port\TotpVerifierInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class DisableTotpHandler
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly TotpVerifierInterface $totpVerifier,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(DisableTotpCommand $command): void
    {
        $userId = Uuid::fromString($command->getUserId());

        $user = $this->userRepository->findByUuid($userId);
        if ($user === null) {
            throw new RuntimeException(sprintf('User "%s" not found.', $userId->toString()));
        }

        $currentSecret = $user->getTotpSecret();
        if ($currentSecret === null) {
            throw new RuntimeException('TOTP is not enabled for this user.');
        }

        // Verify the current TOTP code before allowing disable
        if (!$this->totpVerifier->verifyCode($currentSecret, $command->getCode())) {
            throw new RuntimeException('Invalid TOTP code. Please provide a valid code from your authenticator app.');
        }

        $user->setTotpSecret(null);
        $this->userRepository->save($user);
    }
}
