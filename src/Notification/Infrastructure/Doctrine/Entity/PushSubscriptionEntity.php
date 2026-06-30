<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'push_subscriptions')]
#[ORM\Index(name: 'idx_push_subscriptions_user_id', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'idx_push_subscriptions_endpoint', columns: ['endpoint'])]
class PushSubscriptionEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(type: 'text')]
    private string $endpoint;

    #[ORM\Column(type: 'text')]
    private string $publicKey;

    #[ORM\Column(type: 'text')]
    private string $authKey;

    #[ORM\Column(type: 'text')]
    private string $contentEncoding;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Uuid $userId,
        string $endpoint,
        string $publicKey,
        string $authKey,
        string $contentEncoding,
        ?string $userAgent = null,
    ) {
        $this->id = new Uuid();
        $this->userId = $userId;
        $this->endpoint = $endpoint;
        $this->publicKey = $publicKey;
        $this->authKey = $authKey;
        $this->contentEncoding = $contentEncoding;
        $this->userAgent = $userAgent;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function getAuthKey(): string
    {
        return $this->authKey;
    }

    public function getContentEncoding(): string
    {
        return $this->contentEncoding;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
