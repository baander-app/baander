<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

use App\Auth\Domain\Repository\OAuth\TokenMetadataRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Model\Uuid;
use League\OAuth2\Server\ResourceServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authenticates API requests using OAuth 2.0 Bearer tokens.
 *
 * Extracts the token from the Authorization header, validates it through
 * the league/oauth2-server ResourceServer, and creates a Passport with
 * the user loaded from the database by UUID.
 */
final class OAuth2Authenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly ResourceServer $resourceServer,
        private readonly UserRepositoryInterface $userRepository,
        private readonly HttpMessageFactoryInterface $psrHttpFactory,
        private readonly LoggerInterface $logger,
        private readonly TokenMetadataRepositoryInterface $tokenMetadataRepository,
    ) {
    }

    public function supports(Request $request): bool
    {
        $authHeader = $request->headers->get('Authorization', '');

        return $authHeader !== ''
            && (str_starts_with($authHeader, 'Bearer ') || str_starts_with($authHeader, 'DPoP '));
    }

    public function authenticate(Request $request): Passport
    {
        $psrRequest = $this->psrHttpFactory->createRequest($request);

        // Translate DPoP Authorization scheme to Bearer for League's BearerTokenValidator
        $authHeader = $psrRequest->getHeaderLine('Authorization');
        if (str_starts_with($authHeader, 'DPoP ')) {
            $psrRequest = $psrRequest->withHeader('Authorization', 'Bearer ' . substr($authHeader, 5));
        }

        try {
            $validatedRequest = $this->resourceServer->validateAuthenticatedRequest($psrRequest);
        } catch (OAuthServerException $exception) {
            $this->logger->debug('OAuth2 authentication failed.', ['exception' => $exception]);
            throw new CustomUserMessageAuthenticationException(
                'Invalid or expired token.',
                ['error_code' => 'AUTH_INVALID_TOKEN'],
            );
        }

        $userIdentifier = $validatedRequest->getAttribute('oauth_user_id');
        $clientId = $validatedRequest->getAttribute('oauth_client_id');
        $scopes = $validatedRequest->getAttribute('oauth_scopes', []);
        $accessTokenId = $validatedRequest->getAttribute('oauth_access_token_id');

        if ($userIdentifier === null || $userIdentifier === '') {
            throw new CustomUserMessageAuthenticationException(
                'Invalid or expired token.',
                ['error_code' => 'AUTH_INVALID_TOKEN'],
            );
        }

        // Copy the access token ID to the Symfony request attributes so that
        // TokenBindingListener can read it to verify token-to-client bindings.
        if ($accessTokenId !== null) {
            $request->attributes->set('oauth_access_token_id', $accessTokenId);
        }

        // Enforce device fingerprint binding when a fingerprint was captured at issuance.
        $this->verifyTokenBinding($accessTokenId, $request);

        $badge = new UserBadge(
            $userIdentifier,
            function (string $uuid): SecurityUser {
                $user = $this->userRepository->findByUuid(Uuid::fromString($uuid));

                if ($user === null) {
                    $this->logger->warning('OAuth2 authenticated user not found in database.', ['uuid' => $uuid]);
                    throw new CustomUserMessageAuthenticationException(
                        'Invalid or expired token.',
                        ['error_code' => 'AUTH_INVALID_TOKEN'],
                    );
                }

                return new SecurityUser(
                    $user->getId()->toString(),
                    $user->getEmail(),
                    $user->getPassword(),
                    $user->getRoles(),
                );
            },
            [
                'oauth_client_id' => $clientId,
                'oauth_scopes' => $scopes,
            ],
        );

        return new SelfValidatingPassport($badge);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    /**
     * Verify token-to-device binding.
     *
     * If the access token was issued with a device fingerprint and the current
     * request supplies a different fingerprint, authentication is rejected.
     * Missing fingerprint on either side is tolerated so existing clients are
     * not broken.
     */
    private function verifyTokenBinding(?string $accessTokenId, Request $request): void
    {
        if ($accessTokenId === null || trim($accessTokenId) === '') {
            return;
        }

        try {
            $metadata = $this->tokenMetadataRepository->findByTokenId(
                Uuid::fromString($accessTokenId),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to load token metadata for binding check.', [
                'exception' => $e,
            ]);

            return;
        }

        if ($metadata === null) {
            return;
        }

        $storedFingerprint = $metadata->getClientFingerprint();
        if ($storedFingerprint === null || $storedFingerprint === '') {
            return;
        }

        $requestFingerprint = $request->headers->get('X-Client-Fingerprint', '');
        if ($requestFingerprint === '') {
            return;
        }

        if (!hash_equals($storedFingerprint, $requestFingerprint)) {
            $this->logger->warning('Token device fingerprint mismatch.', [
                'access_token_id' => $accessTokenId,
            ]);

            throw new CustomUserMessageAuthenticationException(
                'Invalid or expired token.',
                ['error_code' => 'AUTH_INVALID_TOKEN'],
            );
        }
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $messageData = $exception instanceof CustomUserMessageAuthenticationException
            ? $exception->getMessageData()
            : [];

        $errorCode = $messageData['error_code'] ?? 'AUTH_INVALID_TOKEN';

        return new JsonResponse([
            'error' => [
                'message' => $exception->getMessageKey(),
                'code' => $errorCode,
            ],
        ], Response::HTTP_UNAUTHORIZED);
    }
}
