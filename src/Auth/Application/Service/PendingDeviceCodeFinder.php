<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

use App\Auth\Application\Exception\DeviceUserCodeException;
use App\Auth\Domain\Model\OAuth\DeviceCode;
use App\Auth\Domain\Repository\OAuth\DeviceCodeRepositoryInterface;

/**
 * Finds the undecided, unexpired device request behind a user code as the user typed it.
 */
final readonly class PendingDeviceCodeFinder
{
    public function __construct(
        private DeviceCodeRepositoryInterface $deviceCodeRepository,
    ) {
    }

    /** @throws DeviceUserCodeException */
    public function find(string $userCode): DeviceCode
    {
        $normalized = DeviceCode::normalizeUserCode($userCode);
        $deviceCode = $normalized === null ? null : $this->deviceCodeRepository->findByUserCode($normalized);

        if ($deviceCode === null || $deviceCode->isExpired()) {
            throw DeviceUserCodeException::invalid();
        }
        if (!$deviceCode->isPending()) {
            throw DeviceUserCodeException::alreadyProcessed();
        }

        return $deviceCode;
    }
}
