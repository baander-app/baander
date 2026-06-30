<?php

declare(strict_types=1);

namespace App\Lyrics\Application\DTO;

/**
 * Lightweight search result item from the LRCLIB search API.
 *
 * Used for displaying search results to users before they pick one
 * to apply to a song.
 */
final readonly class LrclibSearchResult
{
    public function __construct(
        public int $id,
        public string $trackName,
        public string $artistName,
        public string $albumName,
        public ?float $duration,
        public bool $instrumental,
        public ?string $plainLyrics,
        public ?string $syncedLyrics,
    ) {
    }

    /**
     * Create from LRCLIB search API JSON response item.
     *
     * @param array<string, mixed> $data
     */
    public static function fromApiResponse(array $data): self
    {
        return new self(
            id: (int) ($data['id'] ?? 0),
            trackName: (string) ($data['trackName'] ?? ''),
            artistName: (string) ($data['artistName'] ?? ''),
            albumName: (string) ($data['albumName'] ?? ''),
            duration: isset($data['duration']) ? (float) $data['duration'] : null,
            instrumental: (bool) ($data['instrumental'] ?? false),
            plainLyrics: $data['plainLyrics'] ?? null,
            syncedLyrics: $data['syncedLyrics'] ?? null,
        );
    }
}
