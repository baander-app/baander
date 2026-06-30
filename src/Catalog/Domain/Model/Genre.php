<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

final class Genre
{
    private const MBID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private function __construct(
        private GenreState $state,
    ) {
    }

    /**
     * Create a new Genre aggregate root.
     */
    public static function create(
        string $name,
        string $slug,
        ?Uuid $parent = null,
        ?string $mbid = null,
    ): self {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Genre name cannot be empty.');
        }

        $normalizedName = trim($name);

        if (!self::isValidSlug($slug)) {
            throw new InvalidArgumentException(sprintf(
                'Genre slug "%s" is invalid. Slugs must contain only lowercase letters, numbers, and hyphens.',
                $slug,
            ));
        }

        if ($mbid !== null && !self::isValidMbid($mbid)) {
            throw new InvalidArgumentException(sprintf('Invalid MusicBrainz ID format: "%s".', $mbid));
        }

        return new self(new GenreState(
            id: new Uuid(),
            name: $normalizedName,
            slug: $slug,
            mbid: $mbid,
            parent: $parent,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        ));
    }

    /**
     * Reconstitute a Genre from persistence.
     *
     * This is intended for use by the repository layer only.
     */
    public static function reconstitute(GenreState $state): self
    {
        return new self($state);
    }

    /**
     * Update the genre name and slug.
     */
    public function update(string $name, string $slug): void
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Genre name cannot be empty.');
        }

        if (!self::isValidSlug($slug)) {
            throw new InvalidArgumentException(sprintf(
                'Genre slug "%s" is invalid. Slugs must contain only lowercase letters, numbers, and hyphens.',
                $slug,
            ));
        }

        $this->state->name = trim($name);
        $this->state->slug = $slug;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Set the parent genre for hierarchy.
     */
    public function setParent(?Genre $genre): void
    {
        if ($genre !== null && $genre->getId()->equals($this->state->id)) {
            throw new InvalidArgumentException('A genre cannot be its own parent.');
        }

        $this->state->parent = $genre?->getId();
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Set the parent genre by its Uuid directly (for reconstitution / repository use).
     */
    public function setParentId(?Uuid $parent): void
    {
        if ($parent !== null && $parent->equals($this->state->id)) {
            throw new InvalidArgumentException('A genre cannot be its own parent.');
        }

        $this->state->parent = $parent;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function updateMbid(?string $mbid): void
    {
        if ($mbid !== null && !self::isValidMbid($mbid)) {
            throw new InvalidArgumentException(sprintf('Invalid MusicBrainz ID format: "%s".', $mbid));
        }
        $this->state->mbid = $mbid;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getName(): string
    {
        return $this->state->name;
    }

    public function getSlug(): string
    {
        return $this->state->slug;
    }

    public function getMbid(): ?string
    {
        return $this->state->mbid;
    }

    public function getParent(): ?Uuid
    {
        return $this->state->parent;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->state->updatedAt;
    }

    public function getState(): GenreState
    {
        return $this->state;
    }

    /**
     * Validate that a slug contains only lowercase letters, numbers, and hyphens.
     */
    private static function isValidSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug);
    }

    private static function isValidMbid(string $mbid): bool
    {
        return (bool) preg_match(self::MBID_PATTERN, $mbid);
    }
}
