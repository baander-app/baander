<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

/**
 * The configured hardware encoder failed its startup test encode. Media control stops instead
 * of encoding in software, so the worker deployment does not become ready.
 */
final class HardwareEncoderUnavailable extends \RuntimeException
{
    public const string REASON = 'hardware_encoder_unavailable';

    public function __construct(public readonly string $encoder, public readonly string $device, public readonly string $diagnostic)
    {
        parent::__construct(sprintf(
            'The configured hardware encoder %s%s did not initialize%s',
            $encoder,
            $device === '' ? '' : ' on ' . $device,
            $diagnostic === '' ? '.' : ': ' . $diagnostic,
        ));
    }
}
