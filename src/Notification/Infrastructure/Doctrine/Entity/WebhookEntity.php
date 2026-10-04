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

    /** @var list<string>|null */
    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    private ?array $categoryFilter = null;

    #[ORM\Column(type: 'text')]
    private string $secretHash;

    #[ORM\Column(type: 'smallint', options: ['default' => 1])]
    private int $signingVersion = 1;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $encryptedSecret = null;

    public function getSigningVersion(): int
    {
        return $this->signingVersion;
    }

    public function getEncryptedSecret(): ?string
    {
        return $this->encryptedSecret;
    }

    public function setEncryptedSigningSecret(string $encryptedSecret, string $secretHash): void
    {
        $this->encryptedSecret = $encryptedSecret;
        $this->secretHash = $secretHash;
        $this->signingVersion = 2;
        $this->updatedAt = new \DateTimeImmutable();
    }

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

    /** @return list<string>|null */
    public function getCategoryFilter(): ?array
    {
        return $this->categoryFilter;
    }

    /** @param list<string>|null $categoryFilter */
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
