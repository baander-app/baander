<?php

declare(strict_types=1);

namespace App\Transcode\Application\Exception;

use App\Shared\Application\Exception\ConflictException;

/**
 * A start would leave the user holding more transcode sessions than the server setting allows, and
 * too few of the user's other sessions have been silent long enough to give way. HTTP answers 409
 * with the reason and the limit in the error details.
 */
final class TranscodeSessionLimitException extends ConflictException
{
    public const string REASON = 'transcode_session_limit';

    public static function at(int $limit): self
    {
        return new self(
            sprintf('This account already holds the %d video sessions the server allows, and they are in use. Stop playback on another device and try again.', $limit),
            ['reason' => self::REASON, 'limit' => $limit],
        );
    }
}
