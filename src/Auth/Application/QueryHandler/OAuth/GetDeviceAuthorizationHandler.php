<?php

declare(strict_types=1);

namespace App\Auth\Application\QueryHandler\OAuth;

use App\Auth\Application\DTO\PendingDeviceAuthorizationDTO;
use App\Auth\Application\Query\OAuth\GetDeviceAuthorizationQuery;
use App\Auth\Application\Service\PendingDeviceCodeFinder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Shows the signed-in user which client a user code belongs to before they approve it.
 */
final readonly class GetDeviceAuthorizationHandler
{
    public function __construct(
        private PendingDeviceCodeFinder $pendingDeviceCodes,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(GetDeviceAuthorizationQuery $query): PendingDeviceAuthorizationDTO
    {
        $deviceCode = $this->pendingDeviceCodes->find($query->userCode);

        return new PendingDeviceAuthorizationDTO(
            userCode: $deviceCode->getUserCode(),
            clientName: $deviceCode->getClient()->getName(),
            scopes: $deviceCode->getScopeIdentifiers(),
        );
    }
}
