<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class NativeExecutionException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $uncertain = true)
    {
        parent::__construct($message);
    }
}
