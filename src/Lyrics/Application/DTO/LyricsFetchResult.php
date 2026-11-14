<?php

declare(strict_types=1);

namespace App\Lyrics\Application\DTO;

use App\Lyrics\Application\Exception\LyricsFetchNotAttemptedException;
use App\Lyrics\Application\Exception\LyricsProviderUnavailableException;
use App\Lyrics\Application\Exception\LyricsRequestRejectedException;
use App\Lyrics\Domain\Model\Lyrics;

/**
 * What a lyrics fetch for one song came to: the song's lyrics, no match, a fetch that could
 * not be attempted for a named cause, a request LRCLIB rejected, or LRCLIB unavailable.
 *
 * FetchLyricsHandler returns it instead of throwing, so a queued fetch ends without a retry
 * whatever the outcome, and its run can count the outcome. The per-song API route and
 * app:song:lyrics:fetch report every outcome but found and no match through lyricsOrFail().
 */
final readonly class LyricsFetchResult
{
    private function __construct(
        public LyricsFetchOutcome $outcome,
        public ?Lyrics $lyrics = null,
    ) {
    }

    /** The song's lyrics, stored earlier or just fetched. */
    public static function found(Lyrics $lyrics): self
    {
        return new self(LyricsFetchOutcome::Found, $lyrics);
    }

    /** Nothing was stored: LRCLIB has no lyrics for the song, or the song no longer exists. */
    public static function noMatch(): self
    {
        return new self(LyricsFetchOutcome::NoMatch);
    }

    public static function noArtist(): self
    {
        return new self(LyricsFetchOutcome::NoArtist);
    }

    public static function noLength(): self
    {
        return new self(LyricsFetchOutcome::NoLength);
    }

    public static function rejected(): self
    {
        return new self(LyricsFetchOutcome::Rejected);
    }

    public static function providerUnavailable(): self
    {
        return new self(LyricsFetchOutcome::Unavailable);
    }

    /**
     * @return Lyrics|null the lyrics, or null when LRCLIB has no match
     *
     * @throws LyricsFetchNotAttemptedException  when the song has no artist or no length
     * @throws LyricsRequestRejectedException    when LRCLIB rejected the request
     * @throws LyricsProviderUnavailableException when LRCLIB was unavailable
     */
    public function lyricsOrFail(): ?Lyrics
    {
        return match ($this->outcome) {
            LyricsFetchOutcome::Found, LyricsFetchOutcome::NoMatch => $this->lyrics,
            LyricsFetchOutcome::NoArtist => throw LyricsFetchNotAttemptedException::noArtist(),
            LyricsFetchOutcome::NoLength => throw LyricsFetchNotAttemptedException::noLength(),
            LyricsFetchOutcome::Rejected => throw new LyricsRequestRejectedException(),
            LyricsFetchOutcome::Unavailable => throw new LyricsProviderUnavailableException(),
        };
    }
}
