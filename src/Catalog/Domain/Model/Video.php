<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

final class Video
{
    private function __construct(
        private VideoState $state,
    ) {
    }

    /**
     * Create a new Video aggregate root.
     */
    public static function create(
        string $path,
        string $hash,
        ?int $duration = null,
        ?int $height = null,
        ?int $width = null,
        ?int $videoBitrate = null,
        ?int $framerate = null,
    ): self {
        if (trim($path) === '') {
            throw new InvalidArgumentException('Video path cannot be empty.');
        }

        if (trim($hash) === '') {
            throw new InvalidArgumentException('Video hash cannot be empty.');
        }

        return new self(new VideoState(
            id: new Uuid(),
            publicId: new PublicId(),
            path: $path,
            hash: $hash,
            duration: $duration,
            height: $height,
            width: $width,
            videoBitrate: $videoBitrate,
            framerate: $framerate,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        ));
    }

    /**
     * Reconstitute a Video from persistence.
     *
     * This is intended for use by the repository layer only.
     */
    public static function reconstitute(VideoState $state): self
    {
        return new self($state);
    }

    /**
     * Update video technical metadata.
     */
    public function updateMetadata(
        ?int $duration = null,
        ?int $height = null,
        ?int $width = null,
        ?int $videoBitrate = null,
        ?int $framerate = null,
    ): void {
        $this->state->duration = $duration ?? $this->state->duration;
        $this->state->height = $height ?? $this->state->height;
        $this->state->width = $width ?? $this->state->width;
        $this->state->videoBitrate = $videoBitrate ?? $this->state->videoBitrate;
        $this->state->framerate = $framerate ?? $this->state->framerate;

        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Update the raw probe data from ffprobe or similar tools.
     *
     * @param array<string, mixed> $probe
     */
    public function updateProbe(array $probe): void
    {
        $this->state->probe = $probe;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getPublicId(): PublicId
    {
        return $this->state->publicId;
    }

    public function getPath(): string
    {
        return $this->state->path;
    }

    public function getHash(): string
    {
        return $this->state->hash;
    }

    public function getDuration(): ?int
    {
        return $this->state->duration;
    }

    public function getHeight(): ?int
    {
        return $this->state->height;
    }

    public function getWidth(): ?int
    {
        return $this->state->width;
    }

    public function getVideoBitrate(): ?int
    {
        return $this->state->videoBitrate;
    }

    public function getFramerate(): ?int
    {
        return $this->state->framerate;
    }

    /**
     * @return array<string, mixed>
     */
    public function getProbe(): array
    {
        return $this->state->probe;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->state->updatedAt;
    }

    public function getState(): VideoState
    {
        return $this->state;
    }
}
