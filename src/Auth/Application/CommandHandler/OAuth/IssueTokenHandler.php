<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\IssueTokenCommand;
use App\Auth\Application\DTO\TokenResponseDTO;
use App\Auth\Application\Port\JwtGeneratorInterface;
use App\Auth\Application\ScopeAllowlist;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\DeviceCode;
use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\AuthCodeRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\DeviceCodeRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Domain\Service\TokenChainValidator;
use App\Shared\Domain\Model\Uuid;
use DateInterval;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for issuing OAuth 2.0 tokens across all supported grant types.
 *
 * Supported grants:
 * - authorization_code (with PKCE)
 * - client_credentials
 * - refresh_token (with rotation)
 * - direct_grant (credentials verified server-side by Symfony authenticators)
 * - urn:ietf:params:oauth:grant-type:device_code (RFC 8628)
 */
final class IssueTokenHandler
{
    private readonly DateInterval $accessTokenTtl;
    private readonly DateInterval $refreshTokenTtl;

    public function __construct(
        private readonly AccessTokenRepositoryInterface $accessTokenRepository,
        private readonly RefreshTokenRepositoryInterface $refreshTokenRepository,
        private readonly AuthCodeRepositoryInterface $authCodeRepository,
        private readonly DeviceCodeRepositoryInterface $deviceCodeRepository,
        private readonly ClientRepositoryInterface $clientRepository,
        private readonly UserRepositoryInterface $userRepository,
        private readonly TokenChainValidator $chainValidator,
        private readonly ScopeAllowlist $scopeAllowlist,
        private readonly EntityManagerInterface $entityManager,
        private readonly JwtGeneratorInterface $jwtGenerator,
        int $accessTokenTtl,
        int $refreshTokenTtl,
    ) {
        $this->accessTokenTtl = new DateInterval(sprintf('PT%dS', $accessTokenTtl));
        $this->refreshTokenTtl = new DateInterval(sprintf('PT%dS', $refreshTokenTtl));
    }

    #[AsMessageHandler]
    public function __invoke(IssueTokenCommand $command): TokenResponseDTO
    {
        return match ($command->getGrantType()) {
            'authorization_code' => $this->handleAuthorizationCode($command),
            'client_credentials' => $this->handleClientCredentials($command),
            'refresh_token' => $this->handleRefreshToken($command),
            'direct_grant' => $this->handleDirectGrant($command),
            'urn:ietf:params:oauth:grant-type:device_code' => $this->handleDeviceCode($command),
            default => throw new InvalidArgumentException(
                sprintf('Unsupported grant type: "%s".', $command->getGrantType()),
            ),
        };
    }

    /**
     * Handle the Authorization Code grant with PKCE support.
     */
    private function handleAuthorizationCode(IssueTokenCommand $command): TokenResponseDTO
    {
        $this->validateRequired($command, ['code', 'clientId']);

        $client = $this->resolveClient($command->getClientId(), $command->getClientSecret());
        $this->validateRedirectUri($client, $command->getRedirectUri());

        $codeId = TokenId::fromString($command->getCode());
        $authCode = $this->authCodeRepository->findByCodeId($codeId);

        if ($authCode === null) {
            throw new RuntimeException('Authorization code not found.');
        }

        if ($authCode->isRevoked()) {
            throw new RuntimeException('Authorization code has been revoked.');
        }

        if ($authCode->isExpired()) {
            throw new RuntimeException('Authorization code has expired.');
        }

        if (!$authCode->getClient()->getId()->equals($client->getId())) {
            throw new RuntimeException('Authorization code was not issued to this client.');
        }

        // Revoke the authorization code (one-time use)
        $authCode->revoke();

        $scopes = $this->resolveScopes($command->getScopes(), $authCode->getScopes(), $client, 'authorization_code');

        $chainId = ChainId::generate();

        $accessToken = AccessToken::issue(
            $client,
            $authCode->getUser(),
            $scopes,
            $command->getTokenName(),
            $this->accessTokenTtl,
            $chainId,
        );

        $refreshToken = RefreshToken::issue(
            $accessToken,
            $chainId,
            $this->refreshTokenTtl,
        );

        // Perform all write operations atomically
        $this->entityManager->getConnection()->transactional(function () use ($authCode, $accessToken, $refreshToken): void {
            $this->authCodeRepository->save($authCode);
            $this->accessTokenRepository->save($accessToken);
            $this->refreshTokenRepository->save($refreshToken);
        });

        return new TokenResponseDTO(
            accessToken: $this->jwtGenerator->generate($accessToken, $command->getDpopJkt()),
            expiresIn: $this->accessTokenTtl->s,
            refreshToken: $refreshToken->getTokenId()->toString(),
            scopes: $accessToken->getScopeIdentifiers(),
        );
    }

