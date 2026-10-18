<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** An opaque command identity; it grants no operating-system process authority. */
#[Exclude]
final readonly class NativeExecutionHandle
{
    public function __construct(public string $token, public int $attempt)
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1 || $attempt < 1) {
            throw new \InvalidArgumentException('Invalid native execution handle.');
        }
    }
}
