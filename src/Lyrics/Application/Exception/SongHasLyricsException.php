<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Exception;

use App\Shared\Application\Exception\ConflictException;

/**
 * An apply targeted a song that has lyrics, which stay unchanged; HTTP answers 409 with the
 * reason `song_has_lyrics` and console commands exit with FAILURE.
 */
final class SongHasLyricsException extends ConflictException
{
    public const string REASON = 'song_has_lyrics';

    public function __construct()
    {
        parent::__construct('The song already has lyrics.', ['reason' => self::REASON]);
    }
}
