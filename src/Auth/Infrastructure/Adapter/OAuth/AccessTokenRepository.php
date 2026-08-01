<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Adapter\OAuth;

use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\AccessTokenState;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface as DomainRepository;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\HttpFoundation\RequestStack;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;

/**
 * Anti-corruption layer adapting the league/oauth2-server AccessTokenRepositoryInterface
 * to our domain repository.
 */
final class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    public function __construct(
        private readonly DomainRepository $domainRepository,
        private readonly ClientRepositoryInterface $clientRepository,
        private readonly UserRepositoryInterface $userRepository,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, $userIdentifier = null): AccessTokenEntity
    {
        $client = $this->ensureClientEntity($clientEntity);
        $user = null;

        if ($userIdentifier !== null) {
            $domainUser = $this->userRepository->findByUuid(Uuid::fromString($userIdentifier));

            if ($domainUser !== null && $domainUser->isDisabled()) {
                throw OAuthServerException::accessDenied('User account is disabled.');
            }

            if ($domainUser !== null) {
                $user = new UserEntity(
                    $domainUser->getPublicId(),
                    $domainUser->getName(),
                    $domainUser->getEmail(),
                    $domainUser->getPassword(),
                    $domainUser->getId(),
                );
            }
        }

        return new AccessTokenEntity(
            bin2hex(random_bytes(40)),
            $client,
            $user,
            null,
            array_map(fn ($scope) => $scope->getIdentifier(), $scopes),
        );
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $entity = $this->ensureAccessTokenEntity($accessTokenEntity);

        // Read DPoP jkt from request attribute (set by OAuthController::token)
        $dpopJkt = $this->requestStack->getCurrentRequest()?->attributes->get('_dpop_jkt');
        if (\is_string($dpopJkt) && $dpopJkt !== '') {
            $entity->setDpopJkt($dpopJkt);
        }

        $clientDomain = $this->clientRepository->findClientByUuid($entity->getClient()->getId());

        $userDomain = $entity->getUser() !== null
            ? $this->userRepository->findByUuid($entity->getUser()->getId())
            : null;

        $domain = AccessToken::reconstitute(new AccessTokenState(
            id: Uuid::generate(),
            tokenId: TokenId::fromString($entity->getTokenId()),
            user: $userDomain,
            client: $clientDomain,
            name: $entity->getName(),
            scopes: array_map(fn (string $s) => new Scope($s), $entity->getScopeIdentifiers() ?? []),
            chainId: $entity->getChainId() !== null ? ChainId::fromUuid($entity->getChainId()) : null,
            expiresAt: $entity->getExpiresAt(),
            lastRefreshedAt: null,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            revoked: false,
        ));

        $this->domainRepository->save($domain);
    }

    public function revokeAccessToken($tokenId): void
    {
        $domain = $this->domainRepository->findByTokenId(TokenId::fromString($tokenId));

        if ($domain !== null) {
            $domain->revoke();
            $this->domainRepository->save($domain);
        }
    }

    public function isAccessTokenRevoked($tokenId): bool
    {
        $domain = $this->domainRepository->findByTokenId(TokenId::fromString($tokenId));

        if ($domain === null) {
            return true;
        }

        if ($domain->isRevoked()) {
            return true;
        }

        if ($domain->isExpired()) {
            return true;
        }

        return false;
    }

    private function ensureAccessTokenEntity(AccessTokenEntityInterface $entity): AccessTokenEntity
    {
        if ($entity instanceof AccessTokenEntity) {
            return $entity;
        }

        throw new \RuntimeException(sprintf(
            'Expected %s, got %s.',
            AccessTokenEntity::class,
            get_debug_type($entity),
        ));
    }

    private function ensureClientEntity(ClientEntityInterface $clientEntity): ClientEntity
    {
        if ($clientEntity instanceof ClientEntity) {
            return $clientEntity;
        }

        throw new \RuntimeException(sprintf(
            'Expected %s, got %s.',
            ClientEntity::class,
            get_debug_type($clientEntity),
        ));
    }
}