    /**
     * Handle the Client Credentials grant.
     */
    private function handleClientCredentials(IssueTokenCommand $command): TokenResponseDTO
    {
        $this->validateRequired($command, ['clientId']);

        $client = $this->resolveClient($command->getClientId(), $command->getClientSecret());

        if (!$client->isConfidential()) {
            throw new RuntimeException('Client credentials grant requires a confidential client.');
        }

        $scopes = $this->resolveScopes($command->getScopes(), [], $client, 'client_credentials');

        $accessToken = AccessToken::issue(
            $client,
            null, // No user for client_credentials
            $scopes,
            $command->getTokenName(),
            $this->accessTokenTtl,
        );

        $this->accessTokenRepository->save($accessToken);

        return new TokenResponseDTO(
            accessToken: $this->jwtGenerator->generate($accessToken, $command->getDpopJkt()),
            expiresIn: $this->accessTokenTtl->s,
            scopes: $accessToken->getScopeIdentifiers(),
        );
    }

    /**
     * Handle the Refresh Token grant with rotation.
     */
    private function handleRefreshToken(IssueTokenCommand $command): TokenResponseDTO
    {
        $this->validateRequired($command, ['clientId']);

        $client = $this->resolveClient($command->getClientId(), $command->getClientSecret());
        $refreshTokenId = TokenId::fromString($command->getCode());
        $refreshToken = $this->refreshTokenRepository->findByTokenId($refreshTokenId);

        if ($refreshToken === null) {
            throw new RuntimeException('Refresh token not found.');
        }

        if ($refreshToken->isRevoked()) {
            throw new RuntimeException('Refresh token has been revoked.');
        }

        if ($refreshToken->isExpired()) {
            throw new RuntimeException('Refresh token has expired.');
        }

        if (!$refreshToken->getAccessToken()->getClient()->getId()->equals($client->getId())) {
            throw new RuntimeException('Refresh token was not issued to this client.');
        }

        // Validate chain integrity (replay detection)
        // Tokens without a chainId are outside the rotation model and skip validation.
        if ($refreshToken->getChainId() !== null) {
            $this->chainValidator->validateWithLoadedPrevious($refreshToken);
        }

        // Mark the old refresh token as used
        $refreshToken->markUsed();

        // Issue new token pair in the same chain
        $chainId = $refreshToken->getChainId();
        $user = $refreshToken->getAccessToken()->getUser();
        $scopes = $this->resolveScopes($command->getScopes(), $refreshToken->getAccessToken()->getScopes(), $client, 'refresh_token');

        $newAccessToken = AccessToken::issue(
            $client,
            $user,
            $scopes,
            $command->getTokenName(),
            $this->accessTokenTtl,
            $chainId,
        );

        $newRefreshToken = RefreshToken::issue(
            $newAccessToken,
            $chainId,
            $this->refreshTokenTtl,
            $refreshToken, // Chain link to previous
        );

        // Perform all write operations atomically
        $this->entityManager->getConnection()->transactional(function () use ($refreshToken, $newAccessToken, $newRefreshToken): void {
            $this->refreshTokenRepository->save($refreshToken);
            $this->accessTokenRepository->save($newAccessToken);
            $this->refreshTokenRepository->save($newRefreshToken);
        });

        return new TokenResponseDTO(
            accessToken: $this->jwtGenerator->generate($newAccessToken, $command->getDpopJkt()),
            expiresIn: $this->accessTokenTtl->s,
            refreshToken: $newRefreshToken->getTokenId()->toString(),
            scopes: $newAccessToken->getScopeIdentifiers(),
        );
    }

