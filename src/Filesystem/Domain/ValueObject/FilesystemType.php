<?php

declare(strict_types=1);

namespace App\Filesystem\Domain\ValueObject;

enum FilesystemType: string
{
    case Local = 'local';
}
