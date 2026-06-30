<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\ApproveDeviceCodeCommand;
use App\Auth\Domain\Event\OAuth\DeviceCodeApproved;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\DeviceCodeRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for approving an OAuth 2.0 Device Code authorization (RFC 8628).
 *
 * Binds a user to the device code and returns the verification URI complete
 * so the caller can confirm the approval was processed.
 */
final class ApproveDeviceCodeHandler
{
    public function __construct(
        private readonly DeviceCodeRepositoryInterface $deviceCodeRepository,
        private readonly UserRepositoryInterface $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(ApproveDeviceCodeCommand $command): string
    {
        $deviceCode = $this->deviceCodeRepository->findByUserCode($command->getUserCode());

        if ($deviceCode === null) {
            throw new RuntimeException(
                sprintf('No device code found for user code "%s".', $command->getUserCode()),
            );
        }

        if ($deviceCode->isExpired()) {
            throw new RuntimeException('Device code has expired.');
        }

        if ($deviceCode->isDenied()) {
            throw new RuntimeException('Device authorization has been denied.');
        }

        if ($deviceCode->isApproved()) {
            throw new RuntimeException('Device authorization has already been approved.');
        }

        $user = $this->userRepository->findByUuid($command->getUserId());

        if ($user === null) {
            throw new RuntimeException('User not found.');
        }

        $deviceCode->approve($user);

        $this->entityManager->getConnection()->transactional(function () use ($deviceCode): void {
            $this->deviceCodeRepository->save($deviceCode);
        });

        $this->eventDispatcher->dispatch(new DeviceCodeApproved(
            deviceCodeId: $deviceCode->getId()->toString(),
            userId: $command->getUserId()->toString(),
        ));

        $verificationUriComplete = $deviceCode->getVerificationUriComplete();

        return $verificationUriComplete ?? $deviceCode->getVerificationUri();
    }
}
