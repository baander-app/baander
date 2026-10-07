<?php

declare(strict_types=1);

namespace App\Auth\Application\QueryHandler\OAuth;

use App\Auth\Application\DTO\AuthorizationRequestDTO;
use App\Auth\Application\Query\OAuth\GetAuthorizationRequestQuery;
use App\Auth\Application\Service\AuthorizationRequestValidator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Checks an authorization request and describes it for the consent page.
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
        $request = $this->validator->validate(
            $query->userId,
            $query->responseType,
            $query->clientId,
            $query->redirectUri,
            $query->codeChallenge,
            $query->codeChallengeMethod,
            $query->scopes,
        );

        return new AuthorizationRequestDTO(
            clientId: $request->client->getPublicId()->toString(),
            clientName: $request->client->getName(),
            clientType: $request->client->getType()->value,
            scopes: $request->effectiveScopes(),
            redirectUri: $request->redirectUri,
            consentRequired: !$request->client->isFirstParty(),
        );
    }
}
