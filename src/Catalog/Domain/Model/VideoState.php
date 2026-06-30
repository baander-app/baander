<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * Internal state for Video aggregate root.
 *
 * This class is mutable and should only be used by the aggregate root
 * and its repository implementation.
 */
final class VideoState
{
    /**
     * @param array<string, mixed> $probe
     */
    public function __construct(
        public readonly Uuid $id,
        public readonly PublicId $publicId,
        public string $path,
        public string $hash,
        public ?int $duration,
        public ?int $height,
        public ?int $width,
        public ?int $videoBitrate,
        public ?int $framerate,
        public array $probe = [],
        public readonly DateTimeImmutable $createdAt = new DateTimeImmutable(),
        public DateTimeImmutable $updatedAt = new DateTimeImmutable(),
    ) {
    }
}
