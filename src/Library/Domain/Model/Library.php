<?php

declare(strict_types=1);

namespace App\Library\Domain\Model;

use App\Shared\Domain\ValueObject\FilesystemType;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A media library. Its discovery status and last scan time are read-only here: the scan claim
 * (LibraryRepositoryInterface::claimScan() and the methods after it) sets them, so that
 * concurrent scans and saves cannot overwrite each other's status.
 */
final class Library
{
    private function __construct(
        private LibraryState $state,
    ) {
    }

    public static function create(
        string $name,
        LibrarySlug $slug,
        LibraryPath $path,
        LibraryType $type,
        FilesystemType $filesystemType,
        int $sortOrder = 0,
    ): self {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Library name cannot be empty.');
        }

        return new self(new LibraryState(
            id: new Uuid(),
            name: $name,
            slug: $slug,
            path: $path,
            type: $type,
            filesystemType: $filesystemType,
            sortOrder: $sortOrder,
        ));
    }

    public static function reconstitute(LibraryState $state): self
    {
        return new self($state);
    }

    public function updateMetadata(
        ?string $name = null,
        ?int $sortOrder = null,
    ): void {
        if ($name !== null) {
            if (trim($name) === '') {
                throw new InvalidArgumentException('Library name cannot be empty.');
            }
            $this->state->name = $name;
        }

        if ($sortOrder !== null) {
            $this->state->sortOrder = $sortOrder;
        }

        $this->state->updatedAt = new DateTimeImmutable();
    }

    // --- Getters ---

    public function getId(): Uuid { return $this->state->id; }
    public function getName(): string { return $this->state->name; }
    public function getSlug(): LibrarySlug { return $this->state->slug; }
    public function getPath(): LibraryPath { return $this->state->path; }
    public function getType(): LibraryType { return $this->state->type; }
    public function getFilesystemType(): FilesystemType { return $this->state->filesystemType; }
    public function getSortOrder(): int { return $this->state->sortOrder; }
    public function getLastScan(): ?DateTimeImmutable { return $this->state->lastScan; }
    public function getDiscoveryStatus(): ?string { return $this->state->discoveryStatus; }
    public function getCreatedAt(): DateTimeImmutable { return $this->state->createdAt; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->state->updatedAt; }

    public function getState(): LibraryState { return $this->state; }
}
