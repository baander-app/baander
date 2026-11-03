<?php

declare(strict_types=1);

namespace App\Transcode\Application\Exception;

/**
 * Every audio slot stayed busy for the whole start wait, so no worker began the
 * admitted rendition. The work keeps its place in the queue; a retry of the same
 * request follows it instead of admitting it again.
 */
final class AudioRenditionBusyException extends \RuntimeException
{
    /** Most audio renditions finish within seconds, freeing a slot. */
    public const int RETRY_AFTER_SECONDS = 5;
}
