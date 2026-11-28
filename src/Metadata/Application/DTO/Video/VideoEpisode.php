<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\Video;

final readonly class VideoEpisode
{
    /**
     * @param array<string, string> $externalIds
     * @param list<VideoCredit>|null $cast
     * @param list<VideoCredit>|null $crew
     */
    public function __construct(
        public string $id,
        public int $number,
        public string $title,
        public ?int $absoluteNumber = null,
        public ?string $airDate = null,
        public array $externalIds = [],
        public ?string $overview = null,
        public ?VideoArtwork $still = null,
        public ?array $cast = null,
        public ?array $crew = null,
    ) {}
}
