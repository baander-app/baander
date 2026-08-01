<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'passkeys')]
#[ORM\Index(name: 'idx_passkeys_user_id', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'passkeys_credential_id_unique', columns: ['credential_id'])]
class PasskeyEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: UserEntity::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private UserEntity $user;

    #[ORM\Column(type: 'text')]
    private string $name;

    #[ORM\Column(type: 'text')]
    private string $credentialId;

    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '{}'])]
    private array $data;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $counter;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        UserEntity $user,
        string $name,
        string $credentialId,
        array $data,
        int $counter = 0,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->user = $user;
        $this->name = $name;
        $this->credentialId = $credentialId;
        $this->data = $data;
        $this->counter = $counter;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getCredentialId(): string
    {
        return $this->credentialId;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): void
    {
        $this->data = $data;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCounter(): int
    {
        return $this->counter;
    }

    public function setCounter(int $counter): void
    {
        $this->counter = $counter;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markUsed(): void
    {
        $this->lastUsedAt = new \DateTimeImmutable();
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
