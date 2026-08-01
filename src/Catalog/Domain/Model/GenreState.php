<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * Internal state for Genre aggregate root.
 *
 * This class is mutable and should only be used by the aggregate root
 * and its repository implementation.
 */
final class GenreState
{
    public function __construct(
        public readonly Uuid $id,
        public string $name,
        public string $slug,
        public ?string $mbid,
        public ?Uuid $parent = null,
        public readonly DateTimeImmutable $createdAt = new DateTimeImmutable(),
        public DateTimeImmutable $updatedAt = new DateTimeImmutable(),
    ) {
    }
}
