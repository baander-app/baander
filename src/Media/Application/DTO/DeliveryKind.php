<?php

declare(strict_types=1);

namespace App\Media\Application\DTO;

enum DeliveryKind: string
{
    case Original = 'original';
    case Cached = 'cached';
    case Copy = 'copy';
    case Encoded = 'encoded';
    case Incompatible = 'incompatible';
}
