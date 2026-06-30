<?php

declare(strict_types=1);

namespace App\Discovery\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'server_instances')]
#[ORM\UniqueConstraint(name: 'server_instances_public_id_key', columns: ['public_id'])]
#[ORM\UniqueConstraint(name: 'server_instances_server_url_key', columns: ['server_url'])]
class ServerInstanceEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'public_id')]
    private PublicId $publicId;

    #[ORM\Column(type: 'text')]
    private string $serverUrl;

    #[ORM\Column(type: 'text')]
    private string $name;

    #[ORM\Column(type: 'text')]
    private string $apiKey;

    #[ORM\Column(type: 'text')]
    private string $version;

    #[ORM\Column(type: 'text', options: ['default' => 'online'])]
    private string $status;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastHeartbeatAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        PublicId $publicId,
        string $serverUrl,
        string $name,
        string $apiKey,
        string $version,
        string $status = 'online',
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->publicId = $publicId;
        $this->serverUrl = $serverUrl;
        $this->name = $name;
        $this->apiKey = $apiKey;
        $this->version = $version;
        $this->status = $status;
        $this->lastHeartbeatAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getPublicId(): PublicId { return $this->publicId; }
    public function getServerUrl(): string { return $this->serverUrl; }
    public function getName(): string { return $this->name; }
    public function getApiKey(): string { return $this->apiKey; }
    public function getVersion(): string { return $this->version; }
    public function getStatus(): string { return $this->status; }
    public function getLastHeartbeatAt(): ?\DateTimeImmutable { return $this->lastHeartbeatAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setVersion(string $version): void { $this->version = $version; $this->updatedAt = new \DateTimeImmutable(); }
    public function setStatus(string $status): void { $this->status = $status; $this->updatedAt = new \DateTimeImmutable(); }
    public function setLastHeartbeatAt(?\DateTimeImmutable $at): void { $this->lastHeartbeatAt = $at; $this->updatedAt = new \DateTimeImmutable(); }
}
