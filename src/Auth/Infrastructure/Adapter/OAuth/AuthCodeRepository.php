<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Adapter\OAuth;

use App\Auth\Domain\Model\OAuth\AuthCode;
use App\Auth\Domain\Model\OAuth\AuthCodeState;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Repository\OAuth\AuthCodeRepositoryInterface as DomainRepository;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AuthCodeEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\Uuid;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;

/**
 * Anti-corruption layer adapting the league/oauth2-server AuthCodeRepositoryInterface
 * to our domain repository.
 */
final class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    public function __construct(
        private readonly DomainRepository $domainRepository,
        private readonly ClientRepositoryInterface $clientRepository,
        private readonly UserRepositoryInterface $userRepository,
    ) {
    }

    public function getNewAuthCode(): AuthCodeEntity
    {
        return new AuthCodeEntity(
            bin2hex(random_bytes(40)),
            new UserEntity(new \App\Shared\Domain\Model\PublicId(), '', '', ''),
            new \App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity(
                new \App\Shared\Domain\Model\PublicId(),
                '',
                '',
            ),
        );
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $entity = $this->ensureAuthCodeEntity($authCodeEntity);

        $existing = $this->domainRepository->findByCodeId(TokenId::fromString($entity->getIdentifier()));

        if ($existing !== null) {
            throw UniqueTokenIdentifierConstraintViolationException::create();
        }

        $userDomain = $this->userRepository->findByUuid($entity->getUser()->getId());
        $clientDomain = $this->clientRepository->findClientByUuid($entity->getClient()->getId());

        $domain = AuthCode::reconstitute(new AuthCodeState(
            id: Uuid::generate(),
            codeId: TokenId::fromString($entity->getCodeId()),
            user: $userDomain,
            client: $clientDomain,
            scopes: array_map(fn (string $s) => new Scope($s), $entity->getScopeIdentifiers() ?? []),
            expiresAt: $entity->getExpiresAt(),
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            revoked: false,
        ));

        $this->domainRepository->save($domain);
    }

    public function revokeAuthCode(string $codeId): void
    {
        $domain = $this->domainRepository->findByCodeId(TokenId::fromString($codeId));

        if ($domain !== null) {
            $domain->revoke();
            $this->domainRepository->save($domain);
        }
    }

    public function isAuthCodeRevoked(string $codeId): bool
    {
        $domain = $this->domainRepository->findByCodeId(TokenId::fromString($codeId));

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

    private function ensureAuthCodeEntity(AuthCodeEntityInterface $entity): AuthCodeEntity
    {
        if ($entity instanceof AuthCodeEntity) {
            return $entity;
        }

        throw new \RuntimeException(sprintf(
            'Expected %s, got %s.',
            AuthCodeEntity::class,
            get_debug_type($entity),
        ));
    }
}
