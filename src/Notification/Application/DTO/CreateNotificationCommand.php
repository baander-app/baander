<?php

declare(strict_types=1);

namespace App\Notification\Application\DTO;

final readonly class CreateNotificationCommand
{
    /**
     * @param class-string $eventClass Fully-qualified class name of the domain event
     * @param array<string, mixed> $payload Event payload data
     */
    public function __construct(
        public string $eventClass,
        public array $payload,
        public string $eventName,
    ) {
    }
}
