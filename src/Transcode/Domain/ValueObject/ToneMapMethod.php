<?php

declare(strict_types=1);

namespace App\Transcode\Domain\ValueObject;

enum ToneMapMethod: string
{
    case Hable = 'hable';
    case Reinhard = 'reinhard';
    case BT2446 = 'bt2446a';
    case None = 'none';

    public function ffmpegParam(): string
    {
        return match ($this) {
            self::Hable => 'tonemap=tonemap=hable:desat=0',
            self::Reinhard => 'tonemap=tonemap=reinhard:desat=0',
            self::BT2446 => 'tonemap=tonemap=bt2446a:desat=0',
            self::None => '',
        };
    }
}
