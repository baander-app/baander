<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Messaging\JsonMessageCodec;
use Symfony\Component\Messenger\Envelope;

/** Job history and retry use the same versioned payload contract as transports. */
final readonly class JobMessageSerializer
{
    public function __construct(
        private JsonMessageCodec $codec,
        private int $maxPayloadSize = 1_048_576,
    ) {
    }

    public function serialize(Envelope $envelope): ?string
    {
        try {
            $data = $this->codec->encode($envelope->getMessage());
            return strlen($data) <= $this->maxPayloadSize ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function deserialize(string $data): ?object
    {
        try {
            if (strlen($data) > $this->maxPayloadSize) {
                return null;
            }
            return $this->codec->decode($data)->message;
        } catch (\Throwable) {
            return null;
        }
    }
}
