<?php

declare(strict_types=1);

namespace Baander\Tooling\Development\Acceptance;

final class CommandFailure extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
