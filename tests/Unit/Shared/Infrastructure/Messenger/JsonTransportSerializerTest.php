<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Messaging\JsonMessageCodec;
use App\Shared\Infrastructure\Messenger\JobIdStamp;
use App\Shared\Infrastructure\Messenger\JsonTransportSerializer;
use App\Shared\Infrastructure\Messenger\Stamp\CorrelationIdStamp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

final class JsonTransportSerializerTest extends TestCase
{
    public function testRetryAndFailureMetadataSurviveWithoutSerializingObjects(): void
    {
        $serializer = new JsonTransportSerializer(new JsonMessageCodec());
        $command = new ExtractAlbumCoverCommand(Uuid::v4());
        $stamps = [
            new BusNameStamp('messenger.bus.default'),
            new CorrelationIdStamp('request-123'),
            new JobIdStamp(new PublicId()),
            new DelayStamp(1500),
            new RedeliveryStamp(2, new \DateTimeImmutable('2026-10-01T10:00:00.123+00:00')),
            new SentToFailureTransportStamp('async'),
            new ErrorDetailsStamp('RuntimeException', 503, 'Temporarily unavailable'),
        ];
        $wire = $serializer->encode(new Envelope($command, [...$stamps, new ReceivedStamp('async')]));
        self::assertSame('application/json', $wire['headers']['Content-Type']);
        self::assertStringNotContainsString('Symfony', $wire['body']);
        self::assertStringNotContainsString(ExtractAlbumCoverCommand::class, $wire['body']);
        $decoded = $serializer->decode($wire);
        self::assertEquals($command, $decoded->getMessage());
        self::assertNull($decoded->last(ReceivedStamp::class));
        foreach ($stamps as $stamp) {
            self::assertEquals($stamp, $decoded->last($stamp::class));
        }
    }

    public function testUnknownSendableStampIsRejectedRatherThanSilentlyLost(): void
    {
        $serializer = new JsonTransportSerializer(new JsonMessageCodec());
        $this->expectException(\InvalidArgumentException::class);
        $serializer->encode(new Envelope(new ExtractAlbumCoverCommand(Uuid::v4()), [new class implements StampInterface {}]));
    }

    public function testUntrustedMetadataCannotInstantiateClasses(): void
    {
        $codec = new JsonMessageCodec();
        $wire = $codec->encode(new ExtractAlbumCoverCommand(Uuid::v4()), ['stamps' => [['type' => 'stdClass', 'data' => []]]]);
        $this->expectException(MessageDecodingFailedException::class);
        (new JsonTransportSerializer($codec))->decode(['body' => $wire]);
    }

    public function testLegacyPhpEnvelopeIsRejected(): void
    {
        $this->expectException(MessageDecodingFailedException::class);
        (new JsonTransportSerializer(new JsonMessageCodec()))->decode(['body' => serialize(new Envelope(new \stdClass()))]);
    }

    public function testNegativeRetryCountIsRejected(): void
    {
        $codec = new JsonMessageCodec();
        $wire = $codec->encode(new ExtractAlbumCoverCommand(Uuid::v4()), ['stamps' => [['type' => 'retry', 'data' => ['count' => -1, 'at' => '2026-10-01T10:00:00.000+00:00']]]]);
        $this->expectException(MessageDecodingFailedException::class);
        (new JsonTransportSerializer($codec))->decode(['body' => $wire]);
    }
}