    /**
     * Handle the Direct Grant.
     *
     * Issues tokens for an already-authenticated user (credentials verified by
     * the Symfony authenticator). The command's userId and clientId identify
     * the user and the OAuth client to issue tokens to.
     */
    private function handleDirectGrant(IssueTokenCommand $command): TokenResponseDTO
    {
        $this->validateRequired($command, ['clientId', 'userId']);

        $client = $this->resolveClient($command->getClientId(), $command->getClientSecret());

        $user = $this->userRepository->findByUuid($command->getUserId());

        if ($user === null) {
            throw new RuntimeException('User not found.');
        }

        $scopes = $this->resolveScopes($command->getScopes(), [], $client, 'direct_grant');
        $chainId = ChainId::generate();

        $accessToken = AccessToken::issue(
            $client,
            $user,
            $scopes,
            $command->getTokenName(),
            $this->accessTokenTtl,
            $chainId,
        );

        $refreshToken = RefreshToken::issue(
            $accessToken,
            $chainId,
            $this->refreshTokenTtl,
        );

        $this->entityManager->getConnection()->transactional(function () use ($accessToken, $refreshToken): void {
            $this->accessTokenRepository->save($accessToken);
            $this->refreshTokenRepository->save($refreshToken);
        });

        return new TokenResponseDTO(
            accessToken: $this->jwtGenerator->generate($accessToken, $command->getDpopJkt()),
            expiresIn: $this->accessTokenTtl->s,
            refreshToken: $refreshToken->getTokenId()->toString(),
            scopes: $accessToken->getScopeIdentifiers(),
        );
    }

    /**
     * Handle the Device Code grant (RFC 8628).
     */
    private function handleDeviceCode(IssueTokenCommand $command): TokenResponseDTO
    {
        $this->validateRequired($command, ['clientId', 'deviceCode']);

        $client = $this->resolveClient($command->getClientId(), $command->getClientSecret());
        $deviceCodeTokenId = TokenId::fromString($command->getDeviceCode());
        $deviceCode = $this->deviceCodeRepository->findByDeviceCode($deviceCodeTokenId);

        if ($deviceCode === null) {
            throw new RuntimeException('Device code not found.');
        }

        if (!$deviceCode->getClient()->getId()->equals($client->getId())) {
            throw new RuntimeException('Device code was not issued to this client.');
        }

        if ($deviceCode->isExpired()) {
            throw new RuntimeException('Device code has expired.');
        }

        if ($deviceCode->isDenied()) {
            throw new RuntimeException('Device authorization was denied.');
        }

        if ($deviceCode->isConsumed()) {
            throw new RuntimeException('Device code has already been consumed.');
        }

        if ($deviceCode->isPending()) {
            // The user has not yet approved -- return slow polling response
            return new TokenResponseDTO(
                accessToken: '',
                expiresIn: 0,
                scopes: $deviceCode->getScopeIdentifiers(),
                deviceId: $deviceCode->getUserCode(),
                verificationInterval: $deviceCode->getInterval(),
            );
        }

        // Device code is approved -- consume it to prevent double token issuance
        $deviceCode->consume();

        $scopes = $this->resolveScopes($command->getScopes(), $deviceCode->getScopes(), $client, 'urn:ietf:params:oauth:grant-type:device_code');
        $user = $deviceCode->getUser();
        $chainId = ChainId::generate();

        $accessToken = AccessToken::issue(
            $client,
            $user,
            $scopes,
            null,
            $this->accessTokenTtl,
            $chainId,
        );

        $refreshToken = RefreshToken::issue(
            $accessToken,
            $chainId,
            $this->refreshTokenTtl,
        );

        // Perform all write operations atomically
        $this->entityManager->getConnection()->transactional(function () use ($deviceCode, $accessToken, $refreshToken): void {
            $this->deviceCodeRepository->save($deviceCode);
            $this->accessTokenRepository->save($accessToken);
            $this->refreshTokenRepository->save($refreshToken);
        });

        return new TokenResponseDTO(
            accessToken: $this->jwtGenerator->generate($accessToken, $command->getDpopJkt()),
            expiresIn: $this->accessTokenTtl->s,
            refreshToken: $refreshToken->getTokenId()->toString(),
            scopes: $accessToken->getScopeIdentifiers(),
        );
    }

