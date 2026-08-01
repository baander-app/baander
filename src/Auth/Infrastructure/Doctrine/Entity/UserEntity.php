<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'users_public_id_unique', columns: ['public_id'])]
#[ORM\UniqueConstraint(name: 'users_email_unique', columns: ['email'])]
class UserEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'public_id')]
    private PublicId $publicId;

    #[ORM\Column(type: 'text')]
    private string $name;

    #[ORM\Column(type: 'citext')]
    private string $email;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(type: 'text')]
    private string $password;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $totp_secret = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '["ROLE_USER"]'])]
    private array $roles = ['ROLE_USER'];

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $disabled = false;

    public function __construct(
        PublicId $publicId,
        string $name,
        string $email,
        string $password,
        string $totp_secret,
        ?Uuid $id = null,
        array $roles = ['ROLE_USER'],
    ) {
        $this->id = $id ?? new Uuid();
        $this->publicId = $publicId;
        $this->name = $name;
        $this->email = $email;
        $this->password = $password;
        $this->totp_secret = $totp_secret;
        $this->roles = $roles;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function markEmailAsVerified(): void
    {
        $this->emailVerifiedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
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

    public function getTotpSecret(): string
    {
        return $this->totp_secret;
    }

    public function setTotpSecret(string $totp_secret): void
    {
        $this->totp_secret = $totp_secret;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function setRoles(array $roles): void
    {
        $this->roles = $roles;
    }

    public function isDisabled(): bool
    {
        return $this->disabled;
    }

    public function setDisabled(bool $disabled): void
    {
        $this->disabled = $disabled;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
