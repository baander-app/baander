<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class JobMessageSerializer
{
    public function __construct(
        private SerializerInterface $serializer,
        private readonly JsonEncoder $jsonEncoder,
        private int $maxPayloadSize = 1_048_576,
    ) {
    }

    /**
     * Serialize a message envelope to a JSON string with class metadata.
     *
     * The serialized format wraps the message properties with a __class key
     * so the original FQCN can be recovered during deserialization (e.g. for retry).
     *
     * Format: {"__class": "App\\...\\SomeCommand", "property1": "value1", ...}
     */
    public function serialize(Envelope $envelope): ?string
    {
        try {
            $message = $envelope->getMessage();
            $serialized = $this->serializer->serialize($message, 'json');

            if (strlen($serialized) > $this->maxPayloadSize) {
                return null;
            }

            // Wrap with class name for re-dispatch (retry support)
            $payload = $this->jsonEncoder->decode($serialized, 'json');

            return $this->jsonEncoder->encode([
                '__class' => $message::class,
                ...$payload,
            ], 'json');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Deserialize a JSON string (produced by serialize()) back to a message object.
     *
     * Expects the JSON to contain a "__class" key with the FQCN.
     */
    public function deserialize(string $data): ?object
    {
        try {
            $wrapper = $this->jsonEncoder->decode($data, 'json');

            $class = $wrapper['__class'] ?? null;
            if ($class === null || !class_exists($class)) {
                return null;
            }

            // Remove the __class metadata key to get the original payload
            unset($wrapper['__class']);

            return $this->serializer->deserialize(
                $this->jsonEncoder->encode($wrapper, 'json'),
                $class,
                'json',
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
