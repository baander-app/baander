<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'third_party_credentials')]
#[ORM\Index(name: 'idx_third_party_credentials_user_id', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'third_party_credentials_public_id_unique', columns: ['public_id'])]
class ThirdPartyCredentialEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'public_id')]
    private PublicId $publicId;

    #[ORM\ManyToOne(targetEntity: UserEntity::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private UserEntity $user;

    #[ORM\Column(type: 'text')]
    private string $provider;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $accessToken = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $refreshToken = null;

    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '{}'])]
    private array $meta;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        PublicId $publicId,
        UserEntity $user,
        string $provider,
        ?string $accessToken = null,
        ?string $refreshToken = null,
        array $meta = [],
        ?\DateTimeImmutable $expiresAt = null,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->publicId = $publicId;
        $this->user = $user;
        $this->provider = $provider;
        $this->accessToken = $accessToken;
        $this->refreshToken = $refreshToken;
        $this->meta = $meta;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPublicId(): PublicId
    {
        return $this->publicId;
    }

    public function getUser(): UserEntity
    {
        return $this->user;
    }

    public function getProvider(): string
    {
        return $this->provider;
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

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    public function setAccessToken(?string $accessToken): void
    {
        $this->accessToken = $accessToken;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function setRefreshToken(?string $refreshToken): void
    {
        $this->refreshToken = $refreshToken;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getMeta(): array
    {
        return $this->meta;
    }

    public function setMeta(array $meta): void
    {
        $this->meta = $meta;
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
