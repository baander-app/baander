<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Messaging;

use App\Catalog\Application\Command\BatchExtractCoversCommand;
use App\Catalog\Application\Message\DrainAlbumMatchesMessage;
use App\Catalog\Application\Message\DrainVideoMatchesMessage;
use App\Shared\Application\Messaging\MessagePayloadCodecInterface;

final readonly class CatalogMessagePayloadCodec implements MessagePayloadCodecInterface
{
    private const string BATCH_EXTRACT_COVERS = 'catalog.batch_extract_covers';
    private const string DRAIN_ALBUM_MATCHES = 'catalog.drain_album_matches';
    private const string DRAIN_VIDEO_MATCHES = 'catalog.drain_video_matches';

    private const array FIELDS = [
        self::BATCH_EXTRACT_COVERS => [],
        self::DRAIN_ALBUM_MATCHES => ['chain', 'outages'],
        self::DRAIN_VIDEO_MATCHES => [],
    ];

    public static function types(): array
    {
        return [
            self::BATCH_EXTRACT_COVERS => BatchExtractCoversCommand::class,
            self::DRAIN_ALBUM_MATCHES => DrainAlbumMatchesMessage::class,
            self::DRAIN_VIDEO_MATCHES => DrainVideoMatchesMessage::class,
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
            $message instanceof DrainAlbumMatchesMessage => [$message->chain, $message->outages],
            $message instanceof DrainVideoMatchesMessage => [],
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }

    public function decode(string $type, array $payload): object
    {
        return match (true) {
            $type === self::BATCH_EXTRACT_COVERS && $payload === [] => new BatchExtractCoversCommand(),
            $type === self::DRAIN_ALBUM_MATCHES && self::isDrain($payload) => new DrainAlbumMatchesMessage($payload['chain'], $payload['outages']),
            $type === self::DRAIN_VIDEO_MATCHES && $payload === [] => new DrainVideoMatchesMessage(),
            default => throw new \InvalidArgumentException('Unsupported message type or payload.'),
        };
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @phpstan-assert-if-true array{chain: string|null, outages: int<0, max>} $payload
     */
    private static function isDrain(array $payload): bool
    {
        return array_key_exists('chain', $payload) && ($payload['chain'] === null || is_string($payload['chain']))
            && is_int($payload['outages'] ?? null) && $payload['outages'] >= 0;
    }
}
