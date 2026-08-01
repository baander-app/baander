<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final class Artist
{
    /** @var string[] */
    private const LOCKABLE_FIELDS = [
        'name', 'country', 'gender', 'type', 'lifeSpanBegin',
        'lifeSpanEnd', 'disambiguation', 'sortName', 'biography',
    ];

    private function __construct(
        private ArtistState $state,
    ) {
    }

    /**
     * Create a new Artist aggregate root.
     */
    public static function create(
        string $name,
        ?string $country = null,
        ?string $gender = null,
        ?string $type = null,
        ?DateTimeInterface $lifeSpanBegin = null,
        ?DateTimeInterface $lifeSpanEnd = null,
        ?string $disambiguation = null,
        ?string $sortName = null,
        ?string $biography = null,
        ?string $mbid = null,
        ?string $discogsId = null,
        ?string $spotifyId = null,
    ): self {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Artist name cannot be empty.');
        }

        return new self(new ArtistState(
            id: new Uuid(),
            publicId: new PublicId(),
            name: $name,
            country: $country,
            gender: $gender,
            type: $type,
            lifeSpanBegin: $lifeSpanBegin,
            lifeSpanEnd: $lifeSpanEnd,
            disambiguation: $disambiguation,
            sortName: $sortName,
            biography: $biography,
            mbid: $mbid,
            discogsId: $discogsId,
            spotifyId: $spotifyId,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        ));
    }

    /**
     * Reconstitute an Artist from persistence.
     *
     * This is intended for use by the repository layer only.
     */
    public static function reconstitute(ArtistState $state): self
    {
        return new self($state);
    }

    /**
     * Update artist metadata fields.
     */
    public function updateMetadata(
        ?string $name = null,
        ?string $country = null,
        ?string $gender = null,
        ?string $type = null,
        ?DateTimeInterface $lifeSpanBegin = null,
        ?DateTimeInterface $lifeSpanEnd = null,
        ?string $disambiguation = null,
        ?string $sortName = null,
        ?string $biography = null,
    ): void {
        if ($name !== null && $this->isFieldLocked('name')) {
            throw new InvalidArgumentException('Field "name" is locked and cannot be updated.');
        }

        if ($name !== null) {
            if (trim($name) === '') {
                throw new InvalidArgumentException('Artist name cannot be empty.');
            }
            $this->state->name = trim($name);
        }

        $this->state->country = $country ?? $this->state->country;
        $this->state->gender = $gender ?? $this->state->gender;
        $this->state->type = $type ?? $this->state->type;
        $this->state->lifeSpanBegin = $lifeSpanBegin ?? $this->state->lifeSpanBegin;
        $this->state->lifeSpanEnd = $lifeSpanEnd ?? $this->state->lifeSpanEnd;
        $this->state->disambiguation = $disambiguation ?? $this->state->disambiguation;
        $this->state->sortName = $sortName ?? $this->state->sortName;
        $this->state->biography = $biography ?? $this->state->biography;

        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function updateExternalIds(
        ?string $mbid = null,
        ?string $discogsId = null,
        ?string $spotifyId = null,
    ): void {
        $this->state->mbid = $mbid ?? $this->state->mbid;
        $this->state->discogsId = $discogsId ?? $this->state->discogsId;
        $this->state->spotifyId = $spotifyId ?? $this->state->spotifyId;

        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function lockField(string $field): void
    {
        if (!in_array($field, self::LOCKABLE_FIELDS, true)) {
            throw new InvalidArgumentException(sprintf('Cannot lock unknown field "%s".', $field));
        }

        if (!in_array($field, $this->state->lockedFields, true)) {
            $this->state->lockedFields[] = $field;
            $this->state->updatedAt = new DateTimeImmutable();
        }
    }

    public function unlockField(string $field): void
    {
        $this->state->lockedFields = array_values(array_filter(
            $this->state->lockedFields,
            static fn(string $f): bool => $f !== $field,
        ));
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function isFieldLocked(string $field): bool
    {
        return in_array($field, $this->state->lockedFields, true);
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getPublicId(): PublicId
    {
        return $this->state->publicId;
    }

    public function getName(): string
    {
        return $this->state->name;
    }

    public function getCountry(): ?string
    {
        return $this->state->country;
    }

    public function getGender(): ?string
    {
        return $this->state->gender;
    }

    public function getType(): ?string
    {
        return $this->state->type;
    }

    public function getLifeSpanBegin(): ?DateTimeInterface
    {
        return $this->state->lifeSpanBegin;
    }

    public function getLifeSpanEnd(): ?DateTimeInterface
    {
        return $this->state->lifeSpanEnd;
    }

    public function getDisambiguation(): ?string
    {
        return $this->state->disambiguation;
    }

    public function getSortName(): ?string
    {
        return $this->state->sortName;
    }

    public function getBiography(): ?string
    {
        return $this->state->biography;
    }

    public function getMbid(): ?string
    {
        return $this->state->mbid;
    }

    public function getDiscogsId(): ?string
    {
        return $this->state->discogsId;
    }

    public function getSpotifyId(): ?string
    {
        return $this->state->spotifyId;
    }

    /**
     * @return string[]
     */
    public function getLockedFields(): array
    {
        return $this->state->lockedFields;
    }

    public function getCoverImageId(): ?Uuid
    {
        return $this->state->coverImageId;
    }

    public function setCoverImage(?Uuid $id): void
    {
        $this->state->coverImageId = $id;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->state->updatedAt;
    }

    public function getState(): ArtistState
    {
        return $this->state;
    }
}
