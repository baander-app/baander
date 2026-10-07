<?php

declare(strict_types=1);

namespace App\Transcode\Application\Exception;

/** An audio rendition could not be produced; no partial rendition is kept. */
final class AudioRenditionFailedException extends \RuntimeException
{
}
