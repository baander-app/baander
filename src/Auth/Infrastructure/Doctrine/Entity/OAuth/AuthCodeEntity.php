<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Doctrine\Entity\OAuth;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;

#[ORM\Entity]
#[ORM\Table(name: 'oauth_auth_codes')]
#[ORM\UniqueConstraint(name: 'oauth_auth_codes_code_id_unique', columns: ['code_id'])]
class AuthCodeEntity implements AuthCodeEntityInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'text')]
    private string $codeId;

    #[ORM\ManyToOne(targetEntity: UserEntity::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private UserEntity $user;

    #[ORM\ManyToOne(targetEntity: ClientEntity::class)]
    #[ORM\JoinColumn(name: 'client_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ClientEntity $client;

    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    private ?array $scopes = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $revoked = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    private ?string $redirectUri = null;

    public function __construct(
        string $codeId,
        UserEntity $user,
        ClientEntity $client,
        ?array $scopes = null,
        ?\DateTimeImmutable $expiresAt = null,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->codeId = $codeId;
        $this->user = $user;
        $this->client = $client;
        $this->scopes = $scopes;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCodeId(): string
    {
        return $this->codeId;
    }

    public function getUser(): UserEntity
    {
        return $this->user;
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

    // --- TokenInterface ---

    public function getIdentifier(): string
    {
        return $this->codeId;
    }

    public function setIdentifier(string $identifier): void
    {
        $this->codeId = $identifier;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getExpiryDateTime(): \DateTimeImmutable
    {
        return $this->expiresAt ?? new \DateTimeImmutable('+10 minutes');
    }

    public function setExpiryDateTime(\DateTimeImmutable $dateTime): void
    {
        $this->expiresAt = $dateTime;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setUserIdentifier(string $identifier): void
    {
        // User is set via constructor; this is a no-op for the league interface contract.
    }

    public function getUserIdentifier(): string
    {
        return $this->user->getId()->toString();
    }

    public function getClient(): ClientEntityInterface
    {
        return $this->client;
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
    public function getScopeEntities(): array
    {
        if ($this->scopes === null) {
            return [];
        }

        return array_map(
            fn (string $scopeId) => new ScopeEntity($scopeId, ''),
            $this->scopes,
        );
    }

    /**
     * @return ScopeEntityInterface[]
     */
    public function getScopes(): array
    {
        return $this->getScopeEntities();
    }

    // --- AuthCodeEntityInterface ---

    public function getRedirectUri(): ?string
    {
        return $this->redirectUri;
    }

    public function setRedirectUri(string $uri): void
    {
        $this->redirectUri = $uri;
    }

    public function isRevoked(): bool
    {
        return $this->revoked;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
