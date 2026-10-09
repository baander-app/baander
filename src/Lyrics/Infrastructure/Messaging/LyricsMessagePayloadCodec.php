<?php

declare(strict_types=1);

namespace App\Lyrics\Infrastructure\Messaging;

use App\Lyrics\Application\Command\BulkFetchLyricsCommand;
use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Shared\Application\Messaging\MessagePayloadCodecInterface;
use App\Shared\Domain\Model\Uuid;

final readonly class LyricsMessagePayloadCodec implements MessagePayloadCodecInterface
{
    private const array FIELDS = [
        'lyrics.fetch' => ['song_id', 'bulk_run_id'],
        'lyrics.bulk_fetch' => ['limit', 'delay_ms'],
    ];

    public function types(): array
    {
        return [
            'lyrics.fetch' => FetchLyricsCommand::class,
            'lyrics.bulk_fetch' => BulkFetchLyricsCommand::class,
        ];
    }

    public function fields(string $type): array
    {
        return self::FIELDS[$type] ?? throw new \InvalidArgumentException('Unsupported message type.');
    }

    public function encode(object $message): array
    {
        return match (true) {
            $message instanceof FetchLyricsCommand => [$message->getSongId()->toString(), $message->getBulkRunId()?->toString()],
            $message instanceof BulkFetchLyricsCommand => [$message->getLimit(), $message->getDelayMs()],
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }

    public function decode(string $type, array $p): object
    {
        return match ($type) {
            'lyrics.fetch' => new FetchLyricsCommand(
                Uuid::fromString($p['song_id']),
                $p['bulk_run_id'] === null ? null : Uuid::fromString($p['bulk_run_id']),
            ),
            'lyrics.bulk_fetch' => new BulkFetchLyricsCommand($p['limit'], $p['delay_ms']),
            default => throw new \InvalidArgumentException('Unsupported message type.'),
        };
    }
}
