<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Doctrine\Entity\OAuth;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use RuntimeException;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\CryptKeyInterface;

#[ORM\Entity]
#[ORM\Table(name: 'oauth_access_tokens')]
#[ORM\Index(name: 'idx_oauth_access_tokens_user_id', columns: ['user_id'])]
#[ORM\Index(name: 'idx_oauth_access_tokens_client_id', columns: ['client_id'])]
#[ORM\Index(name: 'idx_oauth_access_tokens_chain_id', columns: ['chain_id'])]
#[ORM\UniqueConstraint(name: 'uniq_oauth_access_tokens_token_id', columns: ['token_id'])]
class AccessTokenEntity implements AccessTokenEntityInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $chainId = null;

    #[ORM\Column(type: 'text')]
    private string $tokenId;

    #[ORM\ManyToOne(targetEntity: UserEntity::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?UserEntity $user = null;

    #[ORM\ManyToOne(targetEntity: ClientEntity::class)]
    #[ORM\JoinColumn(name: 'client_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ClientEntity $client;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $name = null;

    /** @var string[]|null */
    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    private ?array $scopes = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $revoked = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $dpopJkt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastRefreshedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    private ?CryptKeyInterface $privateKey = null;

    // Transport configuration, deliberately not persisted with the token row.
    private ?string $resourceServerUri = null;

    /** @param string[]|null $scopes */
    public function __construct(
        string $tokenId,
        ClientEntity $client,
        ?UserEntity $user = null,
        ?string $name = null,
        ?array $scopes = null,
        ?\DateTimeImmutable $expiresAt = null,
        ?Uuid $chainId = null,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->tokenId = $tokenId;
        $this->client = $client;
        $this->user = $user;
        $this->name = $name;
        $this->scopes = $scopes;
        $this->expiresAt = $expiresAt;
        $this->chainId = $chainId;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getChainId(): ?Uuid
    {
        return $this->chainId;
    }

    public function getTokenId(): string
    {
        return $this->tokenId;
    }

    public function getUser(): ?UserEntity
    {
        return $this->user;
    }

    public function getClient(): ClientEntity
    {
        return $this->client;
    }

    // --- TokenInterface ---

    public function getIdentifier(): string
    {
        return $this->tokenId;
    }

    public function setIdentifier(string $identifier): void
    {
        $this->tokenId = $identifier;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getExpiryDateTime(): \DateTimeImmutable
    {
        return $this->expiresAt ?? new \DateTimeImmutable('+1 hour');
    }

    public function setExpiryDateTime(\DateTimeImmutable $dateTime): void
    {
        $this->expiresAt = $dateTime;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setUserIdentifier(string $identifier): void
    {
        if ($this->user === null) {
            throw new \RuntimeException('Cannot set user identifier: no user entity is associated with this access token.');
        }
    }

    public function getUserIdentifier(): ?string
    {
        return $this->user?->getId()->toString();
    }

    public function setClient(ClientEntityInterface $client): void
    {
        if (!$client instanceof ClientEntity) {
            throw new \RuntimeException(sprintf(
                'Expected %s, got %s.',
                ClientEntity::class,
                get_debug_type($client),
            ));
        }

        $this->client = $client;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function addScope(ScopeEntityInterface $scope): void
    {
        $identifier = $scope->getIdentifier();

        if ($this->scopes === null) {
            $this->scopes = [];
        }

        if (!in_array($identifier, $this->scopes, true)) {
            $this->scopes[] = $identifier;
            $this->updatedAt = new \DateTimeImmutable();
        }
    }

    /**
     * @return ScopeEntityInterface[]
     */
    public function getScopes(): array
    {
        if ($this->scopes === null) {
            return [];
        }

        return array_map(
            fn (string $scopeId) => new ScopeEntity($scopeId, ''),
            $this->scopes,
        );
    }

    // --- AccessTokenEntityInterface ---

    public function setPrivateKey(CryptKeyInterface $privateKey): void
    {
        $this->privateKey = $privateKey;
    }

    public function setResourceServerUri(string $resourceServerUri): void
    {
        if (trim($resourceServerUri) === '') {
            throw new RuntimeException('A resource server audience is required to issue an access token.');
        }
        $this->resourceServerUri = $resourceServerUri;
    }

    public function toString(): string
    {
        if ($this->privateKey === null || $this->resourceServerUri === null || $this->expiresAt === null) {
            throw new RuntimeException('Access token signing requires a key, audience and expiry.');
        }
        $configuration = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($this->privateKey->getKeyContents(), $this->privateKey->getPassPhrase() ?? ''),
            InMemory::plainText('empty', 'empty'),
        );
        $now = new \DateTimeImmutable();
        $builder = $configuration->builder()
            ->permittedFor($this->resourceServerUri)
            ->identifiedBy($this->tokenId)
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($this->expiresAt)
            ->relatedTo($this->getUserIdentifier() ?? $this->client->getId()->toString())
            ->withClaim('scopes', $this->scopes ?? [])
            ->withClaim('client_id', $this->client->getId()->toString());
        if ($this->dpopJkt !== null && $this->dpopJkt !== '') {
            $builder = $builder->withClaim('cnf', ['jkt' => $this->dpopJkt]);
        }

        return $builder->getToken($configuration->signer(), $configuration->signingKey())->toString();
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Returns the raw scope identifier strings stored in the database.
     *
     * @return string[]|null
     */
    public function getScopeIdentifiers(): ?array
    {
        return $this->scopes;
    }

    /** @param string[]|null $scopes */
    public function setScopes(?array $scopes): void
    {
        $this->scopes = $scopes;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isRevoked(): bool
    {
        return $this->revoked;
    }

    public function getDpopJkt(): ?string
    {
        return $this->dpopJkt;
    }

    public function setDpopJkt(?string $dpopJkt): void
    {
        $this->dpopJkt = $dpopJkt;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function revoke(): void
    {
        $this->revoked = true;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getLastRefreshedAt(): ?\DateTimeImmutable
    {
        return $this->lastRefreshedAt;
    }

    public function markRefreshed(): void
    {
        $this->lastRefreshedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
