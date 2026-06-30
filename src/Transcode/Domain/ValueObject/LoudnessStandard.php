<?php

declare(strict_types=1);

namespace App\Transcode\Domain\ValueObject;

enum LoudnessStandard: string
{
    case EbuR128 = 'ebu_r128';
    case AtscA85 = 'atsc_a85';
    case Streaming = 'streaming';
    case Mobile = 'mobile';
    case Dialogue = 'dialogue';

    public function targetLufs(): float
    {
        return match ($this) {
            self::EbuR128 => -23.0,
            self::AtscA85 => -24.0,
            self::Streaming => -16.0,
            self::Mobile => -14.0,
            self::Dialogue => -20.0,
        };
    }
}
