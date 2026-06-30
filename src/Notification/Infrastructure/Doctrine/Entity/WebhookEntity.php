<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'webhooks')]
class WebhookEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'text')]
    private string $url;

    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    private ?array $categoryFilter = null;

    #[ORM\Column(type: 'text')]
    private string $secretHash;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Uuid $id)
    {
        $this->id = $id;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
    }

    public function getCategoryFilter(): ?array
    {
        return $this->categoryFilter;
    }

    public function setCategoryFilter(?array $categoryFilter): void
    {
        $this->categoryFilter = $categoryFilter;
    }

    public function getSecretHash(): string
    {
        return $this->secretHash;
    }

    public function setSecretHash(string $secretHash): void
    {
        $this->secretHash = $secretHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }
}
