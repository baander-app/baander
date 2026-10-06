<?php

declare(strict_types=1);

namespace App\Radio\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'radio_stations')]
#[ORM\UniqueConstraint(name: 'uniq_radio_stations_source_id_external_id', columns: ['source_id', 'external_id'])]
#[ORM\Index(name: 'idx_radio_stations_country', columns: ['country'])]
#[ORM\Index(name: 'idx_radio_stations_source_id_country', columns: ['source_id', 'country'])]
class RadioStationEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: RadioSourceEntity::class)]
    #[ORM\JoinColumn(name: 'source_id', referencedColumnName: 'id', nullable: false)]
    private RadioSourceEntity $source;

    #[ORM\Column(type: 'text')]
    private string $externalId;

    #[ORM\Column(type: 'text')]
    private string $name;

    #[ORM\Column(type: 'text')]
    private string $country;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $language = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '[]'])]
    private array $genres = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '[]'])]
    private array $tags = [];

    /** @var list<array{url: string, format: string, bitrate: int, reliability: float}> */
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
        RadioSourceEntity $source,
        string $externalId,
        string $name,
        string $country,
    ) {
        $this->id = $id;
        $this->source = $source;
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
        return $this->source->getId();
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

    /** @return list<string> */
    public function getGenres(): array
    {
        return $this->genres;
    }

    /** @param list<string> $genres */
    public function setGenres(array $genres): void
    {
        $this->genres = $genres;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** @return list<string> */
    public function getTags(): array
    {
        return $this->tags;
    }

    /** @param list<string> $tags */
    public function setTags(array $tags): void
    {
        $this->tags = $tags;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** @return list<array{url: string, format: string, bitrate: int, reliability: float}> */
    public function getStreams(): array
    {
        return $this->streams;
    }

    /** @param list<array{url: string, format: string, bitrate: int, reliability: float}> $streams */
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
