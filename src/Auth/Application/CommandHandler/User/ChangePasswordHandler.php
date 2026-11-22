<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\ChangePasswordCommand;
use App\Auth\Application\Exception\CredentialsChangedException;
use App\Auth\Application\Exception\PasswordPolicyException;
use App\Auth\Application\Exception\StepUpFailedException;
use App\Auth\Application\Exception\StepUpRateLimitedException;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Service\CredentialChangeRevoker;
use App\Auth\Application\Service\PasswordChanger;
use App\Auth\Application\Service\PasswordPolicy;
use App\Auth\Application\Service\StepUpVerifier;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A signed-in user changes their own password after step-up. The session that asked stays
 * signed in and every other session ends, its WebSocket connections closed once the change
 * has committed.
 */
final readonly class ChangePasswordHandler
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private StepUpVerifier $stepUp,
        private PasswordChanger $passwordChanger,
        private CredentialChangeRevoker $credentialChangeRevoker,
    ) {
    }

    /**
     * @throws PasswordPolicyException     when the new password breaks the policy, before any step-up attempt is spent
     * @throws StepUpFailedException       when the current password or the code does not match
     * @throws StepUpRateLimitedException  when the account has used up its step-up attempts
     * @throws CredentialsChangedException when another change ended the requesting session first
     */
    #[AsMessageHandler]
    public function __invoke(ChangePasswordCommand $command): void
    {
        $user = $this->userRepository->findByUuid(Uuid::fromString($command->userId))
            ?? throw UserNotFoundException::forIdentifier($command->userId);
        PasswordPolicy::assertAcceptable($command->newPassword);
        $this->stepUp->verify($user, $command->currentPassword, $command->totpCode);

        $password = $this->passwordChanger->hash($command->newPassword);
        $this->passwordChanger->change($user, $password, $command->currentAccessTokenId)
            |> $this->credentialChangeRevoker->closeConnections(...);
    }
}
