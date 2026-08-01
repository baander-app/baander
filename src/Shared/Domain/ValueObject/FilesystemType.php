<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

enum FilesystemType: string
{
    case Local = 'local';
}
