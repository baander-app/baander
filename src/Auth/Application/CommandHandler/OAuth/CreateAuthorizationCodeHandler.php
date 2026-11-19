<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\CreateAuthorizationCodeCommand;
use App\Auth\Application\DTO\AuthorizationResponseDTO;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Application\Service\AuthorizationRequestValidator;
use App\Auth\Domain\Model\OAuth\AuthCode;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\PushedAuthorizationRequest;
use App\Auth\Domain\Repository\OAuth\AuthCodeRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\PushedAuthorizationRequestRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use DateInterval;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Records the signed-in user's decision on a pushed authorization request (RFC 6749 section 4.1.2).
 *
 * Either decision uses the request up, so it is decided once. Approval issues a
 * single-use authorization code bound to the pushed PKCE challenge, redirect URI and
 * DPoP key thumbprint, in the transaction that uses the request up; of two concurrent
 * approvals one issues a code. Denial answers access_denied at the pushed redirect URI.
 * Both run the same user checks first; a check that fails for the user leaves the
 * request unused.
 */
final readonly class CreateAuthorizationCodeHandler
{
    private DateInterval $authCodeTtl;

    public function __construct(
        private AuthorizationRequestValidator $validator,
        private PushedAuthorizationRequestRepositoryInterface $pushedRequests,
        private AuthCodeRepositoryInterface $authCodeRepository,
        private TransactionPortInterface $transaction,
        int $authCodeTtl,
    ) {
        $this->authCodeTtl = new DateInterval(sprintf('PT%dS', $authCodeTtl));
    }

    /** @throws OAuthProtocolException */
    #[AsMessageHandler]
    public function __invoke(CreateAuthorizationCodeCommand $command): AuthorizationResponseDTO
    {
        // Both decisions run the user checks first, so no one but the request's rightful
        // user can use it up, not even by denying it.
        $request = $this->validator->validate($command->userId, $command->clientId, $command->requestUri);
        $pushedRequest = $request->pushedRequest;

        if (!$command->approved) {
            $this->useUp($pushedRequest, $request->client);

            return AuthorizationResponseDTO::denied($pushedRequest->getRedirectUri(), $pushedRequest->getState());
        }

        $code = $this->transaction->run(function () use ($request, $pushedRequest): AuthCode {
            $this->useUp($pushedRequest, $request->client);
            $code = AuthCode::create(
                $request->user,
                $request->client,
                $pushedRequest->getRedirectUri(),
                $pushedRequest->getCodeChallenge(),
                $pushedRequest->getCodeChallengeMethod(),
                $pushedRequest->getDpopJkt(),
                $pushedRequest->getScopes(),
                $this->authCodeTtl,
            );
            $this->authCodeRepository->save($code);

            return $code;
        });

        return AuthorizationResponseDTO::code($pushedRequest->getRedirectUri(), $pushedRequest->getState(), $code->getCodeId()->toString());
    }

    /** @throws OAuthProtocolException invalid_request_uri when another decision, or expiry, came first */
    private function useUp(PushedAuthorizationRequest $pushedRequest, Client $client): void
    {
        if (!$this->pushedRequests->consume($pushedRequest->getRequestId(), $client->getId())) {
            throw OAuthProtocolException::invalidRequestUri('The request_uri was already used or has expired.');
        }
    }
}
