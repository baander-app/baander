<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * Internal state for Song aggregate root.
 *
 * This class is mutable and should only be used by the aggregate root
 * and its repository implementation.
 */
final class SongState
{
    /**
     * @param string[] $lockedFields
     */
    public function __construct(
        public readonly Uuid $id,
        public readonly PublicId $publicId,
        public readonly Uuid $album,
        public string $title,
        public string $path,
        public int $size,
        public string $mimeType,
        public ?float $length,
        public ?string $lyrics,
        public ?int $track,
        public ?int $disc,
        public ?int $year,
        public ?string $comment,
        public ?string $hash,
        public ?int $bitrate,
        public ?int $sampleRate,
        public ?int $channels,
        public ?string $codec,
        public bool $explicit,
        public ?float $energy = null,
        public ?float $danceability = null,
        public ?float $valence = null,
        public ?float $acousticness = null,
        public ?float $instrumentalness = null,
        public ?float $liveness = null,
        public ?float $spechiness = null,
        public ?float $loudness = null,
        public ?string $mbid = null,
        public ?string $discogsId = null,
        public ?string $spotifyId = null,
        public array $lockedFields = [],
        public readonly ?PublicId $albumPublicId = null,
        public readonly DateTimeImmutable $createdAt = new DateTimeImmutable(),
        public DateTimeImmutable $updatedAt = new DateTimeImmutable(),
    ) {
    }
}
