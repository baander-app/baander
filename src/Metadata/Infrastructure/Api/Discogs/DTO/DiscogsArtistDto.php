<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\Discogs\DTO;

final readonly class DiscogsArtistDto
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $profile = null,
        public ?string $imageUrl = null,
        public int $releaseCount = 0,
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
            'name' => $this->name,
            'profile' => $this->profile,
            'imageUrl' => $this->imageUrl,
            'releaseCount' => $this->releaseCount,
            'resourceUrl' => $this->resourceUrl,
            'score' => $this->score,
        ];
    }
}
