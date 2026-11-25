<?php

declare(strict_types=1);

namespace App\Notification\Application\DTO;

/** The push service's answer to one notification: its HTTP status, or none when it did not answer. */
final readonly class PushSendReport
{
    public function __construct(
        public bool $delivered,
        public ?int $status,
    ) {
    }
}
