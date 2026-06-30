<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Doctrine\Entity\OAuth;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;
use League\OAuth2\Server\Entities\ClientEntityInterface;

#[ORM\Entity]
#[ORM\Table(name: 'oauth_clients')]
#[ORM\UniqueConstraint(name: 'oauth_clients_public_id_unique', columns: ['public_id'])]
class ClientEntity implements ClientEntityInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'public_id')]
    private PublicId $publicId;

    #[ORM\Column(type: 'text')]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $secret = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $provider = null;

    #[ORM\Column(type: 'text')]
    private string $redirect;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $personalAccessClient = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $passwordClient = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $deviceClient = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $confidential = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $firstParty = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $revoked = false;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $userId = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        PublicId $publicId,
        string $name,
        string $redirect,
        ?string $secret = null,
        ?string $provider = null,
        bool $personalAccessClient = false,
        bool $passwordClient = false,
        bool $deviceClient = false,
        bool $confidential = false,
        bool $firstParty = false,
    ) {
        $this->id = new Uuid();
        $this->publicId = $publicId;
        $this->name = $name;
        $this->redirect = $redirect;
        $this->secret = $secret;
        $this->provider = $provider;
        $this->personalAccessClient = $personalAccessClient;
        $this->passwordClient = $passwordClient;
        $this->deviceClient = $deviceClient;
        $this->confidential = $confidential;
        $this->firstParty = $firstParty;
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

    public function getIdentifier(): string
    {
        return $this->publicId->toString();
    }

    public function getRedirectUri(): string|array
    {
        $uris = json_decode($this->redirect, true);

        if (is_array($uris)) {
            return $uris;
        }

        return $this->redirect;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getSecret(): ?string
    {
        return $this->secret;
    }

    public function setSecret(?string $secret): void
    {
        $this->secret = $secret;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function getRedirect(): string
    {
        return $this->redirect;
    }

    public function setRedirect(string $redirect): void
    {
        $this->redirect = $redirect;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isPersonalAccessClient(): bool
    {
        return $this->personalAccessClient;
    }

    public function isPasswordClient(): bool
    {
        return $this->passwordClient;
    }

    public function isDeviceClient(): bool
    {
        return $this->deviceClient;
    }

    public function isConfidential(): bool
    {
        return $this->confidential;
    }

    public function isFirstParty(): bool
    {
        return $this->firstParty;
    }

    public function isRevoked(): bool
    {
        return $this->revoked;
    }

    public function revoke(): void
    {
        $this->revoked = true;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUserId(): ?Uuid
    {
        return $this->userId;
    }

    public function setUserId(?Uuid $userId): void
    {
        $this->userId = $userId;
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
