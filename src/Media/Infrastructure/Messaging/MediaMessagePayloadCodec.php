<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Messaging;

use App\Media\Application\Command\PruneMissingImagesCommand;
use App\Media\Application\Command\GenerateImageDerivativeCommand;
use App\Shared\Application\Messaging\MessagePayloadCodecInterface;

final readonly class MediaMessagePayloadCodec implements MessagePayloadCodecInterface
{
    private const array FIELDS = [
        'media.prune_missing_images' => [],
        'media.generate_image_derivative' => ['source_path', 'preset'],
    ];

    public static function types(): array
    {
        return [
            'media.prune_missing_images' => PruneMissingImagesCommand::class,
            'media.generate_image_derivative' => GenerateImageDerivativeCommand::class,
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
            $message instanceof GenerateImageDerivativeCommand => [$message->sourcePath, $message->preset],
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }

    public function decode(string $type, array $p): object
    {
        return match ($type) {
            'media.prune_missing_images' => new PruneMissingImagesCommand(),
            'media.generate_image_derivative' => new GenerateImageDerivativeCommand($p['source_path'], $p['preset']),
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }
}
