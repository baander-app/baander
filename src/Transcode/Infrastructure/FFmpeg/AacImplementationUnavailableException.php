<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Domain\ValueObject\AacImplementation;

/** The selected AAC implementation is missing from the FFmpeg binary in use. */
final class AacImplementationUnavailableException extends \RuntimeException
{
    public function __construct(public readonly AacImplementation $implementation)
    {
        parent::__construct(sprintf('AAC implementation "%s" is not available in this FFmpeg build', $implementation->label()));
    }
}
