<?php

declare(strict_types=1);

namespace App\Favorites\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user_favorites')]
#[ORM\UniqueConstraint(name: 'user_favorites_public_id_key', columns: ['public_id'])]
#[ORM\UniqueConstraint(name: 'user_favorites_user_entity_key', columns: ['user_id', 'entity_type', 'entity_public_id'])]
class UserFavoriteEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'public_id')]
    private PublicId $publicId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(type: 'text')]
    private string $entityType;

    #[ORM\Column(type: 'text')]
    private string $entityPublicId;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        PublicId $publicId,
        Uuid $userId,
        string $entityType,
        string $entityPublicId,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->publicId = $publicId;
        $this->userId = $userId;
        $this->entityType = $entityType;
        $this->entityPublicId = $entityPublicId;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getPublicId(): PublicId { return $this->publicId; }
    public function getUserId(): Uuid { return $this->userId; }
    public function getEntityType(): string { return $this->entityType; }
    public function getEntityPublicId(): string { return $this->entityPublicId; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
