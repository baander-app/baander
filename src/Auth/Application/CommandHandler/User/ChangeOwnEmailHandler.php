<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\ChangeEmailCommand;
use App\Auth\Application\Command\User\ChangeOwnEmailCommand;
use App\Auth\Application\Exception\EmailAddressInUseException;
use App\Auth\Application\Exception\StepUpFailedException;
use App\Auth\Application\Exception\StepUpRateLimitedException;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Service\StepUpVerifier;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The user's own email change: step-up first, so neither the change nor its refusals answer
 * a session that cannot prove the user is present, then the email change step every path shares.
 */
final readonly class ChangeOwnEmailHandler
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private StepUpVerifier $stepUp,
        private ChangeEmailHandler $changeEmail,
    ) {
    }

    /**
     * @return User the user with the new address
     *
     * @throws StepUpFailedException       when the password or code does not match
     * @throws StepUpRateLimitedException  when the account has used up its step-up attempts
     * @throws EmailAddressInUseException  when another account uses the new address
     */
    #[AsMessageHandler]
    public function __invoke(ChangeOwnEmailCommand $command): User
    {
        $user = $this->userRepository->findByUuid(Uuid::fromString($command->userId))
            ?? throw UserNotFoundException::forIdentifier($command->userId);

        $this->stepUp->verify($user, $command->currentPassword, $command->totpCode);

        return ($this->changeEmail)(new ChangeEmailCommand($user->getPublicId()->toString(), $command->email));
    }
}
