<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Doctrine\Entity;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'notifications')]
#[ORM\Index(name: 'idx_notifications_user_created', columns: ['user_id', 'created_at'])]
#[ORM\Index(name: 'idx_notifications_user_read', columns: ['user_id', 'is_read'])]
#[ORM\UniqueConstraint(name: 'notifications_public_id_key', columns: ['public_id'])]
class NotificationEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'public_id', columnDefinition: 'VARCHAR(21)')]
    private PublicId $publicId;

    #[ORM\ManyToOne(targetEntity: UserEntity::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private UserEntity $user;

    #[ORM\Column(type: 'text')]
    private string $category;

    #[ORM\Column(type: 'text')]
    private string $eventType;

    #[ORM\Column(type: 'text')]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isRead = false;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    private ?array $referenceData = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    private ?array $parameters = null;

    #[ORM\Column(type: 'datetime_immutable', options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    public function __construct(Uuid $id)
    {
        $this->id = $id;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPublicId(): PublicId
    {
        return $this->publicId;
    }

    public function setPublicId(PublicId $publicId): void
    {
        $this->publicId = $publicId;
    }

    public function getUserId(): Uuid
    {
        return $this->user->getId();
    }

    public function getUser(): UserEntity
    {
        return $this->user;
    }

    public function setUser(UserEntity $user): void
    {
        $this->user = $user;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): void
    {
        $this->category = $category;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function setEventType(string $eventType): void
    {
        $this->eventType = $eventType;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
    }

    public function isRead(): bool
    {
        return $this->isRead;
    }

    public function setIsRead(bool $isRead): void
    {
        $this->isRead = $isRead;
    }

    /** @return array<string, mixed>|null */
    public function getReferenceData(): ?array
    {
        return $this->referenceData;
    }

    /** @param array<string, mixed>|null $referenceData */
    public function setReferenceData(?array $referenceData): void
    {
        $this->referenceData = $referenceData;
    }

    /** @return array<string, mixed>|null */
    public function getParameters(): ?array
    {
        return $this->parameters;
    }

    /** @param array<string, mixed>|null $parameters */
    public function setParameters(?array $parameters): void
    {
        $this->parameters = $parameters;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
