<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Internal state for Artist aggregate root.
 *
 * This class is mutable and should only be used by the aggregate root
 * and its repository implementation.
 */
final class ArtistState
{
    /**
     * @param string[] $lockedFields
     */
    public function __construct(
        public readonly Uuid $id,
        public readonly PublicId $publicId,
        public string $name,
        public ?string $country,
        public ?string $gender,
        public ?string $type,
        public ?DateTimeInterface $lifeSpanBegin,
        public ?DateTimeInterface $lifeSpanEnd,
        public ?string $disambiguation,
        public ?string $sortName,
        public ?string $biography,
        public ?string $mbid,
        public ?string $discogsId,
        public ?string $spotifyId,
        public ?Uuid $coverImageId = null,
        public array $lockedFields = [],
        public readonly DateTimeImmutable $createdAt = new DateTimeImmutable(),
        public DateTimeImmutable $updatedAt = new DateTimeImmutable(),
    ) {
    }
}
