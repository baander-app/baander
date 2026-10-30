<?php

declare(strict_types=1);

namespace App\Shared\Application\LiveUpdates;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class LiveSubscription
{
    /** @param array<string, mixed> $message */
    public static function fromArray(array $message): self
    {
        if (count($message) !== 2 || ($message['type'] ?? null) !== 'live.subscribe' || ($message['version'] ?? null) !== 1) {
            throw new \InvalidArgumentException('Invalid live subscription.');
        }

        return new self();
    }
}
