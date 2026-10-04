<?php

declare(strict_types=1);

namespace App\Shared\Application\Messaging;

final class PayloadSchema
{
    /**
     * @param array<mixed> $payload
     * @param list<string> $fields
     */
    public static function requireFields(array $payload, array $fields): void
    {
        if (array_diff($fields, array_keys($payload)) !== [] || array_diff(array_keys($payload), $fields) !== []) {
            throw new \InvalidArgumentException('Message fields do not match its versioned schema.');
        }
    }
}
