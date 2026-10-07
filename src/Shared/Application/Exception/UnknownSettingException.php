<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

use RuntimeException;

final class UnknownSettingException extends RuntimeException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct(sprintf('Unknown setting "%s".', $key));
    }
}
