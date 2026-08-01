<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

final class Movie
{
    private function __construct(
        private MovieState $state,
    ) {
    }

    public static function create(
        Uuid $libraryId,
        string $title,
        ?int $year = null,
        ?string $summary = null,
    ): self {
        if (trim($title) === '') {
            throw new InvalidArgumentException('Movie title cannot be empty.');
        }

        return new self(new MovieState(
            id: new Uuid(),
            publicId: new PublicId(),
            libraryId: $libraryId,
            title: $title,
            year: $year,
            summary: $summary,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        ));
    }

    public static function reconstitute(MovieState $state): self
    {
        return new self($state);
    }

    public function updateMetadata(
        ?string $title = null,
        ?int $year = null,
        ?string $summary = null,
        ?string $overview = null,
        ?string $tagline = null,
        ?string $posterUrl = null,
        ?string $backdropUrl = null,
        ?int $runtime = null,
        ?float $rating = null,
        ?string $originalLanguage = null,
        ?int $tmdbCollectionId = null,
        ?string $collectionName = null,
    ): void {
        if ($title !== null) {
            if (trim($title) === '') {
                throw new InvalidArgumentException('Movie title cannot be empty.');
            }
            $this->state->title = $title;
        }

        $this->state->year = $year ?? $this->state->year;
        $this->state->summary = $summary ?? $this->state->summary;
        $this->state->overview = $overview ?? $this->state->overview;
        $this->state->tagline = $tagline ?? $this->state->tagline;
        $this->state->posterUrl = $posterUrl ?? $this->state->posterUrl;
        $this->state->backdropUrl = $backdropUrl ?? $this->state->backdropUrl;
        $this->state->runtime = $runtime ?? $this->state->runtime;
        $this->state->rating = $rating ?? $this->state->rating;
        $this->state->originalLanguage = $originalLanguage ?? $this->state->originalLanguage;
        $this->state->tmdbCollectionId = $tmdbCollectionId ?? $this->state->tmdbCollectionId;
        $this->state->collectionName = $collectionName ?? $this->state->collectionName;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function updateExternalIds(
        ?int $tmdbId = null,
        ?string $imdbId = null,
    ): void {
        $this->state->tmdbId = $tmdbId ?? $this->state->tmdbId;
        $this->state->imdbId = $imdbId ?? $this->state->imdbId;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function addVideo(Uuid $videoId): void
    {
        $idStr = $videoId->toString();
        if (!in_array($idStr, $this->state->videoIds, true)) {
            $this->state->videoIds[] = $idStr;
            $this->state->updatedAt = new DateTimeImmutable();
        }
    }

    public function removeVideo(Uuid $videoId): void
    {
        $idStr = $videoId->toString();
        $this->state->videoIds = array_values(array_filter(
            $this->state->videoIds,
            static fn (string $id): bool => $id !== $idStr,
        ));
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->state->id; }
    public function getPublicId(): PublicId { return $this->state->publicId; }
    public function getLibraryId(): Uuid { return $this->state->libraryId; }
    public function getTitle(): string { return $this->state->title; }
    public function getYear(): ?int { return $this->state->year; }
    public function getSummary(): ?string { return $this->state->summary; }
    public function getOverview(): ?string { return $this->state->overview; }
    public function getTagline(): ?string { return $this->state->tagline; }
    public function getPosterUrl(): ?string { return $this->state->posterUrl; }
    public function getBackdropUrl(): ?string { return $this->state->backdropUrl; }
    public function getRuntime(): ?int { return $this->state->runtime; }
    public function getRating(): ?float { return $this->state->rating; }
    public function getOriginalLanguage(): ?string { return $this->state->originalLanguage; }
    public function getTmdbId(): ?int { return $this->state->tmdbId; }
    public function getImdbId(): ?string { return $this->state->imdbId; }
    public function getTmdbCollectionId(): ?int { return $this->state->tmdbCollectionId; }
    public function getCollectionName(): ?string { return $this->state->collectionName; }
    /** @return string[] */
    public function getVideoIds(): array { return $this->state->videoIds; }
    public function getCreatedAt(): DateTimeImmutable { return $this->state->createdAt; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->state->updatedAt; }
    public function getState(): MovieState { return $this->state; }
}
