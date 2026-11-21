<?php

declare(strict_types=1);

namespace App\Catalog\Domain\ReadModel;

use App\Shared\Domain\Model\PublicId;

final readonly class DirectorReadView
{
    public function __construct(
        public PublicId $publicId,
        public string $name,
        public int $tmdbId,
        public ?string $profileUrl,
        public int $movieCount,
    ) {}
}
