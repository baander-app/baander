<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\Video;

final readonly class VideoTitle
{
    /**
     * @param list<VideoOrdering> $orderings
     * @param list<VideoSeason> $seasons in the requested ordering, empty when that ordering is missing
     * @param list<VideoCredit>|null $cast null when the provider omitted this credit list
     * @param list<VideoCredit>|null $crew
     * @param list<string> $genres
     */
    public function __construct(
        public VideoCandidate $entry,
        public ?string $overview = null,
        public ?VideoArtwork $poster = null,
        public ?VideoArtwork $backdrop = null,
        public array $orderings = [],
        public array $seasons = [],
        public ?array $cast = null,
        public ?array $crew = null,
        public ?string $tagline = null,
        public ?int $runtime = null,
        public ?float $rating = null,
        public ?string $originalLanguage = null,
        public ?int $collectionId = null,
        public ?string $collectionName = null,
        public array $genres = [],
    ) {}

    public function hasOrdering(string $id): bool
    {
        return array_any($this->orderings, static fn (VideoOrdering $ordering): bool => $ordering->id === $id);
    }
}
