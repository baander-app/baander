<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Doctrine\Entity\OAuth;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;

#[ORM\Entity]
#[ORM\Table(name: 'oauth_refresh_tokens')]
#[ORM\Index(name: 'idx_oauth_refresh_tokens_access_token_id', columns: ['access_token_id'])]
#[ORM\UniqueConstraint(name: 'oauth_refresh_tokens_token_id_unique', columns: ['token_id'])]
class RefreshTokenEntity implements RefreshTokenEntityInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $chainId = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'previous_refresh_token_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?RefreshTokenEntity $previousRefreshToken = null;

    #[ORM\Column(type: 'text')]
    private string $tokenId;

    #[ORM\ManyToOne(targetEntity: AccessTokenEntity::class)]
    #[ORM\JoinColumn(name: 'access_token_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AccessTokenEntity $accessToken;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $revoked = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $tokenId,
        AccessTokenEntity $accessToken,
        ?\DateTimeImmutable $expiresAt = null,
        ?Uuid $chainId = null,
        ?RefreshTokenEntity $previousRefreshToken = null,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->tokenId = $tokenId;
        $this->accessToken = $accessToken;
        $this->expiresAt = $expiresAt;
        $this->chainId = $chainId;
        $this->previousRefreshToken = $previousRefreshToken;
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

    public function getPreviousRefreshToken(): ?RefreshTokenEntity
    {
        return $this->previousRefreshToken;
    }

    public function getTokenId(): string
    {
        return $this->tokenId;
    }

    public function getAccessToken(): AccessTokenEntity
    {
        return $this->accessToken;
    }

    // --- RefreshTokenEntityInterface ---

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
        return $this->expiresAt ?? new \DateTimeImmutable('+30 days');
    }

    public function setExpiryDateTime(\DateTimeImmutable $dateTime): void
    {
        $this->expiresAt = $dateTime;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setAccessToken(AccessTokenEntityInterface $accessToken): void
    {
        if (!$accessToken instanceof AccessTokenEntity) {
            throw new \RuntimeException(sprintf(
                'Expected %s, got %s.',
                AccessTokenEntity::class,
                get_debug_type($accessToken),
            ));
        }

        $this->accessToken = $accessToken;
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function markUsed(): void
    {
        $this->usedAt = new \DateTimeImmutable();
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
