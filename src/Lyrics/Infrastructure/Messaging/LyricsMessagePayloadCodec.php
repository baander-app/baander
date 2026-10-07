<?php

declare(strict_types=1);

namespace App\Lyrics\Infrastructure\Messaging;

use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Shared\Application\Messaging\MessagePayloadCodecInterface;
use App\Shared\Domain\Model\Uuid;

final readonly class LyricsMessagePayloadCodec implements MessagePayloadCodecInterface
{
    private const array FIELDS = [
        'lyrics.fetch' => ['song_id'],
    ];

    public function types(): array
    {
        return [
            'lyrics.fetch' => FetchLyricsCommand::class,
        ];
    }

    public function fields(string $type): array
    {
        return self::FIELDS[$type] ?? throw new \InvalidArgumentException('Unsupported message type.');
    }

    public function encode(object $message): array
    {
        return match (true) {
            $message instanceof FetchLyricsCommand => [$message->getSongId()->toString()],
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }

    public function decode(string $type, array $p): object
    {
        return match ($type) {
            'lyrics.fetch' => new FetchLyricsCommand(Uuid::fromString($p['song_id'])),
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }
}
