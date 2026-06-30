<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'webhook_delivery_logs')]
#[ORM\Index(name: 'idx_webhook_delivery_logs_webhook_id', columns: ['webhook_id'])]
class WebhookDeliveryLogEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $webhookId;

    #[ORM\Column(type: 'text')]
    private string $notificationId;

    #[ORM\Column(type: 'text')]
    private string $status;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $httpStatusCode = null;

    #[ORM\Column(type: 'integer')]
    private int $attempt;

    #[ORM\Column]
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

    public function getWebhookId(): Uuid
    {
        return $this->webhookId;
    }

    public function setWebhookId(Uuid $webhookId): void
    {
        $this->webhookId = $webhookId;
    }

    public function getNotificationId(): string
    {
        return $this->notificationId;
    }

    public function setNotificationId(string $notificationId): void
    {
        $this->notificationId = $notificationId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getHttpStatusCode(): ?int
    {
        return $this->httpStatusCode;
    }

    public function setHttpStatusCode(?int $httpStatusCode): void
    {
        $this->httpStatusCode = $httpStatusCode;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }

    public function setAttempt(int $attempt): void
    {
        $this->attempt = $attempt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