    /**
     * Resolve a client by its ID, optionally verifying the secret.
     */
    private function resolveClient(?Uuid $clientId, ?string $clientSecret): Client
    {
        if ($clientId === null) {
            throw new InvalidArgumentException('Client ID is required.');
        }

        $client = $this->clientRepository->findClientByUuid($clientId);

        if ($client === null) {
            throw new RuntimeException('Client not found.');
        }

        if ($client->isRevoked()) {
            throw new RuntimeException('Client has been revoked.');
        }

        if ($client->isConfidential()) {
            if ($clientSecret === null) {
                throw new RuntimeException('Client secret is required for confidential clients.');
            }

            if (!hash_equals($client->getSecret() ?? '', $clientSecret)) {
                throw new RuntimeException('Invalid client credentials.');
            }
        }

        return $client;
    }

    /**
     * Resolve scopes: merge requested scopes with default/existing scopes,
     * filtering against the scope allowlist for the given grant type.
     *
     * Scopes not present in the allowlist are silently dropped to prevent
     * privilege escalation (e.g., requesting 'admin' via authorization_code).
     *
     * @param string[] $requestedScopes
     * @param Scope[] $existingScopes
     * @param string $grantType The OAuth 2.0 grant type
     * @return Scope[]
     */
    private function resolveScopes(array $requestedScopes, array $existingScopes, Client $client, string $grantType): array
    {
        if ($requestedScopes === []) {
            // No scopes requested: fall back to existing (e.g., from auth code or previous token).
            // Filter existing scopes against the allowlist in case they were issued under
            // different allowlist rules.
            $existingIdentifiers = array_map(
                static fn (Scope $s): string => $s->toString(),
                $existingScopes,
            );

            if ($existingIdentifiers !== []) {
                $filteredIdentifiers = $this->scopeAllowlist->filter($existingIdentifiers, $grantType);

                return array_map(
                    static fn (string $s): Scope => new Scope($s),
                    $filteredIdentifiers,
                );
            }

            return Scope::defaultScopes();
        }

        // Filter requested scopes against the allowlist
        $filteredScopes = $this->scopeAllowlist->filter($requestedScopes, $grantType);

        $scopeObjects = array_map(
            fn (string $s) => new Scope($s),
            $filteredScopes,
        );

        // Merge with existing scopes (union), also filtering existing scopes
        $existingIdentifiers = array_map(
            static fn (Scope $s): string => $s->toString(),
            $existingScopes,
        );
        $filteredExistingIdentifiers = $this->scopeAllowlist->filter($existingIdentifiers, $grantType);

        $merged = [];
        foreach ($scopeObjects as $scope) {
            $merged[$scope->toString()] = $scope;
        }
        foreach ($filteredExistingIdentifiers as $identifier) {
            if (!isset($merged[$identifier])) {
                $merged[$identifier] = new Scope($identifier);
            }
        }

        return array_values($merged);
    }

    /**
     * Validate that the redirect URI is allowed for the client.
     */
    private function validateRedirectUri(Client $client, ?string $redirectUri): void
    {
        if ($redirectUri === null) {
            return;
        }

        $allowedUris = $client->getRedirectUris();
        if ($allowedUris !== [] && !in_array($redirectUri, $allowedUris, true)) {
            throw new RuntimeException('Redirect URI is not allowed for this client.');
        }
    }

    /**
     * Validate that required command fields are present.
     *
     * @param string[] $fields
     */
    private function validateRequired(IssueTokenCommand $command, array $fields): void
    {
        foreach ($fields as $field) {
            $value = match ($field) {
                'clientId' => $command->getClientId(),
                'userId' => $command->getUserId(),
                'code' => $command->getCode(),
                'deviceCode' => $command->getDeviceCode(),
                default => null,
            };

            if ($value === null || (is_string($value) && trim($value) === '')) {
                throw new InvalidArgumentException(
                    sprintf('The field "%s" is required for the "%s" grant.', $field, $command->getGrantType()),
                );
            }
        }
    }

    /**
     * Store token metadata (fingerprint, user agent, IP) for binding verification.
     */
    private function storeTokenMetadata(): void
    {
        // TODO: Implement token metadata storage for IP/user-agent/device fingerprinting
    }
}

