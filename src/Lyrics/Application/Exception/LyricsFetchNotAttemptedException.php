<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Exception;

use App\Shared\Application\Exception\ConflictException;

/**
 * A lyrics fetch was not attempted because the song lacks what an LRCLIB lookup needs; HTTP
 * answers 409 and console commands exit with FAILURE. The error's `details.reason` names
 * the cause.
 */
final class LyricsFetchNotAttemptedException extends ConflictException
{
    /** The song has no primary artist credit. */
    public const string NO_ARTIST = 'song_has_no_artist';

    /** The song's length is missing or not positive. */
    public const string NO_LENGTH = 'song_has_no_length';

    private function __construct(string $message, string $reason)
    {
        parent::__construct($message, ['reason' => $reason]);
    }

    public static function noArtist(): self
    {
        return new self('The song has no artist, which LRCLIB needs to look up its lyrics.', self::NO_ARTIST);
    }

    public static function noLength(): self
    {
        return new self('The song has no length, which LRCLIB needs to look up its lyrics.', self::NO_LENGTH);
    }
}
