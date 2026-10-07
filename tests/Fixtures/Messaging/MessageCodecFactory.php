<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

use App\Catalog\Infrastructure\Messaging\CatalogMessagePayloadCodec;
use App\Library\Infrastructure\Messaging\LibraryMessagePayloadCodec;
use App\Lyrics\Infrastructure\Messaging\LyricsMessagePayloadCodec;
use App\Media\Infrastructure\Messaging\MediaMessagePayloadCodec;
use App\Metadata\Infrastructure\Messaging\MetadataMessagePayloadCodec;
use App\Notification\Infrastructure\Messaging\NotificationMessagePayloadCodec;
use App\Radio\Infrastructure\Messaging\RadioMessagePayloadCodec;
use App\Scheduler\Infrastructure\Messaging\SchedulerMessagePayloadCodec;
use App\Shared\Infrastructure\Messaging\JsonMessageCodec;
use App\Shared\Infrastructure\Messaging\OutboxMessagePayloadCodec;
use App\Transcode\Infrastructure\Messaging\TranscodeMessagePayloadCodec;

/** Standalone transports use the same feature codecs as the application container. */
final class MessageCodecFactory
{
    public static function create(int $maxPayloadSize = 1_048_576): JsonMessageCodec
    {
        return new JsonMessageCodec([
            new CatalogMessagePayloadCodec(),
            new LibraryMessagePayloadCodec(),
            new LyricsMessagePayloadCodec(),
            new MediaMessagePayloadCodec(),
            new MetadataMessagePayloadCodec(),
            new NotificationMessagePayloadCodec(),
            new RadioMessagePayloadCodec(),
            new SchedulerMessagePayloadCodec(),
            new OutboxMessagePayloadCodec(),
            new TranscodeMessagePayloadCodec(),
        ], $maxPayloadSize);
    }
}
