<?php

declare(strict_types=1);

namespace App\Party\Application\Exception;

/**
 * The transcode job named for a party encodes a different video than the party plays.
 */
final class TranscodeJobVideoMismatchException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The transcode job does not belong to the video.');
    }
}
