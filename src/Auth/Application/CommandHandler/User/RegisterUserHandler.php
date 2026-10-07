<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\RegisterUserCommand;
use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Application\Service\EmailVerificationIssuer;
use App\Auth\Domain\Event\UserRegistered;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Notification\Application\DTO\SeedDefaultPreferencesCommand;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Email;
use App\UserPreference\Application\Port\UserSettingsContractInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

final class RegisterUserHandler
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly PasswordHasherInterface $passwordHasher,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly MessageBusInterface $bus,
        private readonly EmailVerificationIssuer $emailVerification,
        private readonly TransactionPortInterface $transaction,
        private readonly UserSettingsContractInterface $settings,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(RegisterUserCommand $command): User
    {
        if ($this->userRepository->existsWithEmail($command->getEmail())) {
            throw new \RuntimeException('A user with this email already exists.');
        }

        $hashedPassword = $this->passwordHasher->hash($command->getPlainPassword());
        $user = User::register($command->getEmail(), $hashedPassword, $command->getName());

        $issued = $this->transaction->run(function () use ($user, $command) {
            $this->userRepository->save($user);

            // Stored only when it differs from the server default, so a user whose browser
            // asks for the default keeps following it when an admin changes it.
            $browserLanguage = $command->getBrowserLanguage();
            if ($browserLanguage !== null) {
                $this->settings->seedLanguage($user->getId()->toString(), $browserLanguage);
            }

            $issued = $this->emailVerification->issue($user);

            $this->bus->dispatch(new SeedDefaultPreferencesCommand(
                userId: $user->getId(),
            ));

            $this->eventDispatcher->dispatch(new UserRegistered(
                userId: $user->getId(),
                publicId: $user->getPublicId(),
                email: Email::fromString($user->getEmail()),
                name: $user->getName(),
            ));

            return $issued;
        });

        // After the commit, so a rolled-back registration never emails a link. A delivery
        // failure is logged by the delivery and does not fail the registration.
        $this->emailVerification->deliver($user, $issued);

        return $user;
    }
}
