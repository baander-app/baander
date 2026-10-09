<?php

declare(strict_types=1);

namespace App\Lyrics\Application\DTO;

use App\Lyrics\Domain\Model\Lyrics;
use App\Shared\Domain\Model\Uuid;

/**
 * Value object representing a lyrics result from the LRCLIB API.
 *
 * Maps the LRCLIB JSON response fields into a typed, immutable DTO
 * so the rest of the application never depends on external data structures.
 */
final readonly class LrclibResult
{
    public function __construct(
        public int $id,
        public string $trackName,
        public string $artistName,
        public string $albumName,
        public float $duration,
        public bool $instrumental,
        public ?string $plainLyrics,
        public ?string $syncedLyrics,
    ) {
    }

    /**
     * Create from LRCLIB API JSON response.
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
            duration: (float) ($data['duration'] ?? 0.0),
            instrumental: (bool) ($data['instrumental'] ?? false),
            plainLyrics: $data['plainLyrics'] ?? null,
            syncedLyrics: $data['syncedLyrics'] ?? null,
        );
    }

    /** The song's lyrics from this result, as a fetch or an applied search result stores them. */
    public function toLyrics(Uuid $songId): Lyrics
    {
        return Lyrics::create(
            songId: $songId,
            lyrics: $this->plainLyrics ?? '',
            source: 'lrclib',
            sourceUrl: null,
            isInstrumental: $this->instrumental,
            syncedLyrics: $this->syncedLyrics,
            lrclibId: $this->id,
        );
    }
}
