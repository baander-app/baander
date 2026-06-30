<?php

declare(strict_types=1);

namespace App\Library\Application\Message;

use App\Library\Domain\Model\DiscoveredFile;
use App\Shared\Domain\Model\Uuid;

/**
 * Messenger message emitted by Library when files are discovered in a directory.
 * Catalog consumes this to orchestrate metadata reading and entity creation.
 */
final readonly class FilesDiscovered
{
    /**
     * @param array<DiscoveredFile> $files
     */
    public function __construct(
        public readonly Uuid $libraryId,
        public readonly string $libraryType,
        public readonly string $directory,
        public readonly array $files,
    ) {
    }
}
