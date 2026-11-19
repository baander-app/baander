<?php

declare(strict_types=1);

namespace App\Auth\Application\QueryHandler\OAuth;

use App\Auth\Application\DTO\AuthorizationRequestDTO;
use App\Auth\Application\Query\OAuth\GetAuthorizationRequestQuery;
use App\Auth\Application\Service\AuthorizationRequestValidator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Describes a pushed authorization request for the consent page, from the pushed parameters alone.
 *
 * Baander keeps no record of earlier consent, so every client except a
 * first-party one needs the user's explicit approval each time.
 */
final readonly class GetAuthorizationRequestHandler
{
    public function __construct(
        private AuthorizationRequestValidator $validator,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(GetAuthorizationRequestQuery $query): AuthorizationRequestDTO
    {
        $request = $this->validator->validate($query->userId, $query->clientId, $query->requestUri);

        return new AuthorizationRequestDTO(
            clientId: $request->client->getPublicId()->toString(),
            clientName: $request->client->getName(),
            clientType: $request->client->getType()->value,
            scopes: $request->effectiveScopes(),
            redirectUri: $request->pushedRequest->getRedirectUri(),
            consentRequired: !$request->client->isFirstParty(),
        );
    }
}
