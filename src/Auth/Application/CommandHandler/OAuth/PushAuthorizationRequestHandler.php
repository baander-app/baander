<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\PushAuthorizationRequestCommand;
use App\Auth\Application\DTO\PushedAuthorizationRequestDTO;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Application\Service\AuthorizationRequestValidator;
use App\Auth\Application\Service\OAuthClientAuthenticator;
use App\Auth\Domain\Model\OAuth\PushedAuthorizationRequest;
use App\Auth\Domain\Repository\OAuth\PushedAuthorizationRequestRepositoryInterface;
use DateInterval;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Stores a client's pushed authorization request (RFC 9126) and answers its request URI.
 *
 * The client authenticates as at the token endpoint, and every check that needs no
 * user runs now. The request is bound to a DPoP key (RFC 9449 section 10.1): the key of
 * a proof the request carried, the dpop_jkt parameter, or both when they name the same
 * key. The authorization code issued for the request is later bound to that key.
 */
final readonly class PushAuthorizationRequestHandler
{
    private DateInterval $requestUriTtl;

    public function __construct(
        private OAuthClientAuthenticator $clientAuthenticator,
        private AuthorizationRequestValidator $validator,
        private PushedAuthorizationRequestRepositoryInterface $pushedRequests,
        private ClockInterface $clock,
        private int $requestUriTtlSeconds,
    ) {
        $this->requestUriTtl = new DateInterval(sprintf('PT%dS', $requestUriTtlSeconds));
    }

    /** @throws OAuthProtocolException */
    #[AsMessageHandler]
    public function __invoke(PushAuthorizationRequestCommand $command): PushedAuthorizationRequestDTO
    {
        $client = $this->clientAuthenticator->authenticate($command->credentials);
        $validated = $this->validator->validatePush(
            $client,
            $command->responseType,
            $command->redirectUri,
            $command->codeChallenge,
            $command->codeChallengeMethod,
            $command->scopes,
        );

        $request = PushedAuthorizationRequest::create(
            $client->getId(),
            $validated->redirectUri,
            $validated->scopes,
            $command->state,
            $validated->codeChallenge,
            $validated->codeChallengeMethod,
            self::keyBinding($command->proofJkt, $command->dpopJkt),
            $this->requestUriTtl,
            $this->clock->now(),
        );
        $this->pushedRequests->save($request);

        return new PushedAuthorizationRequestDTO($request->requestUri(), $this->requestUriTtlSeconds);
    }

    /**
     * The thumbprint the request is bound to (RFC 9449 section 10.1).
     *
     * @throws OAuthProtocolException invalid_request without a binding or with a malformed dpop_jkt,
     *                                invalid_dpop_proof when the proof and dpop_jkt name different keys
     */
    private static function keyBinding(?string $proofJkt, ?string $dpopJkt): string
    {
        if ($dpopJkt !== null && preg_match('/^[A-Za-z0-9_-]{43}$/', $dpopJkt) !== 1) {
            throw OAuthProtocolException::invalidRequest('The dpop_jkt parameter is not a base64url SHA-256 JWK thumbprint.');
        }

        return match (true) {
            $proofJkt !== null && $dpopJkt !== null => hash_equals($proofJkt, $dpopJkt)
                ? $proofJkt
                : throw OAuthProtocolException::invalidDpopProof('The DPoP proof key does not match the dpop_jkt parameter.'),
            $proofJkt !== null => $proofJkt,
            $dpopJkt !== null => $dpopJkt,
            default => throw OAuthProtocolException::invalidRequest('A DPoP proof or the dpop_jkt parameter is required.'),
        };
    }
}
