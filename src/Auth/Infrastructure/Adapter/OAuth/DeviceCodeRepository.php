<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Adapter\OAuth;

use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\DeviceCode;
use App\Auth\Domain\Model\OAuth\DeviceCodeState;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\DeviceCodeRepositoryInterface as DomainRepository;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\DeviceCodeEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Anti-corruption layer adapting the league/oauth2-server DeviceCodeRepositoryInterface
 * to our domain repository.
 */
final class DeviceCodeRepository implements DeviceCodeRepositoryInterface
{
    public function __construct(
        private readonly DomainRepository $domainRepository,
        private readonly ClientRepositoryInterface $clientRepository,
        private readonly UserRepositoryInterface $userRepository,
        private readonly JsonEncoder $jsonEncoder,
    ) {
    }

    public function getNewDeviceCode(): DeviceCodeEntityInterface
    {
        return new DeviceCodeEntity(
            bin2hex(random_bytes(20)),
            $this->generateUserCode(),
            new ClientEntity(new PublicId(), '', ''),
            '/device/verify',
        );
    }

    public function persistDeviceCode(DeviceCodeEntityInterface $deviceCodeEntity): void
    {
        $entity = $this->ensureDeviceCodeEntity($deviceCodeEntity);

        $existing = $this->domainRepository->findByDeviceCode(TokenId::fromString($entity->getDeviceCode()));

        if ($existing !== null) {
            throw UniqueTokenIdentifierConstraintViolationException::create();
        }

        $clientDomain = $this->clientRepository->findClientByUuid($entity->getClient()->getId());

        $userDomain = $entity->getUser() !== null
            ? $this->userRepository->findByUuid($entity->getUser()->getId())
            : null;

        $domain = DeviceCode::reconstitute(new DeviceCodeState(
            id: Uuid::generate(),
            deviceCode: TokenId::fromString($entity->getDeviceCode()),
            userCode: $entity->getUserCode(),
            user: $userDomain,
            client: $clientDomain,
            scopes: array_map(fn (string $s) => new Scope($s), $entity->getScopeIdentifiers() ?? []),
            verificationUri: $entity->getVerificationUri(),
            verificationUriComplete: $entity->getVerificationUriComplete() !== '' ? $entity->getVerificationUriComplete() : null,
            expiresAt: $entity->getExpiresAt(),
            interval: $entity->getInterval(),
            lastPolledAt: null,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            approved: false,
            denied: false,
            consumedAt: null,
        ));

        $this->domainRepository->save($domain);
    }

    public function getDeviceCodeEntityByDeviceCode(string $deviceCode): ?DeviceCodeEntityInterface
    {
        $domain = $this->domainRepository->findByDeviceCode(TokenId::fromString($deviceCode));

        if ($domain === null) {
            return null;
        }

        return $this->domainToEntity($domain);
    }

    public function revokeDeviceCode(string $codeId): void
    {
        $domain = $this->domainRepository->findById(Uuid::fromString($codeId));

        if ($domain !== null) {
            $domain->deny();
            $this->domainRepository->save($domain);
        }
    }

    public function isDeviceCodeRevoked(string $codeId): bool
    {
        $domain = $this->domainRepository->findById(Uuid::fromString($codeId));

        if ($domain === null) {
            return true;
        }

        if ($domain->isDenied()) {
            return true;
        }

        if ($domain->isExpired()) {
            return true;
        }

        return false;
    }

    // --- Internal ---

    private function ensureDeviceCodeEntity(DeviceCodeEntityInterface $entity): DeviceCodeEntity
    {
        if ($entity instanceof DeviceCodeEntity) {
            return $entity;
        }

        throw new \RuntimeException(sprintf(
            'Expected %s, got %s.',
            DeviceCodeEntity::class,
            get_debug_type($entity),
        ));
    }

    private function domainToEntity(DeviceCode $domain): DeviceCodeEntity
    {
        $clientEntity = $this->domainClientToEntity($domain->getClient());

        $entity = new DeviceCodeEntity(
            $domain->getDeviceCode()->toString(),
            $domain->getUserCode(),
            $clientEntity,
            $domain->getVerificationUri(),
            $domain->getVerificationUriComplete(),
            $domain->getScopes() !== [] ? $domain->getScopeIdentifiers() : null,
            $domain->getExpiresAt(),
            $domain->getInterval(),
        );

        if ($domain->getUser() !== null) {
            $entity->setUser($this->domainUserToEntity($domain->getUser()));
        }

        if ($domain->isApproved()) {
            $entity->approve();
        }

        if ($domain->isDenied()) {
            $entity->deny();
        }

        return $entity;
    }

    private function domainUserToEntity(User $user): UserEntity
    {
        return new UserEntity(
            $user->getPublicId(),
            $user->getName(),
            $user->getEmail(),
            $user->getPassword(),
            $user->getId(),
        );
    }

    private function domainClientToEntity(Client $client): ClientEntity
    {
        return new ClientEntity(
            $client->getPublicId(),
            $client->getName(),
            $this->jsonEncoder->encode($client->getRedirectUris(), 'json'),
            $client->getSecret(),
            null,
            $client->isPersonalAccessClient(),
            $client->isPasswordClient(),
            $client->isDeviceClient(),
            $client->isConfidential(),
            $client->isFirstParty(),
        );
    }

    private function generateUserCode(): string
    {
        $chars = 'BCDFGHJKLMNPQRSTVWXZ';

        return $chars[random_int(0, strlen($chars) - 1)]
            . $chars[random_int(0, strlen($chars) - 1)]
            . $chars[random_int(0, strlen($chars) - 1)]
            . $chars[random_int(0, strlen($chars) - 1)]
            . '-'
            . $chars[random_int(0, strlen($chars) - 1)]
            . $chars[random_int(0, strlen($chars) - 1)]
            . $chars[random_int(0, strlen($chars) - 1)]
            . $chars[random_int(0, strlen($chars) - 1)];
    }
}
