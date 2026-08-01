<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * Internal state for Movie aggregate root.
 *
 * This class is mutable and should only be used by the aggregate root
 * and its repository implementation.
 */
final class MovieState
{
    /**
     * @param string[] $videoIds
     */
    public function __construct(
        public readonly Uuid $id,
        public readonly PublicId $publicId,
        public readonly Uuid $libraryId,
        public string $title,
        public ?int $year,
        public ?string $summary,
        public ?int $tmdbId = null,
        public ?string $imdbId = null,
        public ?string $overview = null,
        public ?string $tagline = null,
        public ?string $posterUrl = null,
        public ?string $backdropUrl = null,
        public ?int $runtime = null,
        public ?float $rating = null,
        public ?string $originalLanguage = null,
        public ?int $tmdbCollectionId = null,
        public ?string $collectionName = null,
        public array $videoIds = [],
        public readonly DateTimeImmutable $createdAt = new DateTimeImmutable(),
        public DateTimeImmutable $updatedAt = new DateTimeImmutable(),
    ) {
    }
}
