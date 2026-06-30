<?php

declare(strict_types=1);

namespace App\Recommendation\Infrastructure\Doctrine\Entity;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'recommendations')]
#[ORM\Index(name: 'idx_recommendations_user_id', columns: ['user_id'])]
class RecommendationEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: UserEntity::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?UserEntity $user = null;

    #[ORM\Column(type: 'text', options: ['default' => 'default'])]
    private string $name = 'default';

    #[ORM\Column(type: 'text')]
    private string $sourceType;

    #[ORM\Column(type: 'text')]
    private string $sourceId;

    #[ORM\Column(type: 'text')]
    private string $targetType;

    #[ORM\Column(type: 'text')]
    private string $targetId;

    #[ORM\Column(type: 'float', options: ['default' => '0'])]
    private float $score = 0.0;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $position = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $sourceType,
        string $sourceId,
        string $targetType,
        string $targetId,
        ?UserEntity $user = null,
        string $name = 'default',
        float $score = 0.0,
        ?int $position = null,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->user = $user;
        $this->name = $name;
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->score = $score;
        $this->position = $position;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): ?UserEntity
    {
        return $this->user;
    }

    public function setUser(?UserEntity $user): void
    {
        $this->user = $user;
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getTargetType(): string
    {
        return $this->targetType;
    }

    public function getTargetId(): string
    {
        return $this->targetId;
    }

    public function getScore(): float
    {
        return $this->score;
    }

    public function setScore(float $score): void
    {
        $this->score = $score;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(?int $position): void
    {
        $this->position = $position;
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
