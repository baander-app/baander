<?php

declare(strict_types=1);

namespace App\Catalog\Domain\ValueObject;

use App\Shared\Domain\Model\Uuid;

/**
 * Represents a group of potentially duplicate albums.
 */
final readonly class DuplicateGroup
{
    /**
     * @param Uuid[] $albumIds
     * @param array<int, array> $albums
     */
    public function __construct(
        public array $albumIds,
        public float $confidence,
        public array $albums = [],
    ) {
        if (count($albumIds) < 2) {
            throw new \InvalidArgumentException('Duplicate group must contain at least 2 albums.');
        }
    }

    /**
     * @return Uuid[]
     */
    public function getAlbumIds(): array
    {
        return $this->albumIds;
    }

    public function getConfidence(): float
    {
        return $this->confidence;
    }

    public function getAlbumCount(): int
    {
        return count($this->albumIds);
    }

    /**
     * @return array<int, array>
     */
    public function getAlbums(): array
    {
        return $this->albums;
    }
}
