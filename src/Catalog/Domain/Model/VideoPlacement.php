<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Catalog\Domain\ValueObject\VideoName\ExtraKind;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;

final class VideoPlacement
{
    private function __construct(private VideoPlacementState $state) {}

    /** @param array<string, mixed> $rawParse */
    public static function create(
        Uuid $libraryId,
        Uuid $videoId,
        string $relativePath,
        array $rawParse,
        ?Uuid $seriesId = null,
        ?Uuid $movieId = null,
        ?ExtraKind $extraKind = null,
    ): self {
        if (($seriesId === null) === ($movieId === null)) {
            throw new InvalidArgumentException('A video placement belongs to exactly one series or movie.');
        }
        self::assertPath($relativePath);

        return new self(new VideoPlacementState(
            id: Uuid::v7(),
            publicId: new PublicId(),
            libraryId: $libraryId,
            videoId: $videoId,
            relativePath: $relativePath,
            rawParse: $rawParse,
            seriesId: $seriesId,
            movieId: $movieId,
            extraKind: $extraKind,
        ));
    }

    public static function reconstitute(VideoPlacementState $state): self
    {
        return new self($state);
    }

    /** @param array<string, mixed> $rawParse */
    public function observe(string $relativePath, array $rawParse): void
    {
        self::assertPath($relativePath);
        $this->state->relativePath = $relativePath;
        $this->state->rawParse = $rawParse;
    }

    /** @param list<Uuid> $episodeIds */
    public function assignEpisodes(array $episodeIds, bool $assigned = false): void
    {
        if ($this->state->seriesId === null) {
            throw new InvalidArgumentException('Only a series placement can play episodes.');
        }
        $unique = [];
        foreach ($episodeIds as $episodeId) {
            $unique[$episodeId->toString()] = $episodeId;
        }
        $this->state->episodeIds = array_values($unique);
        $this->state->extraKind = null;
        $this->state->assigned = $assigned;
    }

    public function assignExtra(ExtraKind $kind, bool $assigned = false): void
    {
        $this->state->extraKind = $kind;
        $this->state->episodeIds = [];
        $this->state->assigned = $assigned;
    }

    public function applyParsedExtraKind(?ExtraKind $kind): void
    {
        if ($this->state->assigned) {
            return;
        }
        $this->state->extraKind = $kind;
        if ($kind !== null) {
            $this->state->episodeIds = [];
        }
    }

    public function copyAssignmentFrom(self $previous): void
    {
        if (!$previous->isAssigned()) {
            return;
        }
        $extra = $previous->getExtraKind();
        if ($extra !== null) {
            $this->assignExtra($extra, assigned: true);
        } elseif ($this->state->seriesId !== null) {
            $this->assignEpisodes($previous->getEpisodeIds(), assigned: true);
        } else {
            $this->state->extraKind = null;
            $this->state->assigned = true;
        }
    }

    public function review(?string $reason): void
    {
        $this->state->reviewReason = $reason;
    }

    private static function assertPath(string $path): void
    {
        if (trim($path) === '' || str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
            throw new InvalidArgumentException('A video placement requires a library-relative path.');
        }
    }

    public function getId(): Uuid { return $this->state->id; }
    public function getPublicId(): PublicId { return $this->state->publicId; }
    public function getLibraryId(): Uuid { return $this->state->libraryId; }
    public function getVideoId(): Uuid { return $this->state->videoId; }
    public function getRelativePath(): string { return $this->state->relativePath; }
    /** @return array<string, mixed> */
    public function getRawParse(): array { return $this->state->rawParse; }
    public function getSeriesId(): ?Uuid { return $this->state->seriesId; }
    public function getMovieId(): ?Uuid { return $this->state->movieId; }
    public function getExtraKind(): ?ExtraKind { return $this->state->extraKind; }
    /** @return list<Uuid> */
    public function getEpisodeIds(): array { return $this->state->episodeIds; }
    public function isAssigned(): bool { return $this->state->assigned; }
    public function getReviewReason(): ?string { return $this->state->reviewReason; }
    public function getState(): VideoPlacementState { return $this->state; }
}
