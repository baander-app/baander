<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Http;

use RuntimeException;

final class DrillFailure extends RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
