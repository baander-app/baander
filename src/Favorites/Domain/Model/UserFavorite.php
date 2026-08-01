<?php

declare(strict_types=1);

namespace App\Favorites\Domain\Model;

use App\Favorites\Domain\ValueObject\FavoriteType;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class UserFavorite
{
    private function __construct(
        private UserFavoriteState $state,
    ) {
    }

    public static function create(
        Uuid $userId,
        FavoriteType $entityType,
        string $entityPublicId,
    ): self {
        return new self(new UserFavoriteState(
            id: new Uuid(),
            publicId: new PublicId(),
            userId: $userId,
            entityType: $entityType,
            entityPublicId: $entityPublicId,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        ));
    }

    public static function reconstitute(UserFavoriteState $state): self
    {
        return new self($state);
    }

    public function getId(): Uuid { return $this->state->id; }
    public function getPublicId(): PublicId { return $this->state->publicId; }
    public function getUserId(): Uuid { return $this->state->userId; }
    public function getEntityType(): FavoriteType { return $this->state->entityType; }
    public function getEntityPublicId(): string { return $this->state->entityPublicId; }
    public function getCreatedAt(): DateTimeImmutable { return $this->state->createdAt; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->state->updatedAt; }
    public function getState(): UserFavoriteState { return $this->state; }
}
