<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

/** A message held by the failure transport, with the diagnostics its envelope carries. */
final readonly class FailedMessage
{
    public function __construct(
        public string $id,
        public string $messageClass,
        public ?string $originalTransport,
        public ?string $errorClass,
        public ?string $errorMessage,
        public ?\DateTimeImmutable $failedAt,
        /** Retries from the failure transport that have failed again. */
        public int $retryCount,
    ) {
    }
}
