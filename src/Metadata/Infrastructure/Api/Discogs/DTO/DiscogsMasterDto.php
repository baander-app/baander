<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\Discogs\DTO;

final readonly class DiscogsMasterDto
{
    /**
     * @param string[] $genres
     * @param string[] $styles
     */
    public function __construct(
        public int $id,
        public string $title,
        public ?int $year = null,
        public string $artist = '',
        public array $genres = [],
        public array $styles = [],
        public ?string $thumb = null,
        public ?string $coverImage = null,
        public ?int $mainReleaseId = null,
        public string $resourceUrl = '',
        public int $score = 0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'year' => $this->year,
            'artist' => $this->artist,
            'genres' => $this->genres,
            'styles' => $this->styles,
            'thumb' => $this->thumb,
            'coverImage' => $this->coverImage,
            'mainReleaseId' => $this->mainReleaseId,
            'resourceUrl' => $this->resourceUrl,
            'score' => $this->score,
        ];
    }
}
