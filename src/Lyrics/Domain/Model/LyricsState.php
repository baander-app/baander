<?php

declare(strict_types=1);

namespace App\Lyrics\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * Internal state for Lyrics aggregate root.
 *
 * This class is mutable and should only be used by the aggregate root
 * and its repository implementation.
 */
final class LyricsState
{
    public function __construct(
        public readonly Uuid $id,
        public readonly Uuid $songId,
        public string $lyrics,
        public ?string $syncedLyrics,
        public string $source,
        public ?string $sourceUrl,
        public ?int $lrclibId,
        public bool $isInstrumental,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
