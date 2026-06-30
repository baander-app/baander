<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'library_file_index')]
#[ORM\UniqueConstraint(name: 'library_file_path_unique', columns: ['library_id', 'path'])]
class LibraryFileIndexEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        #[ORM\GeneratedValue(strategy: 'NONE')]
        private Uuid $id,

        #[ORM\Column(type: 'uuid')]
        private Uuid $libraryId,

        #[ORM\Column(type: 'text')]
        private string $path,

        #[ORM\Column(type: 'text')]
        private string $hash,

        #[ORM\Column(type: 'integer')]
        private int $size,

        #[ORM\Column(type: 'text')]
        private string $extension,

        #[ORM\Column(type: 'integer')]
        private int $modifiedAt,

        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $discoveredAt,
    ) {
    }

    public function getId(): Uuid { return $this->id; }
    public function getLibraryId(): Uuid { return $this->libraryId; }
    public function getPath(): string { return $this->path; }
    public function getHash(): string { return $this->hash; }
    public function getSize(): int { return $this->size; }
    public function getExtension(): string { return $this->extension; }
    public function getModifiedAt(): int { return $this->modifiedAt; }
    public function getDiscoveredAt(): \DateTimeImmutable { return $this->discoveredAt; }

    public function setHash(string $hash): void { $this->hash = $hash; }
    public function setSize(int $size): void { $this->size = $size; }
    public function setModifiedAt(int $modifiedAt): void { $this->modifiedAt = $modifiedAt; }
    public function setDiscoveredAt(\DateTimeImmutable $discoveredAt): void { $this->discoveredAt = $discoveredAt; }
}
