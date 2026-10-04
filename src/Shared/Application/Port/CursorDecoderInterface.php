<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use App\Shared\Domain\Model\Cursor;

interface CursorDecoderInterface
{
    public function decode(string $cursor): ?Cursor;
}
