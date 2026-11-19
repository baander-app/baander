<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\ApproveDeviceCodeCommand;
use App\Auth\Application\Exception\DeviceUserCodeException;
use App\Auth\Application\Service\PendingDeviceCodeFinder;
use App\Auth\Domain\Event\OAuth\DeviceCodeApproved;
use App\Auth\Domain\Repository\OAuth\DeviceCodeRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The signed-in user approves a device authorization request (RFC 8628 section 3.3).
 *
 * The device's next poll then receives a token pair for this user. Of two users
 * approving the same code at once, one approval is recorded and the other is told
 * the code was already decided. The approval records the credential epoch of the
 * token it was sent with, so a password change before the device polls voids it.
 * The approval raises DeviceCodeApproved, which
 * notifies the user as a security event.
 */
final readonly class ApproveDeviceCodeHandler
{
    public function __construct(
        private DeviceCodeRepositoryInterface $deviceCodeRepository,
        private PendingDeviceCodeFinder $pendingDeviceCodes,
        private UserRepositoryInterface $userRepository,
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

        $deviceCode->approve($user, $command->getCredentialEpoch());
        if (!$this->deviceCodeRepository->approve($deviceCode)) {
            // Another decision, or the expiry, came between the lookup and this approval.
            throw DeviceUserCodeException::alreadyProcessed();
        }

        $this->eventDispatcher->dispatch(new DeviceCodeApproved(
            deviceCodeId: $deviceCode->getId()->toString(),
            userId: $command->getUserId()->toString(),
        ));
    }
}
