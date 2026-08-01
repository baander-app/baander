<?php

declare(strict_types=1);

namespace App\Playlist\Infrastructure\Doctrine\Entity;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'playlists')]
#[ORM\Index(name: 'idx_playlists_user_id', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'playlists_public_id_unique', columns: ['public_id'])]
class PlaylistEntity
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
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isPublic = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isCollaborative = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isSmart = false;

    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '{}'])]
    private array $smartRules = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        PublicId $publicId,
        UserEntity $user,
        string $name,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->publicId = $publicId;
        $this->user = $user;
        $this->name = $name;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function setPublic(bool $isPublic): void
    {
        $this->isPublic = $isPublic;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isCollaborative(): bool
    {
        return $this->isCollaborative;
    }

    public function setCollaborative(bool $isCollaborative): void
    {
        $this->isCollaborative = $isCollaborative;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isSmart(): bool
    {
        return $this->isSmart;
    }

    public function setSmart(bool $isSmart): void
    {
        $this->isSmart = $isSmart;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getSmartRules(): array
    {
        return $this->smartRules;
    }

    public function setSmartRules(array $smartRules): void
    {
        $this->smartRules = $smartRules;
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
