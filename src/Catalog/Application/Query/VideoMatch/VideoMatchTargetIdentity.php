<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\VideoMatch;

use App\Metadata\Application\DTO\Video\VideoKind;
use App\Shared\Domain\Model\Uuid;

final readonly class VideoMatchTargetIdentity
{
    public function __construct(
        public Uuid $id,
        public VideoKind $kind,
        public string $publicId,
        public Uuid $libraryId,
        public bool $handMatched,
        public ?string $matchedProvider,
        public ?string $providerId,
        public ?string $ordering,
    ) {}

    public static function fromStorage(
        Uuid $id,
        string $kind,
        string $publicId,
        Uuid $libraryId,
        bool $handMatched,
        ?string $matchedProvider,
        ?string $providerId,
        ?string $ordering,
    ): self {
        return new self(
            id: $id,
            kind: VideoKind::from($kind),
            publicId: $publicId,
            libraryId: $libraryId,
            handMatched: $handMatched,
            matchedProvider: $matchedProvider,
            providerId: $providerId,
            ordering: $ordering,
        );
    }

    public function storageKind(): string
    {
        return $this->kind->value;
    }
}
