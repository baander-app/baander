<?php

declare(strict_types=1);

namespace App\Shared\Domain\Model;

enum CursorDirection: string
{
    case Next = 'next';
    case Prev = 'prev';
}
