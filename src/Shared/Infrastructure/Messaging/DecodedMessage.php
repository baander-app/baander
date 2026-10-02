<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

final readonly class DecodedMessage
{
    /** @param array<string, mixed> $metadata */
    public function __construct(public object $message, public array $metadata = [])
    {
    }
}
