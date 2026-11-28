<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\VideoMatch;

use DateTimeImmutable;

final readonly class VideoMatchReview
{
    public function __construct(
        public string $kind,
        public string $publicId,
        public string $title,
        public ?string $path,
        public string $libraryPublicId,
        public string $libraryName,
        public string $reason,
        public DateTimeImmutable $checkedAt,
        public ?int $bestScore,
        public string $ownerKind,
        public string $ownerPublicId,
    ) {}
}
