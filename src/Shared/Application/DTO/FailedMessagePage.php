<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

final readonly class FailedMessagePage
{
    /** @param list<FailedMessage> $messages */
    public function __construct(
        public array $messages,
        public int $total,
    ) {
    }
}
