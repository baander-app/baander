<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Messaging;

use App\Catalog\Application\Command\BatchExtractCoversCommand;
use App\Shared\Application\Messaging\MessagePayloadCodecInterface;

final readonly class CatalogMessagePayloadCodec implements MessagePayloadCodecInterface
{
    private const string BATCH_EXTRACT_COVERS = 'catalog.batch_extract_covers';

    public function types(): array
    {
        return [self::BATCH_EXTRACT_COVERS => BatchExtractCoversCommand::class];
    }

    public function fields(string $type): array
    {
        if ($type !== self::BATCH_EXTRACT_COVERS) {
            throw new \InvalidArgumentException('Unsupported message type.');
        }

        return [];
    }

    public function encode(object $message): array
    {
        if (!$message instanceof BatchExtractCoversCommand) {
            throw new \InvalidArgumentException('Unsupported message type.');
        }

        return [];
    }

    public function decode(string $type, array $payload): object
    {
        if ($type !== self::BATCH_EXTRACT_COVERS || $payload !== []) {
            throw new \InvalidArgumentException('Unsupported message type or payload.');
        }

        return new BatchExtractCoversCommand();
    }
}
