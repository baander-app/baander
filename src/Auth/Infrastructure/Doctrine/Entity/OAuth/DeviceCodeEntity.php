<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Doctrine\Entity\OAuth;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use RuntimeException;

#[ORM\Entity]
#[ORM\Table(name: 'oauth_device_codes')]
#[ORM\UniqueConstraint(name: 'oauth_device_codes_device_code_unique', columns: ['device_code'])]
#[ORM\UniqueConstraint(name: 'oauth_device_codes_user_code_unique', columns: ['user_code'])]
class DeviceCodeEntity implements DeviceCodeEntityInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'text')]
    private string $deviceCode;

    #[ORM\Column(type: 'text')]
    private string $userCode;

    #[ORM\ManyToOne(targetEntity: UserEntity::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?UserEntity $user = null;

    #[ORM\ManyToOne(targetEntity: ClientEntity::class)]
    #[ORM\JoinColumn(name: 'client_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ClientEntity $client;

    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    private ?array $scopes = null;

    #[ORM\Column(type: 'text')]
    private string $verificationUri;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $verificationUriComplete = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'integer', options: ['default' => 5])]
    private int $interval = 5;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lastPolledAt = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $approved = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $denied = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $consumedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        string $deviceCode,
        string $userCode,
        ClientEntity $client,
        string $verificationUri,
        ?string $verificationUriComplete = null,
        ?array $scopes = null,
        ?DateTimeImmutable $expiresAt = null,
        int $interval = 5,
        ?Uuid $id = null,
    )
    {
        $this->id = $id ?? new Uuid();
        $this->deviceCode = $deviceCode;
        $this->userCode = $userCode;
        $this->client = $client;
        $this->verificationUri = $verificationUri;
        $this->verificationUriComplete = $verificationUriComplete;
        $this->scopes = $scopes;
        $this->expiresAt = $expiresAt;
        $this->interval = $interval;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getDeviceCode(): string
    {
        return $this->deviceCode;
    }

    public function getUser(): ?UserEntity
    {
        return $this->user;
    }

    public function setUser(?UserEntity $user): void
    {
        $this->user = $user;
        $this->updatedAt = new DateTimeImmutable();
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

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function markPolled(): void
    {
        $this->lastPolledAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function isApproved(): bool
    {
        return $this->approved;
    }

    public function approve(): void
    {
        $this->approved = true;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function isDenied(): bool
    {
        return $this->denied;
    }

    public function deny(): void
    {
        $this->denied = true;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getConsumedAt(): ?DateTimeImmutable
    {
        return $this->consumedAt;
    }

    public function consume(): void
    {
        $this->consumedAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setIdentifier(string $identifier): void
    {
        $this->deviceCode = $identifier;
        $this->updatedAt = new DateTimeImmutable();
    }

    // --- TokenInterface ---

    public function getExpiryDateTime(): DateTimeImmutable
    {
        return $this->expiresAt ?? new DateTimeImmutable('+15 minutes');
    }

    public function setExpiryDateTime(DateTimeImmutable $dateTime): void
    {
        $this->expiresAt = $dateTime;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function setUserIdentifier(string $identifier): void
    {
        // User association is handled via setUser().
    }

    public function getUserIdentifier(): ?string
    {
        return $this->user?->getId()->toString();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getClient(): ClientEntityInterface
    {
        return $this->client;
    }

    public function setClient(ClientEntityInterface $client): void
    {
        if (!$client instanceof ClientEntity) {
            throw new RuntimeException(sprintf(
                'Expected %s, got %s.',
                ClientEntity::class,
                get_debug_type($client),
            ));
        }

        $this->client = $client;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function addScope(ScopeEntityInterface $scope): void
    {
        $identifier = $scope->getIdentifier();

        if ($this->scopes === null) {
            $this->scopes = [];
        }

        if (!in_array($identifier, $this->scopes, true)) {
            $this->scopes[] = $identifier;
            $this->updatedAt = new DateTimeImmutable();
        }
    }

    public function getIdentifier(): string
    {
        return $this->deviceCode;
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
            fn(string $scopeId) => new ScopeEntity($scopeId, ''),
            $this->scopes,
        );
    }

    // --- DeviceCodeEntityInterface ---

    public function getUserCode(): string
    {
        return $this->userCode;
    }

    public function setUserCode(string $userCode): void
    {
        $this->userCode = $userCode;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getVerificationUri(): string
    {
        return $this->verificationUri;
    }

    public function setVerificationUri(string $verificationUri): void
    {
        $this->verificationUri = $verificationUri;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getVerificationUriComplete(): string
    {
        return $this->verificationUriComplete ?? '';
    }

    public function getLastPolledAt(): ?DateTimeImmutable
    {
        return $this->lastPolledAt;
    }

    public function setLastPolledAt(DateTimeImmutable $lastPolledAt): void
    {
        $this->lastPolledAt = $lastPolledAt;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getInterval(): int
    {
        return $this->interval;
    }

    public function setInterval(int $interval): void
    {
        $this->interval = $interval;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getUserApproved(): bool
    {
        return $this->approved;
    }

    public function setUserApproved(bool $userApproved): void
    {
        $this->approved = $userApproved;
        $this->updatedAt = new DateTimeImmutable();
    }
}
