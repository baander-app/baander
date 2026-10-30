<?php

declare(strict_types=1);

namespace Baander\Tooling\Process;

final class CommandFailure extends \RuntimeException
{
    public function __construct(string $message, int $status, public readonly ?ProcessResult $result = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }
}
