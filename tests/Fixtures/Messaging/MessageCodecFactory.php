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
use App\Shared\Application\Messaging\MessagePayloadCodecInterface;
use App\Shared\Infrastructure\Messaging\JsonMessageCodec;
use App\Shared\Infrastructure\Messaging\OutboxMessagePayloadCodec;
use App\Shared\Infrastructure\Messenger\JsonTransportSerializer;
use App\Transcode\Infrastructure\Messaging\TimelinePreviewMessagePayloadCodec;
use App\Transcode\Infrastructure\Messaging\TranscodeMessagePayloadCodec;
use Symfony\Component\Messenger\Transport\Serialization\SigningSerializer;

/** Standalone transports use the same feature codecs as the application container. */
final class MessageCodecFactory
{
    public static function create(int $maxPayloadSize = 1_048_576): JsonMessageCodec
    {
        return new JsonMessageCodec(self::payloadCodecs(), $maxPayloadSize);
    }

    /**
     * Signs and verifies as the application's transports do: every type the codecs decode,
     * keyed by the application secret. A worker drops an envelope another key signed.
     *
     * @param list<MessagePayloadCodecInterface>|null $payloadCodecs
     */
    public static function signingSerializer(?array $payloadCodecs = null, ?string $secret = null): SigningSerializer
    {
        $payloadCodecs ??= self::payloadCodecs();
        $secret ??= getenv('APP_SECRET') ?: throw new \RuntimeException('Set APP_SECRET to sign messages as the application does.');
        $types = array_merge(...array_map(static fn (MessagePayloadCodecInterface $codec): array => array_values($codec::types()), $payloadCodecs));

        return new SigningSerializer(new JsonTransportSerializer(new JsonMessageCodec($payloadCodecs)), $secret, $types);
    }

    /** @return list<MessagePayloadCodecInterface> */
    private static function payloadCodecs(): array
    {
        return [
            new CatalogMessagePayloadCodec(),
            new LibraryMessagePayloadCodec(),
            new LyricsMessagePayloadCodec(),
            new MediaMessagePayloadCodec(),
            new MetadataMessagePayloadCodec(),
            new NotificationMessagePayloadCodec(),
            new RadioMessagePayloadCodec(),
            new \App\Recommendation\Infrastructure\Messaging\RecommendationMessagePayloadCodec(),
            new SchedulerMessagePayloadCodec(),
            new OutboxMessagePayloadCodec(),
            new TranscodeMessagePayloadCodec(),
            new TimelinePreviewMessagePayloadCodec(),
        ];
    }
}
