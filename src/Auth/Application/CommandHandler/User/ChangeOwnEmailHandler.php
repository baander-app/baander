<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\ChangeOwnEmailCommand;
use App\Auth\Application\Exception\StepUpFailedException;
use App\Auth\Application\Exception\StepUpRateLimitedException;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Service\EmailVerificationIssuer;
use App\Auth\Application\Service\StepUpVerifier;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The user's own email change. Step-up comes first, so neither the change nor its refusals
 * answer a session that cannot prove the user is present.
 *
 * The new address is held as pending and sent a verification link; the account keeps its
 * address until that link is used (VerifyEmailHandler). Whether another account uses the new
 * address is not checked here, so the request does the same work and gets the same answer
 * either way; the link fails if the address is taken when it is used. Saving a different
 * pending address ends the link sent for the earlier one.
 */
final readonly class ChangeOwnEmailHandler
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private StepUpVerifier $stepUp,
        private EmailVerificationIssuer $verification,
        private TransactionPortInterface $transaction,
    ) {
    }

    /**
     * @throws StepUpFailedException      when the password or code does not match
     * @throws StepUpRateLimitedException when the account has used up its step-up attempts
     * @throws \InvalidArgumentException  when the new address is not a valid email address
     */
    #[AsMessageHandler]
    public function __invoke(ChangeOwnEmailCommand $command): void
    {
        $user = $this->userRepository->findByUuid(Uuid::fromString($command->userId))
            ?? throw UserNotFoundException::forIdentifier($command->userId);

        $this->stepUp->verify($user, $command->currentPassword, $command->totpCode);
        $address = new Email($command->email);

        $issued = $this->transaction->run(function () use ($user, $address) {
            $user->requestEmailChange($address);
            $this->userRepository->save($user);

            // Asking for the current address drops the pending change and sends nothing.
            return $user->getPendingEmail() === null ? null : $this->verification->issue($user);
        });
        $this->verification->deliver($user, $issued);
    }
}
