<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\Video;

final readonly class VideoSeason
{
    /**
     * @param list<VideoEpisode> $episodes
     */
    public function __construct(
        public int $number,
        public ?string $name = null,
        public ?string $overview = null,
        public ?VideoArtwork $poster = null,
        public array $episodes = [],
    ) {}
}
