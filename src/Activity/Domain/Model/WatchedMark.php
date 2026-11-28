<?php

declare(strict_types=1);

namespace App\Activity\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

final class WatchedMark
{
    private function __construct(private WatchedMarkState $state)
    {
        if (($state->episodeId === null) === ($state->movieId === null)) {
            throw new InvalidArgumentException('A watched mark must target exactly one episode or movie.');
        }
    }

    public static function episode(Uuid $userId, Uuid $episodeId, ?DateTimeImmutable $watchedAt = null): self
    {
        return new self(new WatchedMarkState(Uuid::v7(), $userId, $episodeId, null, $watchedAt ?? new DateTimeImmutable()));
    }

    public static function movie(Uuid $userId, Uuid $movieId, ?DateTimeImmutable $watchedAt = null): self
    {
        return new self(new WatchedMarkState(Uuid::v7(), $userId, null, $movieId, $watchedAt ?? new DateTimeImmutable()));
    }

    public static function reconstitute(WatchedMarkState $state): self
    {
        return new self($state);
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getUserId(): Uuid
    {
        return $this->state->userId;
    }

    public function getEpisodeId(): ?Uuid
    {
        return $this->state->episodeId;
    }

    public function getMovieId(): ?Uuid
    {
        return $this->state->movieId;
    }

    public function getWatchedAt(): DateTimeImmutable
    {
        return $this->state->watchedAt;
    }

}
