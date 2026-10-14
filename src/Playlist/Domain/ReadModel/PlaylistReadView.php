<?php

declare(strict_types=1);

namespace App\Playlist\Domain\ReadModel;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * A playlist as a viewer reads it: the owner and the collaborators by public id, and the
 * number of songs the viewer can see.
 */
final readonly class PlaylistReadView
{
    /**
     * @param list<PublicId> $collaboratorPublicIds
     * @param array<array-key, mixed> $smartRules
     */
    public function __construct(
        private Uuid $id,
        private PublicId $publicId,
        private PublicId $ownerPublicId,
        private array $collaboratorPublicIds,
        private string $name,
        private ?string $description,
        private bool $isPublic,
        private bool $isCollaborative,
        private bool $isSmart,
        private array $smartRules,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
        private int $songCount,
    ) {
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPublicId(): PublicId
    {
        return $this->publicId;
    }

    public function getOwnerPublicId(): PublicId
    {
        return $this->ownerPublicId;
    }

    /** @return list<PublicId> */
    public function getCollaboratorPublicIds(): array
    {
        return $this->collaboratorPublicIds;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function isCollaborative(): bool
    {
        return $this->isCollaborative;
    }

    public function isSmart(): bool
    {
        return $this->isSmart;
    }

    /** @return array<array-key, mixed> */
    public function getSmartRules(): array
    {
        return $this->smartRules;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getSongCount(): int
    {
        return $this->songCount;
    }
}
