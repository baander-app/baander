<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\Discogs\DTO;

/**
 * Aggregates typed search results returned from the Discogs database endpoint.
 *
 * Each search call populates only one of the three typed arrays based on
 * the requested `type` parameter; the others remain empty.
 */
final readonly class DiscogsSearchResultDto
{
    /**
     * @param DiscogsArtistDto[] $artists
     * @param DiscogsReleaseDto[] $releases
     * @param DiscogsMasterDto[] $masters
     */
    public function __construct(
        public array $artists = [],
        public array $releases = [],
        public array $masters = [],
        public int $total = 0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'artists' => array_map(fn(DiscogsArtistDto $a) => $a->toArray(), $this->artists),
            'releases' => array_map(fn(DiscogsReleaseDto $r) => $r->toArray(), $this->releases),
            'masters' => array_map(fn(DiscogsMasterDto $m) => $m->toArray(), $this->masters),
            'total' => $this->total,
        ];
    }
}
