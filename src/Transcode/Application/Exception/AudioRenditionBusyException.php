<?php

declare(strict_types=1);

namespace App\Transcode\Application\Exception;

/**
 * The server already runs as many audio rendition encodes as it allows, so a
 * new one was not started. Nothing was dispatched; the listener may retry.
 */
final class AudioRenditionBusyException extends \RuntimeException
{
}
