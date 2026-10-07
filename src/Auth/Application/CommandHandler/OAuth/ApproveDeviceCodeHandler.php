<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\ApproveDeviceCodeCommand;
use App\Auth\Application\Service\PendingDeviceCodeFinder;
use App\Auth\Domain\Event\OAuth\DeviceCodeApproved;
use App\Auth\Domain\Repository\OAuth\DeviceCodeRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The signed-in user approves a device authorization request (RFC 8628 section 3.3).
 *
 * The device's next poll then receives a token pair for this user. The approval
 * raises DeviceCodeApproved, which notifies the user as a security event.
 */
final readonly class ApproveDeviceCodeHandler
{
    public function __construct(
        private DeviceCodeRepositoryInterface $deviceCodeRepository,
        private PendingDeviceCodeFinder $pendingDeviceCodes,
        private UserRepositoryInterface $userRepository,
        private EntityManagerInterface $entityManager,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(ApproveDeviceCodeCommand $command): void
    {
        $deviceCode = $this->pendingDeviceCodes->find($command->getUserCode());

        $user = $this->userRepository->findByUuid($command->getUserId());
        if ($user === null) {
            throw new RuntimeException('User not found.');
        }

        $deviceCode->approve($user);

        $this->entityManager->getConnection()->transactional(function () use ($deviceCode): void {
            $this->deviceCodeRepository->save($deviceCode, false);
            $this->entityManager->flush();
        });

        $this->eventDispatcher->dispatch(new DeviceCodeApproved(
            deviceCodeId: $deviceCode->getId()->toString(),
            userId: $command->getUserId()->toString(),
        ));
    }
}
