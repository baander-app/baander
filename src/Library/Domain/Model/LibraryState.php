<?php

declare(strict_types=1);

namespace App\Library\Domain\Model;

use App\Filesystem\Domain\ValueObject\FilesystemType;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * Internal state for Library aggregate root.
 *
 * This class is mutable and should only be used by the aggregate root
 * and its repository implementation.
 */
final class LibraryState
{
    public function __construct(
        public readonly Uuid $id,
        public string $name,
        public readonly LibrarySlug $slug,
        public readonly LibraryPath $path,
        public readonly LibraryType $type,
        public readonly FilesystemType $filesystemType,
        public int $sortOrder,
        public ?DateTimeImmutable $lastScan = null,
        public ?string $discoveryStatus = null,
        public readonly DateTimeImmutable $createdAt = new DateTimeImmutable(),
        public DateTimeImmutable $updatedAt = new DateTimeImmutable(),
    ) {
    }
}
