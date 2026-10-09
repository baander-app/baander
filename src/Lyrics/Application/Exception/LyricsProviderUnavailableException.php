<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Exception;

use App\Shared\Application\Exception\ServiceUnavailableException;

/** LRCLIB did not answer a fetch, search or apply; HTTP answers 503 and console commands fail. */
final class LyricsProviderUnavailableException extends ServiceUnavailableException
{
    public const string MESSAGE = 'The lyrics provider LRCLIB is unavailable. Try again later.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
