<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\RegisterUserCommand;
use App\Auth\Application\Exception\RegistrationClosedException;
use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Application\Port\RegistrationAttemptNoticeInterface;
use App\Auth\Application\Port\SelfRegistrationInterface;
use App\Auth\Application\Service\EmailVerificationIssuer;
use App\Auth\Domain\Event\UserRegistered;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Notification\Application\DTO\SeedDefaultPreferencesCommand;
use App\Shared\Application\Port\SleeperInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Email;
use App\UserPreference\Application\Port\UserSettingsContractInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Registers an account, or, when the address is taken, tells its owner instead.
 *
 * The caller cannot tell the two apart. Both hash the password, both send their email after
 * the response, and both return no sooner than a fixed time after the hash, so neither the
 * answer nor its timing reveals which addresses have accounts. While self-registration is
 * closed, a registration is refused before any of that work.
 */
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
        private readonly RegistrationAttemptNoticeInterface $registrationAttemptNotice,
        private readonly SelfRegistrationInterface $selfRegistration,
        private readonly ClockInterface $clock,
        private readonly SleeperInterface $sleeper,
        #[Autowire(param: 'auth.registration.response_floor_ms')]
        private readonly int $responseFloorMilliseconds,
    ) {
    }

    /**
     * @throws RegistrationClosedException while self-registration is closed
     */
    #[AsMessageHandler]
    public function __invoke(RegisterUserCommand $command): void
    {
        if (!$this->selfRegistration->isOpen()) {
            throw new RegistrationClosedException();
        }

        $hashedPassword = $this->passwordHasher->hash($command->getPlainPassword());
        $hashedAt = $this->clock->now();

        $owner = $this->userRepository->findByEmail($command->getEmail());
        if ($owner === null) {
            $this->createAccount($command, $hashedPassword);
        } else {
            $this->registrationAttemptNotice->deliver($owner);
        }

        $this->holdUntilTheFloor($hashedAt);
    }

    private function createAccount(RegisterUserCommand $command, string $hashedPassword): void
    {
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
    }

    /**
     * Waits until the floor has passed since the hash. Only a new account is stored, so
     * without the wait a quicker answer would mean the address already has an account. The
     * wait never exceeds the floor, even if the clock steps back meanwhile.
     */
    private function holdUntilTheFloor(\DateTimeImmutable $hashedAt): void
    {
        $floor = $this->responseFloorMilliseconds / 1000;
        $elapsed = (float) $this->clock->now()->format('U.u') - (float) $hashedAt->format('U.u');
        $remaining = $floor - $elapsed;

        if ($remaining > 0) {
            $this->sleeper->sleep(min($remaining, $floor));
        }
    }
}
