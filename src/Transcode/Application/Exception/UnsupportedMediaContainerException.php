<?php

declare(strict_types=1);

namespace App\Transcode\Application\Exception;

use UnexpectedValueException;

/** A library file whose content is not one of the containers of its kind, such as a manifest under a media file name. */
final class UnsupportedMediaContainerException extends UnexpectedValueException
{
    /** @param ?string $format the demuxer FFmpeg recognised, when it named one */
    public function __construct(public readonly ?string $format)
    {
        parent::__construct($format === null
            ? 'Not a supported media container.'
            : sprintf('Not a supported media container: %s.', $format));
    }
}
