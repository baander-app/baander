<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

final class Album
{
    /** @var string[] */
    private const LOCKABLE_FIELDS = [
        'title', 'type', 'year', 'label', 'catalogNumber', 'barcode',
        'country', 'language', 'disambiguation', 'annotation',
    ];

    private function __construct(
        private AlbumState $state,
    ) {
    }

    /**
     * Create a new Album aggregate root.
     */
    public static function create(
        Uuid $libraryId,
        string $title,
        string $type,
        ?string $mbid = null,
        ?string $discogsId = null,
        ?string $spotifyId = null,
        ?int $year = null,
        ?string $label = null,
        ?string $catalogNumber = null,
        ?string $barcode = null,
        ?string $country = null,
        ?string $language = null,
        ?string $disambiguation = null,
        ?string $annotation = null,
    ): self {
        if (trim($title) === '') {
            throw new InvalidArgumentException('Album title cannot be empty.');
        }

        if (trim($type) === '') {
            throw new InvalidArgumentException('Album type cannot be empty.');
        }

        return new self(new AlbumState(
            id: new Uuid(),
            publicId: new PublicId(),
            libraryId: $libraryId,
            title: $title,
            type: $type,
            mbid: $mbid,
            discogsId: $discogsId,
            spotifyId: $spotifyId,
            year: $year,
            label: $label,
            catalogNumber: $catalogNumber,
            barcode: $barcode,
            country: $country,
            language: $language,
            disambiguation: $disambiguation,
            annotation: $annotation,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        ));
    }

    /**
     * Reconstitute an Album from persistence.
     *
     * This is intended for use by the repository layer only.
     */
    public static function reconstitute(AlbumState $state): self
    {
        return new self($state);
    }

    /**
     * Update album metadata fields.
     */
    public function updateMetadata(
        ?string $title = null,
        ?string $type = null,
        ?int $year = null,
        ?string $label = null,
        ?string $catalogNumber = null,
        ?string $barcode = null,
        ?string $country = null,
        ?string $language = null,
        ?string $disambiguation = null,
        ?string $annotation = null,
    ): void {
        if ($title !== null && $this->isFieldLocked('title')) {
            throw new InvalidArgumentException('Field "title" is locked and cannot be updated.');
        }

        if ($type !== null && $this->isFieldLocked('type')) {
            throw new InvalidArgumentException('Field "type" is locked and cannot be updated.');
        }

        if ($title !== null) {
            if (trim($title) === '') {
                throw new InvalidArgumentException('Album title cannot be empty.');
            }
            $this->state->title = trim($title);
        }

        if ($type !== null) {
            if (trim($type) === '') {
                throw new InvalidArgumentException('Album type cannot be empty.');
            }
            $this->state->type = trim($type);
        }

        $this->state->year = $year ?? $this->state->year;
        $this->state->label = $label ?? $this->state->label;
        $this->state->catalogNumber = $catalogNumber ?? $this->state->catalogNumber;
        $this->state->barcode = $barcode ?? $this->state->barcode;
        $this->state->country = $country ?? $this->state->country;
        $this->state->language = $language ?? $this->state->language;
        $this->state->disambiguation = $disambiguation ?? $this->state->disambiguation;
        $this->state->annotation = $annotation ?? $this->state->annotation;

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

    public function getLibraryId(): Uuid
    {
        return $this->state->libraryId;
    }

    public function getTitle(): string
    {
        return $this->state->title;
    }

    public function getType(): string
    {
        return $this->state->type;
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

    public function getYear(): ?int
    {
        return $this->state->year;
    }

    public function getLabel(): ?string
    {
        return $this->state->label;
    }

    public function getCatalogNumber(): ?string
    {
        return $this->state->catalogNumber;
    }

    public function getBarcode(): ?string
    {
        return $this->state->barcode;
    }

    public function getCountry(): ?string
    {
        return $this->state->country;
    }

    public function getLanguage(): ?string
    {
        return $this->state->language;
    }

    public function getDisambiguation(): ?string
    {
        return $this->state->disambiguation;
    }

    public function getAnnotation(): ?string
    {
        return $this->state->annotation;
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

    /**
     * @return string[]
     */
    public function getLockedFields(): array
    {
        return $this->state->lockedFields;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->state->updatedAt;
    }

    public function getState(): AlbumState
    {
        return $this->state;
    }

    /**
     * @return array<int, array{id: string, title: string, mergedAt: string}>
     */
    public function getMergedFrom(): array
    {
        return $this->state->mergedFrom;
    }

    /**
     * Add a merge record to this album's audit trail.
     */
    public function addMergeRecord(Uuid $sourceAlbumId, string $sourceTitle): void
    {
        $this->state->mergedFrom[] = [
            'id' => $sourceAlbumId->toString(),
            'title' => $sourceTitle,
            'mergedAt' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
        ];
        $this->state->updatedAt = new DateTimeImmutable();
    }
}
