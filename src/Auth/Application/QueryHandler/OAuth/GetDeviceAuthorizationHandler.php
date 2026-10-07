<?php

declare(strict_types=1);

namespace App\Auth\Application\QueryHandler\OAuth;

use App\Auth\Application\DTO\PendingDeviceAuthorizationDTO;
use App\Auth\Application\Query\OAuth\GetDeviceAuthorizationQuery;
use App\Auth\Application\Service\PendingDeviceCodeFinder;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
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
            clientId: $deviceCode->getClient()->getPublicId()->toString(),
            clientName: $deviceCode->getClient()->getName(),
            // A request without allowed scopes yields a token with the default scopes.
            scopes: $deviceCode->getScopeIdentifiers() !== []
                ? $deviceCode->getScopeIdentifiers()
                : array_map(static fn (Scope $scope): string => $scope->toString(), Scope::defaultScopes()),
            expiresAt: $deviceCode->getExpiresAt(),
        );
    }
}
