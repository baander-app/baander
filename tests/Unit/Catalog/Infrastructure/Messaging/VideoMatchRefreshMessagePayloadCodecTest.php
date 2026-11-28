<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Infrastructure\Messaging;

use App\Catalog\Application\Message\RefreshVideoMatchMessage;
use App\Catalog\Infrastructure\Messaging\VideoMatchRefreshMessagePayloadCodec;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class VideoMatchRefreshMessagePayloadCodecTest extends TestCase
{
    public function testRoundTripPreservesOperationIdentityAndRequestInstant(): void
    {
        $codec = new VideoMatchRefreshMessagePayloadCodec();
        $type = 'catalog.refresh_video_match';
        $message = new RefreshVideoMatchMessage(
            VideoKind::Series, Uuid::v7(), Uuid::v7(), new DateTimeImmutable('2026-11-28T04:15:00.123456+01:00'),
        );
        $payload = array_combine($codec->fields($type), $codec->encode($message));

        self::assertEquals($message, $codec->decode($type, $payload));
        self::assertSame('2026-11-28T04:15:00.123456+01:00', $payload['requestedAt']);
    }

    public function testMalformedRequestInstantIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new VideoMatchRefreshMessagePayloadCodec()->decode('catalog.refresh_video_match', [
            'kind' => 'tv',
            'titleId' => Uuid::v7()->toString(),
            'operationId' => Uuid::v7()->toString(),
            'requestedAt' => '2026-11-31T04:15:00.123456+01:00',
        ]);
    }

    public function testUnknownVideoKindIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new VideoMatchRefreshMessagePayloadCodec()->decode('catalog.refresh_video_match', [
            'kind' => 'unknown',
            'titleId' => Uuid::v7()->toString(),
            'operationId' => Uuid::v7()->toString(),
            'requestedAt' => '2026-11-28T04:15:00.123456+01:00',
        ]);
    }
}
