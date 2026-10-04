<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Messaging;

use App\Media\Application\Command\PruneMissingImagesCommand;
use App\Shared\Application\Messaging\MessagePayloadCodecInterface;

final readonly class MediaMessagePayloadCodec implements MessagePayloadCodecInterface
{
    private const array FIELDS = [
        'media.prune_missing_images' => [],
    ];

    public function types(): array
    {
        return [
            'media.prune_missing_images' => PruneMissingImagesCommand::class,
        ];
    }

    public function fields(string $type): array
    {
        return self::FIELDS[$type] ?? throw new \InvalidArgumentException('Unsupported message type.');
    }

    public function encode(object $message): array
    {
        return match (true) {
            $message instanceof PruneMissingImagesCommand => [],
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }

    public function decode(string $type, array $p): object
    {
        return match ($type) {
            'media.prune_missing_images' => new PruneMissingImagesCommand(),
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }
}
