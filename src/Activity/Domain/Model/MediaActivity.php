<?php

declare(strict_types=1);

namespace App\Activity\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

final class MediaActivity
{
    private function __construct(
        private readonly Uuid $id,
        private readonly PublicId $publicId,
        private readonly Uuid $userId,
        private string $activityType,
        private ?Uuid $songId,
        private ?Uuid $albumId,
        private ?Uuid $artistId,
        private ?Uuid $movieId,
        private int $playCount,
        private bool $love,
        private ?DateTimeImmutable $lastPlayedAt,
        private ?string $lastPlatform,
        private ?string $lastPlayer,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Create a new MediaActivity aggregate root.
     */
    public static function create(
        Uuid $userId,
        string $activityType,
        ?Uuid $songId = null,
        ?Uuid $albumId = null,
        ?Uuid $artistId = null,
        ?Uuid $movieId = null,
    ): self {
        $validTypes = ['play', 'love', 'skip'];

        if (!in_array($activityType, $validTypes, true)) {
            throw new InvalidArgumentException(
                sprintf('Invalid activity type "%s". Must be one of: %s', $activityType, implode(', ', $validTypes)),
            );
        }

        return new self(
            new Uuid(),
            new PublicId(),
            $userId,
            $activityType,
            $songId,
            $albumId,
            $artistId,
            $movieId,
            0,
            false,
            null,
            null,
            null,
            new DateTimeImmutable(),
            new DateTimeImmutable(),
        );
    }

    /**
     * Reconstitute a MediaActivity from persistence.
     *
     * This is intended for use by the repository layer only.
     */
    public static function reconstitute(
        Uuid $id,
        PublicId $publicId,
        Uuid $userId,
        string $activityType,
        ?Uuid $songId,
        ?Uuid $albumId,
        ?Uuid $artistId,
        ?Uuid $movieId,
        int $playCount,
        bool $love,
        ?DateTimeImmutable $lastPlayedAt,
        ?string $lastPlatform,
        ?string $lastPlayer,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $id,
            $publicId,
            $userId,
            $activityType,
            $songId,
            $albumId,
            $artistId,
            $movieId,
            $playCount,
            $love,
            $lastPlayedAt,
            $lastPlatform,
            $lastPlayer,
            $createdAt,
            $updatedAt,
        );
    }

    /**
     * Record a play event — increments play count and sets play metadata.
     */
    public function recordPlay(?string $platform = null, ?string $player = null): void
    {
        $this->playCount++;
        $this->lastPlayedAt = new DateTimeImmutable();
        $this->lastPlatform = $platform;
        $this->lastPlayer = $player;
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Toggle the love flag.
     */
    public function toggleLove(): void
    {
        $this->love = !$this->love;
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Set the love flag to an explicit value.
     */
    public function setLove(bool $love): void
    {
        $this->love = $love;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPublicId(): PublicId
    {
        return $this->publicId;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getActivityType(): string
    {
        return $this->activityType;
    }

    public function getSongId(): ?Uuid
    {
        return $this->songId;
    }

    public function getAlbumId(): ?Uuid
    {
        return $this->albumId;
    }

    public function getArtistId(): ?Uuid
    {
        return $this->artistId;
    }

    public function getMovieId(): ?Uuid
    {
        return $this->movieId;
    }

    public function getPlayCount(): int
    {
        return $this->playCount;
    }

    public function isLove(): bool
    {
        return $this->love;
    }

    public function getLastPlayedAt(): ?DateTimeImmutable
    {
        return $this->lastPlayedAt;
    }

    public function getLastPlatform(): ?string
    {
        return $this->lastPlatform;
    }

    public function getLastPlayer(): ?string
    {
        return $this->lastPlayer;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
