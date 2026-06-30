<?php

declare(strict_types=1);

namespace App\Radio\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'radio_stations')]
#[ORM\UniqueConstraint(name: 'radio_stations_source_id_external_id_key', columns: ['source_id', 'external_id'])]
class RadioStationEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $sourceId;

    #[ORM\Column(type: 'text')]
    private string $externalId;

    #[ORM\Column(type: 'text')]
    private string $name;

    #[ORM\Column(type: 'text')]
    private string $country;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $language = null;

    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '[]'])]
    private array $genres = [];

    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '[]'])]
    private array $tags = [];

    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '[]'])]
    private array $streams = [];

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $logo = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $website = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastCheckedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $id,
        Uuid $sourceId,
        string $externalId,
        string $name,
        string $country,
    ) {
        $this->id = $id;
        $this->sourceId = $sourceId;
        $this->externalId = $externalId;
        $this->name = $name;
        $this->country = $country;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSourceId(): Uuid
    {
        return $this->sourceId;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
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

    public function getCountry(): string
    {
        return $this->country;
    }

    public function setCountry(string $country): void
    {
        $this->country = $country;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(?string $language): void
    {
        $this->language = $language;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getGenres(): array
    {
        return $this->genres;
    }

    public function setGenres(array $genres): void
    {
        $this->genres = $genres;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function setTags(array $tags): void
    {
        $this->tags = $tags;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getStreams(): array
    {
        return $this->streams;
    }

    public function setStreams(array $streams): void
    {
        $this->streams = $streams;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): void
    {
        $this->logo = $logo;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): void
    {
        $this->website = $website;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getLastCheckedAt(): ?\DateTimeImmutable
    {
        return $this->lastCheckedAt;
    }

    public function setLastCheckedAt(?\DateTimeImmutable $lastCheckedAt): void
    {
        $this->lastCheckedAt = $lastCheckedAt;
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

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }
}
