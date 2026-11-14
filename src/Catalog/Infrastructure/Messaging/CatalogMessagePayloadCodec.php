<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Messaging;

use App\Catalog\Application\Command\BatchExtractCoversCommand;
use App\Catalog\Application\Message\DrainAlbumMatchesMessage;
use App\Shared\Application\Messaging\MessagePayloadCodecInterface;

final readonly class CatalogMessagePayloadCodec implements MessagePayloadCodecInterface
{
    private const string BATCH_EXTRACT_COVERS = 'catalog.batch_extract_covers';
    private const string DRAIN_ALBUM_MATCHES = 'catalog.drain_album_matches';

    private const array FIELDS = [
        self::BATCH_EXTRACT_COVERS => [],
        self::DRAIN_ALBUM_MATCHES => ['outages'],
    ];

    public static function types(): array
    {
        return [
            self::BATCH_EXTRACT_COVERS => BatchExtractCoversCommand::class,
            self::DRAIN_ALBUM_MATCHES => DrainAlbumMatchesMessage::class,
        ];
    }

    public function fields(string $type): array
    {
        return self::FIELDS[$type] ?? throw new \InvalidArgumentException('Unsupported message type.');
    }

    public function encode(object $message): array
    {
        return match (true) {
            $message instanceof BatchExtractCoversCommand => [],
            $message instanceof DrainAlbumMatchesMessage => [$message->outages],
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }

    public function decode(string $type, array $payload): object
    {
        return match (true) {
            $type === self::BATCH_EXTRACT_COVERS && $payload === [] => new BatchExtractCoversCommand(),
            $type === self::DRAIN_ALBUM_MATCHES && is_int($payload['outages'] ?? null) && $payload['outages'] >= 0 => new DrainAlbumMatchesMessage($payload['outages']),
            default => throw new \InvalidArgumentException('Unsupported message type or payload.'),
        };
    }
}
