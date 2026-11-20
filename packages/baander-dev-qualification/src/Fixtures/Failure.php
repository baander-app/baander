<?php

declare(strict_types=1);

namespace Baander\DevQualification\Fixtures;

use RuntimeException;

final class Failure extends RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
