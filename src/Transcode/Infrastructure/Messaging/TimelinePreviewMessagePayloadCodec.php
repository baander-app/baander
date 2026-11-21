<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Messaging;

use App\Shared\Application\Messaging\MessagePayloadCodecInterface;
use App\Transcode\Application\Command\PrepareTimelinePreview;

final readonly class TimelinePreviewMessagePayloadCodec implements MessagePayloadCodecInterface
{
    public static function types(): array
    {
        return ['transcode.prepare_timeline_preview' => PrepareTimelinePreview::class];
    }

    public function fields(string $type): array
    {
        if ($type !== 'transcode.prepare_timeline_preview') {
            throw new \InvalidArgumentException('Unsupported message type.');
        }

        return ['path', 'identity', 'duration', 'origin', 'source_hash', 'video_stream_index'];
    }

    public function encode(object $message): array
    {
        if (!$message instanceof PrepareTimelinePreview) {
            throw new \InvalidArgumentException('Unsupported message type.');
        }

        return [
            $message->path,
            $message->identity,
            $message->duration,
            $message->origin,
            $message->sourceHash,
            $message->videoStreamIndex,
        ];
    }

    public function decode(string $type, array $p): object
    {
        $this->fields($type);

        return new PrepareTimelinePreview(
            $p['path'],
            $p['identity'],
            $p['duration'],
            $p['origin'],
            $p['source_hash'],
            $p['video_stream_index'],
        );
    }
}
