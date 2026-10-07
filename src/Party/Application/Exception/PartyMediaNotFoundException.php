<?php

declare(strict_types=1);

namespace App\Party\Application\Exception;

/**
 * The party's video or transcode job does not exist, or the host may not play it.
 *
 * One exception covers both cases so a response cannot reveal that an inaccessible video exists.
 */
final class PartyMediaNotFoundException extends \RuntimeException
{
    public static function video(): self
    {
        return new self('Video not found.');
    }

    public static function transcodeJob(): self
    {
        return new self('Transcode job not found.');
    }
}
