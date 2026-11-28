<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\VideoMatch;

final readonly class VideoMatchLibraryPreview
{
    public function __construct(
        public int $totalTitles,
        public int $handMatchedTitles,
        public int $handAssignedFiles,
        public string $libraryPublicId,
    ) {}
}
