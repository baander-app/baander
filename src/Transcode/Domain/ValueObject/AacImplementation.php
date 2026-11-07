<?php

declare(strict_types=1);

namespace App\Transcode\Domain\ValueObject;

/**
 * The codec implementation FFmpeg uses for AAC: the value of the AAC
 * implementation setting, mapped to the names of FFmpeg's encoder and
 * decoder. bAAC is the default; fdk-aac and FFmpeg's built-in codec are the
 * operator's fallbacks.
 */
enum AacImplementation: string
{
    case Baac = 'baac';
    case FdkAac = 'fdk_aac';
    case Ffmpeg = 'ffmpeg';

    public function encoderName(): string
    {
        return match ($this) {
            self::Baac => 'libbaac',
            self::FdkAac => 'libfdk_aac',
            self::Ffmpeg => 'aac',
        };
    }

    public function decoderName(): string
    {
        return $this->encoderName();
    }

    public function label(): string
    {
        return match ($this) {
            self::Baac => 'bAAC',
            self::FdkAac => 'Fraunhofer fdk-aac',
            self::Ffmpeg => 'FFmpeg built-in',
        };
    }
}
