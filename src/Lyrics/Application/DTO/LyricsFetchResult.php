<?php

declare(strict_types=1);

namespace App\Lyrics\Application\DTO;

use App\Lyrics\Application\Exception\LyricsProviderUnavailableException;
use App\Lyrics\Domain\Model\Lyrics;

/**
 * What a lyrics fetch for one song came to: the song's lyrics, nothing found, or LRCLIB
 * unavailable.
 *
 * FetchLyricsHandler returns it instead of throwing for an outage, so a queued fetch ends
 * without a retry either way. The per-song API route and app:song:lyrics:fetch report an
 * outage through lyricsOrFail().
 */
final readonly class LyricsFetchResult
{
    private function __construct(
        public ?Lyrics $lyrics,
        private bool $providerUnavailable,
    ) {
    }

    /** The song's lyrics, stored earlier or just fetched. */
    public static function found(Lyrics $lyrics): self
    {
        return new self($lyrics, false);
    }

    /** Nothing was stored: LRCLIB has no lyrics, or the song lacks what a lookup needs. */
    public static function notFound(): self
    {
        return new self(null, false);
    }

    public static function providerUnavailable(): self
    {
        return new self(null, true);
    }

    public function isProviderUnavailable(): bool
    {
        return $this->providerUnavailable;
    }

    /**
     * @return Lyrics|null the lyrics, or null when nothing was found
     *
     * @throws LyricsProviderUnavailableException when LRCLIB was unavailable
     */
    public function lyricsOrFail(): ?Lyrics
    {
        if ($this->providerUnavailable) {
            throw new LyricsProviderUnavailableException();
        }

        return $this->lyrics;
    }
}
