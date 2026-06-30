<?php

declare(strict_types=1);

namespace App\Library\Application\Command;

use App\Filesystem\Domain\ValueObject\FilesystemType;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;

final readonly class CreateLibraryCommand
{
    public function __construct(
        private string $name,
        private LibrarySlug $slug,
        private LibraryPath $path,
        private LibraryType $type,
        private FilesystemType $filesystemType,
        private int $sortOrder = 0,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): LibrarySlug
    {
        return $this->slug;
    }

    public function getPath(): LibraryPath
    {
        return $this->path;
    }

    public function getType(): LibraryType
    {
        return $this->type;
    }

    public function getFilesystemType(): FilesystemType
    {
        return $this->filesystemType;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }
}
