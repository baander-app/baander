<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

use App\Shared\Application\Messaging\MessagePayloadCodecInterface;
use App\Shared\Application\Messaging\PayloadSchema;

/** Versioned JSON contract. No reflection, class names, PHP serialization, or framework serializer. */
final readonly class JsonMessageCodec
{
    /** @var array<string, MessagePayloadCodecInterface> */
    private array $codecs;

    /** @var array<class-string, string> */
    private array $types;

    /** @param iterable<MessagePayloadCodecInterface> $payloadCodecs */
    public function __construct(iterable $payloadCodecs, private int $maxPayloadSize = 1_048_576)
    {
        $codecs = [];
        $types = [];
        foreach ($payloadCodecs as $codec) {
            foreach ($codec->types() as $type => $class) {
                if ($type === '' || isset($codecs[$type]) || isset($types[$class])) {
                    throw new \LogicException('Duplicate or empty message payload registration.');
                }
                $codecs[$type] = $codec;
                $types[$class] = $type;
            }
        }
        $this->codecs = $codecs;
        $this->types = $types;
    }

    /** @param array<string, mixed> $metadata */
    public function encode(object $message, array $metadata = []): string
    {
        $type = $this->types[$message::class] ?? throw new \InvalidArgumentException('Unsupported message type.');
        $codec = $this->codecs[$type];
        $payload = $codec->encode($message);
        $this->validateJsonData($payload);
        $this->validateJsonData($metadata);
        $data = json_encode([
            'format' => 'baander.message', 'version' => 1, 'type' => $type,
            'payload' => (object) array_combine($codec->fields($type), $payload), 'metadata' => (object) $metadata,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION, 32);
        if (strlen($data) > $this->maxPayloadSize) {
            throw new \InvalidArgumentException('Message exceeds the payload limit.');
        }
        return $data;
    }

    public function decode(string $data): DecodedMessage
    {
        if (strlen($data) > $this->maxPayloadSize) {
            throw new \InvalidArgumentException('Message exceeds the payload limit.');
        }
        try {
            $document = json_decode($data, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($document)) {
                throw new \InvalidArgumentException('Message must be an object.');
            }
            PayloadSchema::requireFields($document, ['format', 'version', 'type', 'payload', 'metadata']);
            if ($document['format'] !== 'baander.message' || $document['version'] !== 1 ||
                !is_string($document['type']) || !isset($this->codecs[$document['type']]) ||
                !is_array($document['payload']) || !is_array($document['metadata'])) {
                throw new \InvalidArgumentException('Unsupported message format, version, or type.');
            }
            $type = $document['type'];
            $p = $document['payload'];
            $codec = $this->codecs[$type];
            PayloadSchema::requireFields($p, $codec->fields($type));
            $message = $codec->decode($type, $p);
            return new DecodedMessage($message, $document['metadata']);
        } catch (\Throwable $error) {
            throw new \InvalidArgumentException('Invalid message payload.', previous: $error);
        }
    }

    private function validateJsonData(mixed $value, int $depth = 0): void
    {
        if ($depth > 24 || is_object($value) || is_resource($value)) {
            throw new \InvalidArgumentException('Payloads must contain only bounded JSON data.');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->validateJsonData($item, $depth + 1);
            }
        }
    }
}
