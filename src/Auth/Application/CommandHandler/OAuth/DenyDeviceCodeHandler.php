<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\DenyDeviceCodeCommand;
use App\Auth\Domain\Repository\OAuth\DeviceCodeRepositoryInterface;
use App\Auth\Application\Service\PendingDeviceCodeFinder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The signed-in user rejects a device authorization request; the device's next poll answers access_denied.
 */
final readonly class DenyDeviceCodeHandler
{
    public function __construct(
        private PendingDeviceCodeFinder $pendingDeviceCodes,
        private DeviceCodeRepositoryInterface $deviceCodeRepository,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(DenyDeviceCodeCommand $command): void
    {
        $deviceCode = $this->pendingDeviceCodes->find($command->userCode);
        $deviceCode->deny();
        $this->deviceCodeRepository->save($deviceCode);
    }
}
