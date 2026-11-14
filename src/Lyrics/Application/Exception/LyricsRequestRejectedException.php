<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Exception;

use App\Shared\Application\Exception\ConflictException;

/**
 * LRCLIB refused a fetch, search or apply with a client error other than 404, 408 or 429;
 * HTTP answers 409 with the reason `lrclib_rejected` and console commands exit with FAILURE.
 * Sending the same request again gets the same answer.
 */
final class LyricsRequestRejectedException extends ConflictException
{
    public const string REASON = 'lrclib_rejected';

    public function __construct()
    {
        parent::__construct('LRCLIB rejected the request.', ['reason' => self::REASON]);
    }
}
